<?php

namespace App\Support;

/**
 * Philippine TIN (Tax Identification Number) format validation — the cheap,
 * deterministic half of the Stage T cross-reference technique (T5).
 *
 * A well-formed TIN is 9 base digits, optionally followed by a 3-5 digit branch
 * code, conventionally written as ``NNN-NNN-NNN`` or ``NNN-NNN-NNN-NNN``. This
 * class owns the FORMAT check; the authoritative existence check (does this TIN
 * appear in the BIR registry?) attaches here when a registry source is wired in.
 */
class TinValidator
{
    /** Valid total digit counts: 9 (base) or 9 + a 3/4/5-digit branch code. */
    private const VALID_LENGTHS = [9, 12, 13, 14];

    public static function normalize(string $tin): string
    {
        return preg_replace('/\D/', '', $tin) ?? '';
    }

    public static function isValid(string $tin): bool
    {
        return in_array(strlen(self::normalize($tin)), self::VALID_LENGTHS, true);
    }
}
