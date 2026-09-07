<?php

namespace App\Modules\AI\Services;

use App\Models\ClientBusinessHour;
use App\Models\Workspace;
use App\Support\Romania;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The tenant's own company facts, rendered as a block for the chatbot prompt.
 *
 * The company settings form promises this out loud — "agentul o folosește când
 * clientul întreabă cu ce vă ocupați", "agentul spune clientului dacă livrăm la
 * el" — and until now nothing carried any of it into the prompt.
 *
 * Two things are worth knowing before editing:
 *
 * 1. The AI module is scoped by workspace_id and never by client_id, but the
 *    profile hangs off the client, which owns many workspaces. Hence the hop
 *    workspace -> client -> profile, eager-loaded in one go.
 *
 * 2. The "are we open right now?" line is computed here, in PHP, on purpose.
 *    Asked to work it out from a list of intervals, a model will confidently
 *    answer that a clinic open 09:00-13:00 and 15:00-19:00 is open at 14:00.
 *    We state the answer instead of the arithmetic.
 *
 * Labels and facts are Romanian, because the tenant typed them in Romanian and
 * they are quoted back verbatim. The framing and the closing instruction are
 * English, matching ChatbotRunner's order-summary precedent and the default
 * system prompt — the reply language is driven by the customer's own message.
 */
class CompanyProfileContext
{
    private const DEFAULT_TIMEZONE = 'Europe/Bucharest';

    /**
     * Romanian labels for the config('romania.industries') slugs.
     *
     * Deliberately not __(): this block is always Romanian regardless of the
     * app locale, which for an API request is whatever the caller asked for.
     * Wording tracks settings.company.industry_* in resources/js/locales/ro.json.
     */
    private const INDUSTRY_LABELS = [
        'ecommerce' => 'Magazin online',
        'retail' => 'Magazin sau comerț',
        'dental_clinic' => 'Clinică stomatologică',
        'medical_clinic' => 'Clinică medicală',
        'beauty_salon' => 'Salon de înfrumusețare',
        'real_estate' => 'Agenție imobiliară',
        'car_dealer' => 'Dealer auto',
        'restaurant' => 'Restaurant sau cafenea',
        'hotel' => 'Hotel sau pensiune',
        'plumbing_hvac' => 'Instalații sanitare și termice',
        'electrical' => 'Instalații electrice',
        'furniture_fitting' => 'Mobilier și amenajări',
        'construction' => 'Construcții',
        'professional_services' => 'Servicii profesionale',
        'education' => 'Educație și cursuri',
        'auto_service' => 'Service auto',
        'other' => 'Altul',
    ];

    /** @var array<int, string> ISO-8601 weekday (1=Monday) => Romanian name */
    private const DAY_NAMES = [
        1 => 'luni',
        2 => 'marți',
        3 => 'miercuri',
        4 => 'joi',
        5 => 'vineri',
        6 => 'sâmbătă',
        7 => 'duminică',
    ];

