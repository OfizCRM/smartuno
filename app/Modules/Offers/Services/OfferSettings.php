<?php

namespace App\Modules\Offers\Services;

use App\Models\ClientSetting;

/**
 * The five numbers a firm sets once and then forgets about: how long an offer
 * stays valid, what it charges for delivery, above what basket delivery is free,
 * a standing discount, and the line at the bottom of the PDF.
 *
 * They live in client_settings, the existing key/value store that already holds
 * weekly_digest_enabled, rather than in a table of their own. Five scalars per
 * tenant do not earn a migration, a model and a form request — and the store is
 * keyed on client_id, which is where the seller's fiscal identity already lives,
 * so the offer settings sit next to the CUI and the IBAN that end up on the same
 * PDF.
 *
 * THE NULL CLIENT IS NOT A BUG. workspaces.client_id is nullable, so a user can
 * reach this screen with no client row at all — a workspace created before the
 * company profile was filled in. There is nowhere to write for such a user, so
 * get() answers with the defaults and save() refuses, honestly, by returning
 * false. The screen tells them to complete the company profile first. What must
 * never happen is the third option: accepting the form, showing "salvat", and
 * writing nothing.
 */
class OfferSettings
{
    /**
     * Both the shape and the fallback. Every value is bani except
     * validity_days (days) and default_discount_percent (a percentage).
     *
     * @var array{
     *     validity_days: int,
     *     free_shipping_cents: int,
     *     shipping_cents: int,
     *     default_discount_percent: int,
     *     footer_text: string
     * }
     */
    public const DEFAULTS = [
        'validity_days' => 14,
        // 300 lei. Above it, delivery is on the seller.
        'free_shipping_cents' => 30000,
        'shipping_cents' => 0,
        'default_discount_percent' => 0,
        'footer_text' => '',
    ];

    /** Namespaced so the offer keys never collide with another module's. */
    private const PREFIX = 'offers.';

    /**
     * The settings for a client, with every key present.
     *
     * One query rather than five ClientSetting::get() calls: this is read on
     * every offer that gets created, not only on the settings screen, and five
     * round trips to fetch five scalars is four too many.
     *
     * @return array{
     *     validity_days: int,
     *     free_shipping_cents: int,
     *     shipping_cents: int,
     *     default_discount_percent: int,
     *     footer_text: string
     * }
     */
    public function get(?int $clientId): array
    {
        if ($clientId === null) {
            return self::DEFAULTS;
        }

        $keys = array_map(fn (string $key): string => self::PREFIX.$key, array_keys(self::DEFAULTS));

        /** @var array<string, string|null> $stored */
        $stored = ClientSetting::query()
            ->where('client_id', $clientId)
            ->whereIn('key', $keys)
            ->pluck('value', 'key')
            ->all();

        return [
            'validity_days' => $this->int($stored, 'validity_days'),
            'free_shipping_cents' => $this->int($stored, 'free_shipping_cents'),
            'shipping_cents' => $this->int($stored, 'shipping_cents'),
            'default_discount_percent' => $this->int($stored, 'default_discount_percent'),
            'footer_text' => $this->text($stored, 'footer_text'),
        ];
    }

    /**
     * Write the keys that were given, and say whether anything was written.
     *
     * Only the five known keys are touched, and only when present, so a screen
     * that submits one field does not silently reset the other four.
     *
     * @param  array<string, mixed>  $values
     * @return bool false when there is no client to write to
     */
    public function save(?int $clientId, array $values): bool
    {
        if ($clientId === null) {
            return false;
        }

        foreach (array_keys(self::DEFAULTS) as $key) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            ClientSetting::set($clientId, self::PREFIX.$key, $this->clean($key, $values[$key]));
        }

        return true;
    }

    /**
     * The value as it goes into the text column.
     *
     * Clamped, not trusted: these four numbers are read straight into the money
     * arithmetic, and a negative shipping charge or a 400% discount would come
     * back out of OfferTotals as a total the seller never meant to quote. The
     * form validates too; this is the floor under it.
     */
    private function clean(string $key, mixed $value): string
    {
        return match ($key) {
            // A year is already an absurd validity for an offer; zero days would
            // make every offer expire the moment it is written.
            'validity_days' => (string) max(1, min(365, (int) $value)),
            'default_discount_percent' => (string) max(0, min(100, (int) $value)),
            'footer_text' => trim((string) (is_scalar($value) ? $value : '')),
            default => (string) max(0, (int) $value),
        };
    }

    /**
     * @param  array<string, string|null>  $stored
     */
    private function int(array $stored, string $key): int
    {
        $value = $stored[self::PREFIX.$key] ?? null;

        // An empty string is a row someone cleared, and it means the default,
        // not zero — which for free_shipping_cents is the difference between
        // "300 lei" and "we do not do free delivery".
        return $value === null || trim($value) === ''
            ? (int) self::DEFAULTS[$key]
            : (int) $value;
    }

    /**
     * @param  array<string, string|null>  $stored
     */
    private function text(array $stored, string $key): string
    {
        return (string) ($stored[self::PREFIX.$key] ?? self::DEFAULTS[$key]);
    }
}
