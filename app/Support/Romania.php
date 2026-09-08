<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Romanian-specific formatting and read access to config/romania.php.
 *
 * The only interesting part of the config side is the VAT lookup. Rates are stored as dated
 * entries rather than a single current value, so asking "what is the standard
 * rate" is always really asking "what was it on this date" — an invoice
 * reissued for July 2025 must still say 19%, not the 21% that took effect on
 * 2025-08-01. Callers that genuinely mean "today" just omit the date.
 */
final class Romania
{
    /** The zone a date is written in when the recipient has not chosen one of their own. */
    public const DEFAULT_TIMEZONE = 'Europe/Bucharest';

    /**
     * A date written the way a Romanian reads it: "4 septembrie 2026".
     *
     * The locale is pinned to 'ro' rather than left to app()->getLocale(). APP_LOCALE is
     * 'en' and the only thing that ever changes it is SetLocale, a web middleware — so a
     * date formatted from a Stripe webhook, a scheduled command or a queue worker would
     * come out as "Sep 4, 2026" in the middle of Romanian email copy. Every transactional
     * template is Romanian, so the date has to be too, whatever ran the code.
     *
     * $timezone is not optional in spirit. config('app.timezone') is 'UTC', so every
     * subscription column hydrates as a UTC Carbon, and an instant in the last hours of the
     * UTC day prints the day *before* the one the customer's own screen shows for the same
     * column — Stripe writes renews_at from a period end, so that is a couple of hours out
     * of every twenty-four. Callers pass the recipient's own timezone; a recipient who
     * never set one gets Bucharest, which for a Romanian-only product is right far more
     * often than UTC is.
     *
     * $fallback is what a missing date renders as. It exists so a null never reaches the
     * recipient as the literal string "null".
     */
    public static function longDate(mixed $date, ?string $timezone = null, string $fallback = '—'): string
    {
        $zone = is_string($timezone) && trim($timezone) !== '' ? trim($timezone) : self::DEFAULT_TIMEZONE;

        try {
            if ($date instanceof DateTimeInterface) {
                $instance = CarbonImmutable::instance($date);
            } elseif (is_string($date) && trim($date) !== '') {
                $instance = CarbonImmutable::parse($date);
            } else {
                return $fallback;
            }

            try {
                $instance = $instance->setTimezone($zone);
            } catch (\Throwable) {
                // A timezone name a stale profile still carries loses the recipient a few
                // hours, not the whole date — printing the fallback here would put an em
                // dash where they expect a day.
                $instance = $instance->setTimezone(self::DEFAULT_TIMEZONE);
            }

            return $instance->locale('ro')->isoFormat('D MMMM YYYY');
        } catch (\Throwable) {
            return $fallback;
        }
    }

    /**
     * The name to put after "Salut," — the first word of a full name.
     *
     * Every greeting in the transactional templates is informal, and in Romanian that
     * register does not take a surname: "Salut, Ana Popescu," is the signature of a badly
     * merged mail merge, and it is the first line the recipient reads. An empty name stays
     * empty; the templates that identify a customer *to an admin* rather than greet them
     * keep passing the full name, so this is applied per call site, not inside MailService.
     */
    public static function greetingName(mixed $name): string
    {
        if (! is_string($name)) {
            return '';
        }

        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        if ($name === '') {
            return '';
        }

        $first = strstr($name, ' ', true);

        return $first === false ? $name : $first;
    }

    /** The rate that applies to ordinary goods and services on a given date. */
    public static function standardVatRate(?string $onDate = null): float
    {
        $entry = self::vatRateEntryFor($onDate);

        return isset($entry['standard']) ? (float) $entry['standard'] : 0.0;
    }

    /**
     * The reduced rates in force on a given date. Since 2025-08-01 there is only
     * one (11%); before that there were two (9% and 5%), which is why this
     * returns a list rather than a single value.
     *
     * @return array<int, float>
     */
    public static function reducedVatRates(?string $onDate = null): array
    {
        $entry = self::vatRateEntryFor($onDate);

        if (! isset($entry['reduced']) || ! is_array($entry['reduced'])) {
            return [];
        }

        return array_values(array_map(static fn ($rate): float => (float) $rate, $entry['reduced']));
    }