    /**
     * The company block for a workspace's tenant, or null when there is no
     * profile row or every field on it is empty — an unconfigured tenant gets
     * no block at all rather than a page of blank labels.
     */
    public function forWorkspace(int $workspaceId): ?string
    {
        $workspace = Workspace::with(['client.profile', 'client.businessHours'])->find($workspaceId);
        $client = $workspace?->client;

        if (! $client) {
            return null;
        }

        $profile = $client->profile;
        if (! $profile) {
            return null;
        }

        // The client name is held back deliberately. clients.name is NOT NULL, so
        // pushing it first made the "nothing is configured" guard below
        // unreachable: a tenant who opened the form and pressed Save without
        // typing anything got an all-null profile row, a block containing only
        // their own name, and the refusal instruction that comes with it.
        $facts = [];

        $this->push($facts, 'Denumire legală', $profile->legal_name);
        $this->push($facts, 'Descriere', $profile->short_description);
        $this->push($facts, 'Domeniu de activitate', $this->industryLabel($profile->industry, $profile->industry_other));

        $this->push($facts, 'Adresă', Romania::composeAddress([
            'address_street' => $profile->address_street,
            'address_city' => $profile->address_city,
            'address_county' => $profile->address_county,
            'address_postcode' => $profile->address_postcode,
        ]));

        $this->push($facts, 'Telefon', $client->phone);
        $this->push($facts, 'Telefon mobil', $profile->mobile_phone);
        $this->push($facts, 'Email', $client->email);
        $this->push($facts, 'Website', $profile->website);
        $this->push($facts, 'TVA', $this->vatLine($profile->vat_status, $profile->vat_rate));

        foreach ($this->hoursLines($client->businessHours, $profile->timezone) as $line) {
            $facts[] = $line;
        }

        $this->push($facts, 'Zone de livrare', $profile->delivery_zones);
        $this->push($facts, 'Timp de livrare', $profile->delivery_time);
        $this->push($facts, 'Magazin online', $profile->online_shop_url);
        $this->push($facts, 'Locație pe hartă', $profile->google_maps_url);
        $this->push($facts, 'Facebook', $profile->facebook_url);
        $this->push($facts, 'Instagram', $profile->instagram_url);

        if ($facts === []) {
            return null;
        }

        $lines = [];
        $this->push($lines, 'Nume comercial', $client->name);
        $lines = array_merge($lines, $facts);

        // Scoped to the company's own details on purpose. Worded any wider, this
        // paragraph is the last thing in the prompt and overrides the knowledge
        // base and the order summary ChatbotRunner injects around it — the bot
        // then refuses a price that is sitting in its own retrieved context.
        // "We will come back to you" and not "a colleague": a good share of our
        // tenants are one person with a van.
        $lines[] = '';
        $lines[] = 'The details above are the only confirmed information about the business '
            .'itself — its name, address, contact details, opening hours and delivery areas. '
            .'Never state an address, an opening time or a delivery area that is not listed '
            .'there, and never guess or invent one. For anything else — prices, products, '
            .'services, order status — use only the other information given to you in this '
            .'prompt; if it is not there, say you do not have that information and that we will '
            .'come back with an answer. Bring up the legal name or the VAT status only if the '
            .'customer asks about invoicing.';

        return implode("\n", $lines);
    }

    /**
     * The opening-hours section: one line per configured day, then the
     * precomputed open/closed verdict. Empty when no hours exist at all —
     * "nobody filled this in" must never be rendered as "closed".
     *
     * @param  Collection<int, ClientBusinessHour>  $hours
     * @return array<int, string>
     */
    private function hoursLines(Collection $hours, ?string $timezone): array
    {
        if ($hours->isEmpty()) {
            return [];
        }

        $byDay = $this->intervalsByDay($hours);
        if ($byDay === []) {
            return [];
        }

        $lines = ['Program de lucru:'];

        foreach (self::DAY_NAMES as $iso => $name) {
            if (! array_key_exists($iso, $byDay)) {
                // No row for this day: not configured, which is not the same as
                // closed, so we say nothing rather than guess either way.
                continue;
            }

            $intervals = $byDay[$iso];
            $lines[] = $intervals === []
                ? '- '.$this->ucfirstRo($name).': închis'
                : '- '.$this->ucfirstRo($name).': '.implode(', ', array_map(
                    static fn (array $i): string => $i['opens'].'-'.$i['closes'],
                    $intervals
                ));
        }

        $lines[] = $this->nowLine($byDay, $timezone);

        return $lines;
    }

