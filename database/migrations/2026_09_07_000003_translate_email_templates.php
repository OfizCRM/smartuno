<?php

use App\Models\Template;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Ship the Romanian, layout-aware transactional email templates to installs that already
 * have the English rows.
 *
 * EmailTemplateSeeder only ever refreshes `meta` on a row that already exists, so editing
 * the seeder alone changes nothing on wm3_dev or on any live install — the copy has to be
 * carried across by a migration.
 *
 * The definitions themselves are not duplicated here: both this migration and the seeder
 * call EmailTemplateSeeder::sync(), so the two can never drift apart.
 */
return new class extends Migration
{
    public function up(): void
    {
        EmailTemplateSeeder::sync();
    }

    /**
     * Put the English factory text back.
     *
     * Only rows that still hold Romanian copy this project shipped are touched, so a
     * template an admin has edited since keeps their words on the way down too.
     *
     * "Copy this project shipped" is deliberately wider than "the current seeder
     * definition". Later migrations rewrite some of these rows, and their own down() puts
     * their predecessor's text back — which is no longer what templates() returns. Asking
     * only about the current definition made this method conclude those rows belonged to
     * the admin, so `migrate:rollback --step=2` left five rows in Romanian while the other
     * fourteen went back to English. EmailTemplateSeeder::shippedRevisions() carries the
     * earlier texts, and a row matching any of them is ours.
     *
     * Rows that up() had to create are restored to the English original rather than deleted:
     * MailService needs the row to exist at all, and a missing template makes a send fail
     * silently instead of loudly.
     */
    public function down(): void
    {
        $revisions = EmailTemplateSeeder::shippedRevisions();

        foreach (EmailTemplateSeeder::templates() as $definition) {
            $existing = Template::where('slug', $definition['slug'])
                ->where('type', 'email')
                ->first();

            if (! $existing) {
                continue;
            }

            $ours = array_merge([[
                'subject' => $definition['subject'],
                'content' => $definition['content'],
                'meta' => $definition['meta'],
            ]], $revisions[$definition['slug']] ?? []);

            // The copy and the meta roll back on separate conditions on purpose. up()
            // refreshes the documentation keys even on a row whose subject and content it
            // deliberately leaves alone, so a row that kept its own words can still be
            // holding ours — and without this it would keep holding them after a rollback.
            $attributes = ['meta' => $this->rolledBackMeta($existing, $ours, $definition['legacy']['meta'])];

            foreach ($ours as $version) {
                if (! EmailTemplateSeeder::holdsText($existing, $version)) {
                    continue;
                }

                $attributes['name'] = $definition['legacy']['name'];
                $attributes['subject'] = $definition['legacy']['subject'];
                $attributes['content'] = $definition['legacy']['content'];

                // sync() switches the two slugs that have no call site off, and it is the
                // only thing that ever touches the flag — so without this a rollback leaves
                // them disabled while the factory rows they are being restored to shipped
                // enabled. Only ever set on a row whose copy we are also restoring.
                if ($definition['enabled'] === false) {
                    $attributes['enabled'] = true;
                }

                break;
            }

            $existing->update($attributes);
        }
    }

    /**
     * The meta to put back on one row.
     *
     * The whole thing when the whole thing is still ours; otherwise only the two
     * documentation keys, and only while they still hold something we wrote — anything an
     * admin has changed since stays changed.
     *
     * @param  list<array{subject: string, content: string, meta: array<string, mixed>}>  $ours  every meta this project has shipped for the row, current first
     * @param  array<string, mixed>  $legacy  the English factory meta
     * @return array<string, mixed>
     */
    private function rolledBackMeta(Template $existing, array $ours, array $legacy): array
    {
        $stored = $existing->meta;

        if (is_string($stored)) {
            $stored = json_decode($stored, true);
        }

        $stored = is_array($stored) ? $stored : [];

        foreach ($ours as $version) {
            if ($stored == $version['meta']) {
                return $legacy;
            }
        }

        foreach (['description', 'placeholders'] as $key) {
            if (! array_key_exists($key, $stored)) {
                continue;
            }

            foreach ($ours as $version) {
                if ($stored[$key] == ($version['meta'][$key] ?? null)) {
                    $stored[$key] = $legacy[$key] ?? null;

                    break;
                }
            }
        }

        return $stored;
    }
};
