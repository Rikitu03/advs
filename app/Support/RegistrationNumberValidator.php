<?php

namespace App\Support;

/**
 * DTI / business-registration certificate-number format validation — the second
 * deterministic identifier check of the Stage T cross-reference technique (T5).
 *
 * Certificate numbers vary by issuer but are consistently alphanumeric with a
 * numeric serial of at least five digits (often a year prefix). This is the
 * FORMAT gate; a registry existence lookup attaches here when available.
 */
class RegistrationNumberValidator
{
    private const MIN_DIGITS = 5;

    public static function normalize(string $number): string
    {
        return trim($number);
    }

    public static function isValid(string $number): bool
    {
        $digits = preg_replace('/\D/', '', $number) ?? '';

        return strlen($digits) >= self::MIN_DIGITS
            && preg_match('/^[A-Za-z0-9\-\/]+$/', self::normalize($number)) === 1;
    }
}
