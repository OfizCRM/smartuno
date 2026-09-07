<?php

namespace App\Support;

/**
 * Read access to config/romania.php.
 *
 * The only interesting part is the VAT lookup. Rates are stored as dated
 * entries rather than a single current value, so asking "what is the standard
 * rate" is always really asking "what was it on this date" — an invoice
 * reissued for July 2025 must still say 19%, not the 21% that took effect on
 * 2025-08-01. Callers that genuinely mean "today" just omit the date.
 */
final class Romania
{
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
