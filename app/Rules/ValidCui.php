<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Romanian CUI / CIF checksum (the ANAF control-digit algorithm).
 *
 * This catches typos, not fiction: a number can pass the checksum and still
 * belong to nobody. Confirming a company actually exists means an ANAF lookup,
 * which is a separate concern.
 *
 * The literal English sentence is the key, not the final message: translate()
 * resolves it through lang/{locale}.json, so a Romanian user reads Romanian and
 * no raw "validation.cui" placeholder ever reaches the JSX, which renders these
 * verbatim (see resources/js/Pages/client/Settings/Company.jsx). A new message
 * and its Romanian belong in lang/ro.json together; an untranslated one simply
 * falls back to the English written here.
 */
class ValidCui implements ValidationRule
{
    /**
     * Positional weights, right-aligned against the 9-digit body.
     */
    private const KEY = '753217532';

    /**
     * @param  Closure(string): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) && ! is_int($value)) {
            $fail('The :attribute must be a valid Romanian CUI.')->translate();

            return;
        }

        // Accept what people actually paste: "RO 14399840", "RO-14399840", dots.
        $cui = strtoupper(trim((string) $value));
        $cui = preg_replace('/^RO/', '', $cui) ?? '';
        $cui = preg_replace('/[\s.\-]/', '', $cui) ?? '';

        if (preg_match('/^\d{2,10}$/', $cui) !== 1) {
            $fail('The :attribute must be a valid Romanian CUI.')->translate();

            return;
        }

        $control = (int) substr($cui, -1);
        $body = str_pad(substr($cui, 0, -1), 9, '0', STR_PAD_LEFT);

        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $body[$i] * (int) self::KEY[$i];
        }

        $checksum = ($sum * 10) % 11;
        if ($checksum === 10) {
            $checksum = 0;
        }

        if ($checksum !== $control) {
            $fail('The :attribute is not a valid Romanian CUI.')->translate();
        }
    }
}