    /**
     * The whole point of the exercise: the current local time and a flat
     * OPEN/CLOSED answer, so the model never has to compare two clock times.
     *
     * @param  array<int, array<int, array{start: int, end: int, opens: string, closes: string}>>  $byDay
     */
    private function nowLine(array $byDay, ?string $timezone): string
    {
        $tz = $this->resolveTimezone($timezone);
        $now = CarbonImmutable::now($tz);
        $todayIso = $now->dayOfWeekIso;
        $nowMinutes = $now->hour * 60 + $now->minute;

        $prefix = sprintf(
            'Acum, ora locală a firmei (%s), este %s, %s, %s. ',
            $tz,
            self::DAY_NAMES[$todayIso],
            $now->format('d.m.Y'),
            $now->format('H:i'),
        );

        $openUntil = $this->openInterval($byDay, $todayIso, $nowMinutes);
        if ($openUntil !== null) {
            return $prefix.'Firma este DESCHISĂ în acest moment, până la '.$openUntil['closes'].'.';
        }

        // No row for today at all. The form submits every weekday blank by
        // default, so a tenant who saved without filling the hours in has rows
        // for Saturday and Sunday only — and answering "închis" off that told a
        // customer on a Tuesday morning that the firm was shut. hoursLines()
        // already keeps "not configured" apart from "closed" per day; this is
        // the line that used to throw the distinction away. Checked after the
        // open test, so an interval running past midnight from yesterday still
        // wins.
        if (! array_key_exists($todayIso, $byDay)) {
            return $prefix.'Programul pentru ziua de azi nu este completat în setările firmei. '
                .'Nu confirma clientului dacă firma este deschisă sau închisă acum — spune că '
                .'verifici și revii cu un răspuns.';
        }

        $next = $this->nextOpening($byDay, $todayIso, $nowMinutes);
        if ($next === null) {
            // Today is on the list as closed and no other day carries an
            // interval: a half-filled form, not a business that never opens.
            return $prefix.'Firma este ÎNCHISĂ astăzi. Programul pentru celelalte zile nu este '
                .'completat în setările firmei, așa că nu îi spune clientului când se redeschide '
                .'— spune că verifici și revii cu un răspuns.';
        }

        return $prefix.'Firma este ÎNCHISĂ în acest moment. Se deschide '.$next.'.';
    }

    /**
     * The interval covering "now", if any. An interval whose closing time is at
     * or before its opening time runs past midnight (a restaurant open until
     * 02:00), so it is also checked against the previous day.
     *
     * @param  array<int, array<int, array{start: int, end: int, opens: string, closes: string}>>  $byDay
     * @return array{start: int, end: int, opens: string, closes: string}|null
     */
    private function openInterval(array $byDay, int $todayIso, int $nowMinutes): ?array
    {
        foreach ($byDay[$todayIso] ?? [] as $interval) {
            $end = $interval['end'] > $interval['start'] ? $interval['end'] : $interval['end'] + 1440;
            if ($nowMinutes >= $interval['start'] && $nowMinutes < $end) {
                return $interval;
            }
        }

        $yesterdayIso = $todayIso === 1 ? 7 : $todayIso - 1;
        foreach ($byDay[$yesterdayIso] ?? [] as $interval) {
            if ($interval['end'] <= $interval['start'] && $nowMinutes < $interval['end']) {
                return $interval;
            }
        }

        return null;
    }

    /**
     * When the business next opens, phrased for a human. Walks up to seven days
     * forward so a Sunday evening lands on Monday, and a week of closed days
     * wraps round to the same weekday rather than reporting nothing.
     *
     * @param  array<int, array<int, array{start: int, end: int, opens: string, closes: string}>>  $byDay
     */
    private function nextOpening(array $byDay, int $todayIso, int $nowMinutes): ?string
    {
        for ($offset = 0; $offset <= 7; $offset++) {
            $iso = (($todayIso - 1 + $offset) % 7) + 1;

            foreach ($byDay[$iso] ?? [] as $interval) {
                if ($offset === 0 && $interval['start'] <= $nowMinutes) {
                    continue;
                }

                return match (true) {
                    $offset === 0 => 'astăzi la '.$interval['opens'],
                    $offset === 1 => 'mâine, '.self::DAY_NAMES[$iso].', la '.$interval['opens'],
                    $offset === 7 => self::DAY_NAMES[$iso].' săptămâna viitoare, la '.$interval['opens'],
                    default => self::DAY_NAMES[$iso].', la '.$interval['opens'],
                };
            }
        }

        return null;
    }

