<?php

namespace App\Modules\Catalog\Support;

/**
 * Lei in, bani out.
 *
 * Money is stored as an integer number of bani everywhere in this module, so
 * there is exactly one place where a decimal a person typed becomes a column
 * value. It lives here rather than on a controller because both the form and
 * the CSV importer need it, and a second copy is how two screens end up
 * disagreeing about what "1.234,50" means.
 */
class Money
{
    /**
     * A price as a person wrote it, in bani.
     *
     * Accepts what Romanian keyboards and Romanian spreadsheets produce —
     * "240", "240,50", "1.234,50" — as well as the "1,234.50" a supplier's
     * export uses. Whichever separator comes last is the decimal point; the
     * other groups thousands.
     *
     * Rounds rather than truncates, because (int) (24.05 * 100) is 2404.
     */
    public static function bani(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        $value = (string) preg_replace('/[^0-9,.\-]/', '', (string) $value);

        $comma = strrpos($value, ',');
        $dot = strrpos($value, '.');

        if ($comma !== false && $dot !== false) {
            $value = $comma > $dot
                ? str_replace(['.', ','], ['', '.'], $value)
                : str_replace(',', '', $value);
        } elseif ($comma !== false) {
            $value = str_replace(',', '.', $value);
        }

        return max(0, (int) round(((float) $value) * 100));
    }

    /**
     * Escape a term for a LIKE pattern.
     *
     * The backslash goes first, and it is the one people forget: escaping % and
     * _ with a backslash while leaving a user's own backslash alone means their
     * backslash escapes our wildcard, and the search silently stops matching.
     */
    public static function likeTerm(string $term): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';
    }
}