    /**
     * The canonical form of a Romanian CUI: the bare digits, no RO prefix.
     *
     * ValidCui accepts "14399840", "RO14399840", "RO 14399840" and "0014399840"
     * as the same company, so storing whatever was typed puts one firm into the
     * indexed cui column under four different values and defeats the lookup the
     * index exists for. Both writers — the tenant's own company form and the
     * admin profile form — normalise through here, or the column is only
     * comparable when the same person happened to save it twice.
     */
    public static function canonicalCui(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $cui = mb_strtoupper(trim((string) $value));
        $cui = (string) preg_replace('/^RO/', '', $cui);
        $cui = (string) preg_replace('/[\s.\-]+/', '', $cui);

        // The control digit is computed against a body left-padded to nine
        // digits, so leading zeros carry no meaning. Guarded because a value of
        // all zeros would otherwise canonicalise to the empty string.
        $unpadded = ltrim($cui, '0');
        if ($unpadded !== '') {
            $cui = $unpadded;
        }

        return $cui === '' ? null : $cui;
    }

    /**
     * The structured address flattened into the single line clients.address
     * holds for the admin list and the CSV export, with the county written out
     * in full ("Cluj") rather than as its code.
     *
     * One implementation for both writers on purpose: while the client form and
     * the admin form composed it differently, the stored value flip-flopped
     * every time the other side saved — visibly so for every county-seat city
     * whose name equals its county.
     *
     * @param  array<string, mixed>  $data  keyed address_street/city/county/postcode
     */
    public static function composeAddress(array $data): ?string
    {
        $city = is_string($data['address_city'] ?? null) ? trim($data['address_city']) : '';

        $code = $data['address_county'] ?? null;
        $county = is_string($code) && trim($code) !== ''
            ? (self::counties()[$code] ?? $code)
            : null;

        // București and Iași are their own county; "Iași, Iași" reads like a bug
        // to the person whose invoice it lands on.
        if ($county !== null && $city !== '' && mb_strtolower($city) === mb_strtolower($county)) {
            $county = null;
        }

        $parts = [];
        foreach ([$data['address_street'] ?? null, $city, $county, $data['address_postcode'] ?? null] as $part) {
            if (is_string($part) && trim($part) !== '') {
                $parts[] = trim($part);
            }
        }

        return $parts === [] ? null : implode(', ', $parts);
    }

    /** @return array<string, string> county code => name */
    public static function counties(): array
    {
        $counties = config('romania.counties');

        return is_array($counties) ? $counties : [];
    }

    public static function isValidCounty(string $code): bool
    {
        return array_key_exists(strtoupper(trim($code)), self::counties());
    }

    /** @return array<int, string> */
    public static function vatStatuses(): array
    {
        $statuses = config('romania.vat_statuses');

        return is_array($statuses) ? array_values($statuses) : [];
    }

    /** @return array<int, string> */
    public static function companySizes(): array
    {
        $sizes = config('romania.company_sizes');

        return is_array($sizes) ? array_values($sizes) : [];
    }

    /** @return array<int, string> */
    public static function industries(): array
    {
        $industries = config('romania.industries');

        return is_array($industries) ? array_values($industries) : [];
    }

    /**
     * First entry whose `from` is on or before $onDate. The config list is
     * ordered newest-first, but we sort defensively rather than trusting that —
     * a rate entry appended to the bottom instead of the top would otherwise
     * silently bill every customer at a repealed rate.
     *
     * @return array<string, mixed>
     */
    private static function vatRateEntryFor(?string $onDate): array
    {
        $date = $onDate ?? date('Y-m-d');

        $rates = config('romania.vat_rates');
        if (! is_array($rates)) {
            return [];
        }

        $applicable = array_filter(
            $rates,
            static fn ($entry): bool => is_array($entry)
                && isset($entry['from'])
                && is_string($entry['from'])
                && $entry['from'] <= $date
        );

        usort($applicable, static fn (array $a, array $b): int => strcmp((string) $b['from'], (string) $a['from']));

        return $applicable[0] ?? [];
    }
}
