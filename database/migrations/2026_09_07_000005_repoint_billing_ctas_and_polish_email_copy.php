<?php

use App\Models\Template;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Third pass over the Romanian transactional emails, fixing what a review of the rendered
 * output found:
 *
 *  - Five action buttons promised something their destination could not do. All of them
 *    pointed at Facturare, which is a read-only list of past invoices: there is no
 *    add-a-card control and no reactivate control anywhere on it. `subscription_started`,
 *    `subscription_cancelled` and `plan_changed` now point at the Abonament page,
 *    `subscription_expired` and `trial_ending` at Planuri — the only route in the app that
 *    reaches checkout. `payment_failed` keeps Facturare but stops claiming to update a
 *    card, because nothing in the app can: its prose asks the customer to write to us.
 *  - `trial_ending` no longer grafts "Zile rămase: N" into a sentence; it names the date,
 *    which also removes the "în 1 zile" case.
 *  - `subscription_cancelled` said "nu îți mai emitem nicio plată nouă", which reads as us
 *    owing the customer money rather than as us no longer charging them.
 *  - `support_ticket_created` called one object a mesaj, a solicitare and a tichet in four
 *    lines; every screen in the app says tichet.
 *  - `subscription_started` ended on a calque ("fără alți pași din partea ta").
 *
 * The mechanism is 000004's: ownership is decided against the previous ROMANIAN text held
 * verbatim in previous(), because sync() only recognises the ENGLISH factory text and no
 * row has matched that since 000003. The new text is read from EmailTemplateSeeder rather
 * than duplicated, so a fresh install and an upgraded one cannot drift.
 */
