<?php

namespace App\Support;

/**
 * Generates a full 50–950 tint/shade ramp from a single hex colour, so the admin
 * can pick one brand colour and every `bg-brand-*` / `text-brand-*` utility in the
 * app follows. The ramp is emitted as space-separated RGB channels ("35 122 87")
 * because Tailwind's colour tokens are defined as
 * `rgb(var(--brand-500) / <alpha-value>)` — the bare-channel form is what keeps
 * opacity modifiers (`bg-brand-500/20`) working.
 *
 * The picked colour is pinned to the 500 stop (primary buttons). Anchors we ship
 * with (see HAND_TUNED) use a hand-tuned ramp rather than a generated
 * approximation. Surfaces are not derived from the brand hue — they stay on the
 * warm canvas tokens in app.css.
 */
class BrandPalette
{
    /**
     * Lightness mix ratios per stop, relative to the 500 anchor.
     * Positive = mix toward white, negative = mix toward black.
     */
    private const CURVE = [
        '50' => 0.941,
        '100' => 0.870,
        '200' => 0.747,
        '300' => 0.567,
        '400' => 0.263,
        '500' => 0.0,
        '600' => -0.197,
        '700' => -0.362,
        '800' => -0.515,
        '900' => -0.688,
        '950' => -0.805,
    ];

    /**
     * Hand-tuned ramps, keyed by lowercase hex.
     *
     * The generated curve preserves saturation as it raises lightness, which is
     * fine for a muted anchor but turns a saturated one neon at the light end:
     * #047857 generates brand-300 = #5dfacd, which the brand guide rules out
     * ("fara culori neon"). Anchors we ship with are therefore tuned by hand and
     * kept in sync with the :root defaults in resources/css/app.css.
     *
     * @var array<string, array<string, string>>
     */
    private const HAND_TUNED = [
        // "Velvet Sage" — the current brand. 500 is #047857; 300 is #7ce3b1, the
        // mint used for the selected nav icon.
        '#047857' => [
            '50' => '240 250 246',
            '100' => '219 243 233',
            '200' => '179 230 208',
            '300' => '124 227 177',
            '400' => '52 168 122',
            '500' => '4 120 87',
            '600' => '3 96 70',
            '700' => '3 77 56',
            '800' => '2 58 42',
            '900' => '1 37 27',
            '950' => '1 23 17',
        ],
        // Previous brand. Retained so an install that already stored this colour
        // keeps its tuned ramp instead of falling back to a generated one.
        '#237a57' => [
            '50' => '242 247 244',
            '100' => '225 239 231',
            '200' => '196 225 209',
            '300' => '154 203 177',
            '400' => '82 168 126',
            '500' => '35 122 87',
            '600' => '27 99 70',
            '700' => '22 78 55',
            '800' => '17 59 42',
            '900' => '11 38 27',
            '950' => '7 24 16',
        ],
    ];

    /**
     * Build the ramp for a hex colour.
     *
     * @param  array<string, float>  $curve
     * @return array<string, string> stop => "R G B"
     */
    public static function ramp(string $hex, array $curve = self::CURVE): array
    {
        if ($curve === self::CURVE && isset(self::HAND_TUNED[strtolower($hex)])) {
            return self::HAND_TUNED[strtolower($hex)];
        }

        [$h, $s, $l] = self::hexToHsl($hex);

        $ramp = [];
        foreach ($curve as $stop => $ratio) {
            $stopL = $ratio >= 0
                ? $l + $ratio * (1 - $l)
                : $l * (1 + $ratio);

            $ramp[$stop] = self::hslToChannels($h, $s, $stopL);
        }

        return $ramp;
    }

    /**
     * CSS custom-property declarations for a ramp, e.g. `--brand-50: 242 247 244;`.
     */
    public static function cssVars(string $prefix, string $hex): string
    {
        $out = [];
        foreach (self::ramp($hex) as $stop => $channels) {
            $out[] = "--{$prefix}-{$stop}: {$channels};";
        }

        return implode(' ', $out);
    }

    /**
     * Surfaces stay on the warm canvas from app.css. Kept for call-site compatibility.
     */
    public static function surfaceVars(string $hex): string
    {
        return '';
    }

    /** A hex string is usable only if it is exactly `#rrggbb`. */
    public static function isValidHex(?string $hex): bool
    {
        return is_string($hex) && preg_match('/^#[0-9A-Fa-f]{6}$/', $hex) === 1;
    }

    /** @return array{float, float, float} hue 0–1, saturation 0–1, lightness 0–1 */
    private static function hexToHsl(string $hex): array
    {
        $hex = ltrim($hex, '#');
        $r = hexdec(substr($hex, 0, 2)) / 255;
        $g = hexdec(substr($hex, 2, 2)) / 255;
        $b = hexdec(substr($hex, 4, 2)) / 255;

        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        $d = $max - $min;

        if ($d == 0.0) {
            return [0.0, 0.0, $l];
        }

        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);

        $h = match ($max) {
            $r => (($g - $b) / $d) + ($g < $b ? 6 : 0),
            $g => (($b - $r) / $d) + 2,
            default => (($r - $g) / $d) + 4,
        };

        return [$h / 6, $s, $l];
    }

    private static function hslToChannels(float $h, float $s, float $l): string
    {
        if ($s == 0.0) {
            $v = (int) round($l * 255);

            return "{$v} {$v} {$v}";
        }

        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;

        $r = (int) round(self::hueToRgb($p, $q, $h + 1 / 3) * 255);
        $g = (int) round(self::hueToRgb($p, $q, $h) * 255);
        $b = (int) round(self::hueToRgb($p, $q, $h - 1 / 3) * 255);

        return "{$r} {$g} {$b}";
    }

    private static function hueToRgb(float $p, float $q, float $t): float
    {
        if ($t < 0) {
            $t += 1;
        }
        if ($t > 1) {
            $t -= 1;
        }
        if ($t < 1 / 6) {
            return $p + ($q - $p) * 6 * $t;
        }
        if ($t < 1 / 2) {
            return $q;
        }
        if ($t < 2 / 3) {
            return $p + ($q - $p) * (2 / 3 - $t) * 6;
        }

        return $p;
    }
}