    /**
     * Intervals keyed by ISO weekday, each day's list sorted by opening time. A
     * day present with an empty list is explicitly closed; a day absent from the
     * array was never configured.
     *
     * @param  Collection<int, ClientBusinessHour>  $hours
     * @return array<int, array<int, array{start: int, end: int, opens: string, closes: string}>>
     */
    private function intervalsByDay(Collection $hours): array
    {
        $byDay = [];

        foreach ($hours as $hour) {
            $iso = (int) $hour->day_of_week;
            if ($iso < 1 || $iso > 7) {
                continue;
            }

            $opens = $this->minutes($hour->opens_at);
            $closes = $this->minutes($hour->closes_at);

            // Registering the day is what makes it print as "închis", so only
            // an explicitly closed row may do it. A row with no times and
            // is_closed unset says nothing at all — the columns are nullable,
            // and an import or a future writer can produce one.
            if ($hour->is_closed) {
                $byDay[$iso] ??= [];

                continue;
            }

            if ($opens === null || $closes === null) {
                continue;
            }

            $byDay[$iso] ??= [];
            $byDay[$iso][] = [
                'start' => $opens,
                'end' => $closes,
                'opens' => $this->clock($opens),
                'closes' => $this->clock($closes),
            ];
        }

        foreach ($byDay as $iso => $intervals) {
            usort($intervals, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
            $byDay[$iso] = $intervals;
        }

        return $byDay;
    }

    /** Minutes since midnight from a `time` column, which MySQL hands back as "HH:MM:SS". */
    private function minutes(mixed $value): ?int
    {
        if ($value instanceof \DateTimeInterface) {
            return (int) $value->format('H') * 60 + (int) $value->format('i');
        }

        if (! is_string($value) || ! preg_match('/(\d{1,2}):(\d{2})/', $value, $m)) {
            return null;
        }

        $hour = (int) $m[1];
        $minute = (int) $m[2];

        return $hour > 23 || $minute > 59 ? null : $hour * 60 + $minute;
    }

    private function clock(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    private function resolveTimezone(?string $timezone): string
    {
        if (! is_string($timezone) || trim($timezone) === '') {
            return self::DEFAULT_TIMEZONE;
        }

        try {
            new \DateTimeZone(trim($timezone));
        } catch (\Throwable) {
            return self::DEFAULT_TIMEZONE;
        }

        return trim($timezone);
    }

    /** The commercially relevant half of the VAT regime: whether prices carry VAT. */
    private function vatLine(?string $status, mixed $rate): ?string
    {
        $line = match ($status) {
            'none' => 'firma nu este plătitoare de TVA, prețurile nu conțin TVA',
            'standard' => 'firma este plătitoare de TVA',
            'on_collection' => 'firma este plătitoare de TVA, cu TVA la încasare',
            default => null,
        };

        if ($line === null) {
            return null;
        }

        // Only the stored rate is quoted. Deriving one from the current
        // legislation would state 21% for a tenant selling 11% goods.
        if ($status !== 'none' && is_numeric($rate)) {
            $line .= sprintf(' (cotă %s%%)', rtrim(rtrim(number_format((float) $rate, 2, '.', ''), '0'), '.'));
        }

        return $line;
    }

    private function industryLabel(?string $slug, ?string $other): ?string
    {
        $slug = is_string($slug) ? trim($slug) : '';
        $other = is_string($other) ? trim($other) : '';

        if ($slug === 'other' || ($slug === '' && $other !== '')) {
            return $other !== '' ? $other : self::INDUSTRY_LABELS['other'];
        }

        return $slug === '' ? null : (self::INDUSTRY_LABELS[$slug] ?? $slug);
    }

    /**
     * Day names are stored lower-case because they read better mid-sentence in
     * the "acum este luni" line; only the hours list wants them capitalised.
     */
    private function ucfirstRo(string $value): string
    {
        return mb_strtoupper(mb_substr($value, 0, 1)).mb_substr($value, 1);
    }

    /**
     * Append "Label: value", skipping anything blank — an empty field is absent
     * from the block, never rendered as an empty label the model can misread.
     *
     * @param  array<int, string>  $lines
     */
    private function push(array &$lines, string $label, mixed $value): void
    {
        if (! is_string($value)) {
            return;
        }

        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        if ($value === '') {
            return;
        }

        $lines[] = $label.': '.$value;
    }
}