return new class extends Migration
{
    /**
     * Rows an admin has edited keep their own words: only `description` and `placeholders`
     * are refreshed on those, because those are documentation for /admin/email-system
     * rather than anything the recipient reads.
     */
    public function up(): void
    {
        foreach (self::previous() as $slug => $previous) {
            $existing = Template::where('slug', $slug)->where('type', 'email')->first();

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
     * Put back the copy 000004 left, on the same terms up() applied.
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
     * @param  array<string, mixed>  $previous  the meta 000004 left
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
     * The eight rows this migration touches, holding the exact text 000004 left on them.
     * Verbatim on purpose: the moment one character stops matching, up() decides every
     * untouched row belongs to the admin and quietly changes nothing.
     *
     * It is also the ownership list EmailTemplateSeeder::shippedRevisions() hands to
     * 000003's down(), so the rollback chain can still recognise these rows as ours.
     *
     * @return array<string, array{name: string, subject: string, content: string, meta: array<string, mixed>}>
     */
    private static function previous(): array
    {
        return [
            'payment_failed' => [
                'name' => 'Plată eșuată',
                'subject' => 'Plata nu a trecut — actualizează metoda de plată',
                'content' => '<h1 style="margin:0 0 16px; font-size:23px; line-height:1.3; font-weight:700; color:#16241D;">Plata nu a putut fi procesată</h1><p>Salut, {{user_name}},</p><p>Plata de {{amount}} {{currency}} pentru abonamentul tău {{app_name}} a fost refuzată. De obicei e un card expirat sau o blocare de la bancă. Actualizează metoda de plată ca să nu se întrerupă serviciul.</p>',
                'meta' => [
                    'note' => 'Până se face plata, abonamentul rămâne marcat ca neachitat. Dacă ai nevoie de ajutor, scrie-ne.',
                    'label' => 'FACTURARE',
                    'cta_url' => '{{billing_url}}',
                    'fallback' => false,
                    'cta_label' => 'Actualizează metoda de plată',
                    'preheader' => 'Durează un minut să schimbi cardul.',
                    'description' => 'Trimis titularului abonamentului când o plată recurentă eșuează. Doar pe Stripe. Suma vine ca număr simplu, fără simbol, iar moneda ca un cod separat — se scriu întotdeauna împreună.',
                    'placeholders' => ['app_name', 'user_name', 'amount', 'currency', 'billing_url'],
                ],
            ],
            'subscription_started' => [
                'name' => 'Abonament activat',
                'subject' => 'Abonamentul {{plan_name}} este activ',
                'content' => '<h1 style="margin:0 0 16px; font-size:23px; line-height:1.3; font-weight:700; color:#16241D;">Abonamentul tău este activ</h1><p>Salut, {{user_name}},</p><p>Planul {{plan_name}} este activ pe contul tău {{app_name}} începând din {{starts_at}}. Ai acces la tot ce include, fără alți pași din partea ta.</p>',
                'meta' => [
                    'note' => 'Dacă ceva nu ți se pare corect, scrie-ne și verificăm împreună.',
                    'label' => 'ABONAMENT',
                    'cta_url' => '{{billing_url}}',
                    'fallback' => false,
                    'cta_label' => 'Vezi abonamentul',
                    'preheader' => 'Ai acces complet începând de acum.',
                    'description' => 'Trimis când un abonament nou devine activ. Butonul duce în secțiunea Facturare, unde stau facturile și detaliile abonamentului. Ciclul de facturare ajunge ca „month” / „year”, deci textul nu îl folosește.',
                    'placeholders' => ['app_name', 'user_name', 'plan_name', 'billing_cycle', 'starts_at', 'billing_url'],
                ],
            ],
            'subscription_cancelled' => [
                'name' => 'Abonament anulat',
                'subject' => 'Abonamentul {{plan_name}} a fost anulat',
                'content' => '<h1 style="margin:0 0 16px; font-size:23px; line-height:1.3; font-weight:700; color:#16241D;">Abonamentul a fost anulat</h1><p>Salut, {{user_name}},</p><p>Am anulat abonamentul {{plan_name}} și nu îți mai emitem nicio plată nouă.</p><p>Accesul se încheie: {{ends_at}}.</p>',
                'meta' => [
                    'note' => 'Conversațiile și contactele rămân salvate. Dacă te răzgândești, poți reactiva abonamentul de aici.',
                    'label' => 'ABONAMENT',
                    'cta_url' => '{{billing_url}}',
                    'fallback' => false,
                    'cta_label' => 'Reactivează abonamentul',
                    'preheader' => 'Datele din cont rămân la locul lor.',
                    'description' => 'Trimis când un abonament este anulat. Data de final ajunge scrisă în română, sau ca „imediat” când anularea are efect pe loc — de asta stă pe rândul ei, într-o propoziție care funcționează în ambele situații.',
                    'placeholders' => ['app_name', 'user_name', 'plan_name', 'ends_at', 'billing_url'],
                ],
            ],
            'subscription_renewed' => [
                'name' => 'Abonament reînnoit',
                'subject' => 'Abonamentul {{plan_name}} s-a reînnoit',
                'content' => '<h1 style="margin:0 0 16px; font-size:23px; line-height:1.3; font-weight:700; color:#16241D;">Abonamentul s-a reînnoit</h1><p>Salut, {{user_name}},</p><p>Am încasat {{amount}} {{currency}} pentru abonamentul {{plan_name}}. Totul rămâne activ, nu trebuie să faci nimic.</p><p>Următoarea reînnoire: {{next_renewal}}</p>',
                'meta' => [
                    'note' => 'Dacă suma nu ți se pare corectă, scrie-ne și o verificăm.',
                    'label' => 'FACTURARE',
                    'cta_url' => '{{billing_url}}',
                    'fallback' => false,
                    'cta_label' => 'Vezi facturile',
                    'preheader' => 'Plata a trecut, nu ai nimic de făcut.',
                    'description' => 'Trimis la fiecare reînnoire plătită. Data următoarei reînnoiri poate lipsi și atunci ajunge ca „—”, de aceea stă pe un rând al ei, fără punct la final.',
                    'placeholders' => ['app_name', 'user_name', 'plan_name', 'amount', 'currency', 'next_renewal', 'billing_url'],
                ],
            ],
            'subscription_expired' => [
                'name' => 'Abonament expirat',
                'subject' => 'Abonamentul {{plan_name}} s-a încheiat',
                'content' => '<h1 style="margin:0 0 16px; font-size:23px; line-height:1.3; font-weight:700; color:#16241D;">Abonamentul tău s-a încheiat</h1><p>Salut, {{user_name}},</p><p>Perioada ta pe planul {{plan_name}} s-a încheiat și abonamentul nu mai este activ. Reactivează-l ca să continui pe {{app_name}} fără întreruperi.</p>',
                'meta' => [
                    'note' => 'Conversațiile, contactele și setările rămân salvate. Nu se șterge nimic.',
                    'label' => 'ABONAMENT',
                    'cta_url' => '{{billing_url}}',
                    'fallback' => false,
                    'cta_label' => 'Reactivează abonamentul',
                    'preheader' => 'Reactivezi în câteva minute, direct din cont.',
                    'description' => 'Trimis și când expiră un abonament plătit, și când se termină o perioadă de probă fără conversie. Textul trebuie să funcționeze în ambele situații.',
                    'placeholders' => ['app_name', 'user_name', 'plan_name', 'billing_url'],
                ],
            ],
            'plan_changed' => [
                'name' => 'Schimbare de plan',
                'subject' => 'Planul tău a fost schimbat',
                'content' => '<h1 style="margin:0 0 16px; font-size:23px; line-height:1.3; font-weight:700; color:#16241D;">Planul tău a fost schimbat</h1><p>Salut, {{user_name}},</p><p>Abonamentul tău {{app_name}} a trecut de la {{old_plan}} la {{new_plan}}. Schimbarea este deja activă pe cont.</p>',
                'meta' => [
                    'note' => 'Dacă schimbarea nu ți se pare corectă, scrie-ne și o verificăm.',
                    'label' => 'ABONAMENT',
                    'cta_url' => '{{billing_url}}',
                    'fallback' => false,
                    'cta_label' => 'Vezi detaliile',
                    'preheader' => 'Nu ai nimic de făcut — doar de știut.',
                    'description' => 'Trimis când planul unui abonament este schimbat. Textul rămâne neutru: nu știm dacă e trecere în sus sau în jos și nu primim nicio sumă.',
                    'placeholders' => ['app_name', 'user_name', 'old_plan', 'new_plan', 'billing_url'],
                ],
            ],
            'trial_ending' => [
                'name' => 'Perioada de probă se încheie',
                'subject' => 'Perioada de probă {{plan_name}} se apropie de final',
                'content' => '<h1 style="margin:0 0 16px; font-size:23px; line-height:1.3; font-weight:700; color:#16241D;">Perioada de probă se apropie de final</h1><p>Salut, {{user_name}},</p><p>Perioada de probă pentru planul {{plan_name}} se apropie de final. Zile rămase: {{days_remaining}}.</p><p>Adaugă o metodă de plată ca să continui fără întrerupere.</p>',
                'meta' => [
                    'note' => 'Dacă nu adaugi o metodă de plată, abonamentul nu pornește la finalul perioadei de probă. Datele rămân salvate.',
                    'label' => 'ABONAMENT',
                    'cta_url' => '{{billing_url}}',
                    'fallback' => false,
                    'cta_label' => 'Adaugă metoda de plată',
                    'preheader' => 'Adaugă o metodă de plată ca să nu se întrerupă nimic.',
                    'description' => 'Trimis o singură dată, înainte ca perioada de probă să se termine. Numărul de zile este scris ca „zile rămase: N”, pentru că valoarea poate fi 1 și acordul obișnuit ar fi greșit.',
                    'placeholders' => ['app_name', 'user_name', 'plan_name', 'days_remaining', 'trial_ends_at', 'billing_url'],
                ],
            ],
            'support_ticket_created' => [
                'name' => 'Tichet înregistrat',
                'subject' => 'Am primit tichetul #{{ticket_id}}',
                'content' => '<h1 style="margin:0 0 16px; font-size:23px; line-height:1.3; font-weight:700; color:#16241D;">Am primit mesajul tău</h1><p>Salut, {{user_name}},</p><p>Ți-am înregistrat solicitarea „{{ticket_subject}}” cu numărul #{{ticket_id}}. O preia un coleg și primești răspunsul pe email.</p>',
                'meta' => [
                    'note' => 'Dacă vrei să adaugi ceva, răspunde direct în tichet.',
                    'label' => 'SUPORT',
                    'cta_url' => '{{ticket_url}}',
                    'fallback' => false,
                    'cta_label' => 'Vezi tichetul',
                    'preheader' => 'Îți răspundem cât putem de repede.',
                    'description' => 'Trimis persoanei care a deschis tichetul. Poate ajunge și la o adresă fără cont, dacă tichetul a fost deschis de un administrator. Prioritatea ajunge tradusă („ridicată”, „urgentă”), dar textul nu o folosește: nu îi spune nimic celui care tocmai a scris.',
                    'placeholders' => ['app_name', 'user_name', 'ticket_id', 'ticket_subject', 'ticket_priority', 'ticket_url'],
                ],
            ],
        ];
    }
};
