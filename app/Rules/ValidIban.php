<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * IBAN mod-97 check (ISO 13616 / ISO 7064).
 *
 * Structurally valid only — it proves the account number was typed correctly,
 * not that the account is open or belongs to this company.
 *
 * The literal English sentence is the key, not the final message: translate()
 * resolves it through lang/{locale}.json, exactly as in ValidCui.
 */
class ValidIban implements ValidationRule
{
    /**
     * Romanian IBANs are fixed-length. Checking it explicitly turns a silent
     * mod-97 collision on a truncated number into a clear failure.
     */
    private const RO_LENGTH = 24;

    /**
     * @param  Closure(string): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be a valid IBAN.')->translate();

            return;
        }

        $iban = strtoupper(preg_replace('/\s+/', '', $value) ?? '');

        if (preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $iban) !== 1) {
            $fail('The :attribute must be a valid IBAN.')->translate();

            return;
        }

        if (str_starts_with($iban, 'RO') && strlen($iban) !== self::RO_LENGTH) {
            $fail('A Romanian IBAN must be 24 characters long.')->translate();

            return;
        }

        if ($this->mod97($this->rearrange($iban)) !== 1) {
            $fail('The :attribute is not a valid IBAN.')->translate();
        }
    }

    /**
     * Move the country code and check digits to the end, then expand every
     * letter to its two-digit numeric value (A = 10 ... Z = 35).
     */
    private function rearrange(string $iban): string
    {
        $rotated = substr($iban, 4).substr($iban, 0, 4);

        $numeric = '';
        foreach (str_split($rotated) as $char) {
            $numeric .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }

        return $numeric;
    }

    /**
     * Piecewise mod-97. The expanded string is up to ~38 digits, far past
     * PHP_INT_MAX, so we fold it 7 digits at a time carrying the remainder
     * rather than reaching for bcmath (which is not a guaranteed extension).
     */
    private function mod97(string $numeric): int
    {
        $remainder = 0;

        foreach (str_split($numeric, 7) as $chunk) {
            $remainder = (int) (((string) $remainder).$chunk) % 97;
        }

        return $remainder;
    }
}
