<?php

use App\Models\Template;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Second pass over the Romanian transactional emails: the three subscription mails that
 * told the reader to go to Facturare without giving them anything to click now carry a
 * button, and the rows whose `description` documented a date or an enum as arriving in
 * English are corrected now that the call sites translate both.
 *
 * Why this cannot just call EmailTemplateSeeder::sync() the way 000003 did: sync() decides
 * whether a row is still ours by comparing it to the ENGLISH factory text, and after 000003
 * no row matches that any more, so it would fall through to refreshing the documentation
 * keys and leave the copy alone. The ownership test has to be made against the previous
 * ROMANIAN text instead, which is what previous() holds — verbatim, exactly as 000003 wrote
 * it. The NEW text is not duplicated here: it is read from EmailTemplateSeeder::templates(),
 * so a fresh install and an upgraded one cannot drift.
 */
return new class extends Migration
{
    /**
     * Rows an admin has edited since 000003 keep their own words, exactly as sync() would
     * leave them: only `description` and `placeholders` are refreshed, because those are
     * documentation for /admin/email-system rather than anything the recipient reads.
     */
    public function up(): void
    {
        foreach (self::previous() as $slug => $previous) {
            $existing = Template::where('slug', $slug)->where('type', 'email')->first();

            // A row that does not exist is not this migration's problem: the seeder
            // creates it from the current definition, which is already the new copy.
            if (! $existing) {
                continue;
            }

            $definition = $this->definitionFor($slug);

            if (EmailTemplateSeeder::holdsText($existing, $previous)) {
                $existing->update([
                    'name' => $definition['name'],
                    'subject' => $definition['subject'],
                    'content' => $definition['content'],
                    'meta' => $definition['meta'],
                ]);

                continue;
            }

            $existing->update(['meta' => EmailTemplateSeeder::documentationMeta($existing, $definition['meta'])]);
        }
    }

    /**
     * Put back the copy 000003 left, on the same terms up() applied.
     *
     * "Still ours" is asked against every text this project has shipped for the row, not
     * just the one up() wrote: a later migration rewrites some of these rows, and by the
     * time this runs its down() has already put ITS predecessor back, which is a different
     * string again. Matching the text we are restoring to is simply a no-op, so the wider
     * list costs nothing and stops a row being mistaken for an admin's own words.
     */
    public function down(): void
    {
        $revisions = EmailTemplateSeeder::shippedRevisions();

        foreach (self::previous() as $slug => $previous) {
            $existing = Template::where('slug', $slug)->where('type', 'email')->first();

            if (! $existing) {
                continue;
            }

            $definition = $this->definitionFor($slug);

            $ours = array_merge([[
                'subject' => $definition['subject'],
                'content' => $definition['content'],
                'meta' => $definition['meta'],
            ]], $revisions[$slug] ?? []);

            $attributes = ['meta' => $this->rolledBackMeta($existing, $ours, $previous['meta'])];

            foreach ($ours as $version) {
                if (! EmailTemplateSeeder::holdsText($existing, $version)) {
                    continue;
                }

                $attributes['name'] = $previous['name'];
                $attributes['subject'] = $previous['subject'];
                $attributes['content'] = $previous['content'];

                break;
            }

            $existing->update($attributes);
        }
    }

    /**
     * The meta to put back on one row: the whole thing while the whole thing is still ours,
     * otherwise only the two documentation keys, and only while they still hold something
     * we wrote.
     *
     * @param  list<array{subject: string, content: string, meta: array<string, mixed>}>  $ours  every meta this project has shipped for the row
     * @param  array<string, mixed>  $previous  the meta 000003 left
     * @return array<string, mixed>
     */
    private function rolledBackMeta(Template $existing, array $ours, array $previous): array
    {
        $stored = $existing->meta;

        if (is_string($stored)) {
            $stored = json_decode($stored, true);
        }

        $stored = is_array($stored) ? $stored : [];

        foreach ($ours as $version) {
            if ($stored == $version['meta']) {
                return $previous;
            }
        }

        foreach (['description', 'placeholders'] as $key) {
            if (! array_key_exists($key, $stored)) {
                continue;
            }

            foreach ($ours as $version) {
                if ($stored[$key] == ($version['meta'][$key] ?? null)) {
                    $stored[$key] = $previous[$key] ?? null;

                    break;
                }
            }
        }

        return $stored;
    }

    /**
     * The current definition for one slug, from the single source of truth.
     *
     * @return array{name: string, subject: string, content: string, meta: array<string, mixed>}
     */
    private function definitionFor(string $slug): array
    {
        foreach (EmailTemplateSeeder::templates() as $definition) {
            if ($definition['slug'] === $slug) {
                return [
                    'name' => $definition['name'],
                    'subject' => $definition['subject'],
                    'content' => $definition['content'],
                    'meta' => $definition['meta'],
                ];
            }
        }

        // A slug listed here but missing from the seeder is a mistake in this file, and
        // silently skipping it would leave the install half-migrated with no trace.
        throw new RuntimeException("EmailTemplateSeeder has no definition for the '{$slug}' template.");
    }

    /**
     * The five rows this migration touches, holding the exact text 2026_09_07_000003 left
     * on them. This is the ownership test, so it has to be a verbatim literal: the moment
     * one character here stops matching what 000003 wrote, up() decides every untouched row
     * belongs to the admin and quietly changes nothing.
     *
     * @return array<string, array{name: string, subject: string, content: string, meta: array<string, mixed>}>
     */
    private static function previous(): array
    {
        return [
            'subscription_started' => [
                'name' => 'Abonament activat',
                'subject' => 'Abonamentul {{plan_name}} este activ',
                'content' => '<h1 style="margin:0 0 16px; font-size:23px; line-height:1.3; font-weight:700; color:#16241D;">Abonamentul tău este activ</h1><p>Salut, {{user_name}},</p><p>Planul {{plan_name}} este activ pe contul tău {{app_name}}. Ai acces la tot ce include, fără alți pași din partea ta.</p>',
                'meta' => [
                    'label' => 'ABONAMENT',
                    'preheader' => 'Ai acces complet începând de acum.',
                    'cta_label' => null,
                    'cta_url' => null,
                    'fallback' => false,
                    'note' => 'Factura și detaliile abonamentului le găsești oricând în secțiunea Facturare din cont.',
                    'description' => 'Trimis când un abonament nou devine activ. Nu are buton: locul de apel nu trimite niciun URL. Ciclul de facturare ajunge ca „month” / „year”, iar data în format englezesc, deci textul nu le folosește.',
                    'placeholders' => ['app_name', 'user_name', 'plan_name', 'billing_cycle', 'starts_at'],
                ],
            ],
            'subscription_cancelled' => [
                'name' => 'Abonament anulat',
                'subject' => 'Abonamentul {{plan_name}} a fost anulat',
                'content' => '<h1 style="margin:0 0 16px; font-size:23px; line-height:1.3; font-weight:700; color:#16241D;">Abonamentul a fost anulat</h1><p>Salut, {{user_name}},</p><p>Am anulat abonamentul {{plan_name}} și nu îți mai emitem nicio plată nouă. Dacă vrei să știi exact până când mai ai acces, scrie-ne și îți spunem.</p>',
                'meta' => [
                    'label' => 'ABONAMENT',
                    'preheader' => 'Datele din cont rămân la locul lor.',
                    'cta_label' => null,
                    'cta_url' => null,
                    'fallback' => false,
                    'note' => 'Conversațiile și contactele rămân salvate. Dacă vrei să reactivezi abonamentul, scrie-ne și te ajutăm.',
                    'description' => 'Trimis când un abonament este anulat. Nu are buton: locul de apel nu trimite niciun URL. Data de final ajunge fie ca dată în engleză, fie ca „immediately”, deci textul nu o folosește.',
                    'placeholders' => ['app_name', 'user_name', 'plan_name', 'ends_at'],
                ],
            ],
            'subscription_renewed' => [
                'name' => 'Abonament reînnoit',
                'subject' => 'Abonamentul {{plan_name}} s-a reînnoit',
                'content' => '<h1 style="margin:0 0 16px; font-size:23px; line-height:1.3; font-weight:700; color:#16241D;">Abonamentul s-a reînnoit</h1><p>Salut, {{user_name}},</p><p>Am încasat {{amount}} {{currency}} pentru abonamentul {{plan_name}}. Totul rămâne activ, nu trebuie să faci nimic.</p>',
                'meta' => [
                    'label' => 'FACTURARE',
                    'preheader' => 'Plata a trecut, nu ai nimic de făcut.',
                    'cta_label' => null,
                    'cta_url' => null,
                    'fallback' => false,
                    'note' => 'Factura o găsești în secțiunea Facturare din cont.',
                    'description' => 'Trimis la fiecare reînnoire plătită. Nu are buton: locul de apel nu trimite niciun URL. Data următoarei reînnoiri poate ajunge ca „—”, deci textul nu o folosește.',
                    'placeholders' => ['app_name', 'user_name', 'plan_name', 'amount', 'currency', 'next_renewal'],
                ],
            ],
            'support_ticket_created' => [
                'name' => 'Tichet înregistrat',
                'subject' => 'Am primit tichetul #{{ticket_id}}',
                'content' => '<h1 style="margin:0 0 16px; font-size:23px; line-height:1.3; font-weight:700; color:#16241D;">Am primit mesajul tău</h1><p>Salut, {{user_name}},</p><p>Ți-am înregistrat solicitarea „{{ticket_subject}}” cu numărul #{{ticket_id}}. O preia un coleg și primești răspunsul pe email.</p>',
                'meta' => [
                    'label' => 'SUPORT',
                    'preheader' => 'Îți răspundem cât putem de repede.',
                    'cta_label' => 'Vezi tichetul',
                    'cta_url' => '{{ticket_url}}',
                    'fallback' => false,
                    'note' => 'Dacă vrei să adaugi ceva, răspunde direct în tichet.',
                    'description' => 'Trimis persoanei care a deschis tichetul. Poate ajunge și la o adresă fără cont, dacă tichetul a fost deschis de un administrator. Prioritatea ajunge în engleză, deci textul nu o folosește.',
                    'placeholders' => ['app_name', 'user_name', 'ticket_id', 'ticket_subject', 'ticket_priority', 'ticket_url'],
                ],
            ],
            'support_ticket_status_changed' => [
                'name' => 'Stare tichet schimbată',
                'subject' => 'Am actualizat tichetul #{{ticket_id}}',
                'content' => '<h1 style="margin:0 0 16px; font-size:23px; line-height:1.3; font-weight:700; color:#16241D;">Starea tichetului s-a schimbat</h1><p>Salut, {{user_name}},</p><p>Tichetul #{{ticket_id}} — „{{ticket_subject}}” — are acum starea {{new_status}}.</p>',
                'meta' => [
                    'label' => 'SUPORT',
                    'preheader' => 'Tichetul „{{ticket_subject}}” a fost actualizat.',
                    'cta_label' => 'Vezi tichetul',
                    'cta_url' => '{{ticket_url}}',
                    'fallback' => false,
                    'note' => 'Dacă problema nu e rezolvată, răspunde în tichet și îl redeschidem.',
                    'description' => 'Trimis clientului când un administrator schimbă starea tichetului. Atenție: new_status ajunge în engleză (Open / In Progress / Closed), pentru că locul de apel nu îl traduce.',
                    'placeholders' => ['app_name', 'user_name', 'ticket_id', 'ticket_subject', 'new_status', 'ticket_url'],
                ],
            ],
        ];
    }
};
