<?php

namespace App\Services\Document;

use App\Models\Vendor;
use Illuminate\Support\Str;

class OcrVendorMatchScore
{
    /** @var list<string> */
    private const VENDOR_FIELDS = [
        'company_name',
        'trade_name',
        'tin',
        'dti_registration_number',
        'sec_registration_number',
        'business_permit_number',
        'registration_number',
        'business_address',
    ];

    /**
     * @return array{score: float|null, matched: int, expected: int}
     */
    public function calculate(?string $ocrText, ?Vendor $vendor): array
    {
        $values = $this->registrationValues($vendor);
        $expected = count($values);

        if ($expected === 0 || $ocrText === null || trim($ocrText) === '') {
            return ['score' => null, 'matched' => 0, 'expected' => $expected];
        }

        $matched = count(array_filter(
            $values,
            fn (string $value, string $key): bool => self::matches($ocrText, $value, $key === 'business_address'),
            ARRAY_FILTER_USE_BOTH,
        ));

        return [
            'score' => $matched / $expected,
            'matched' => $matched,
            'expected' => $expected,
        ];
    }

    public static function compare(?string $ocrValue, ?string $registrationValue, bool $isAddress = false): ?bool
    {
        $ocrValue = Str::squish((string) $ocrValue);
        $registrationValue = Str::squish((string) $registrationValue);

        if ($registrationValue === '') {
            return null;
        }

        if ($ocrValue === '') {
            return false;
        }

        return self::matches($ocrValue, $registrationValue, $isAddress);
    }

    /** @return array<string, string> */
    private function registrationValues(?Vendor $vendor): array
    {
        if ($vendor === null) {
            return [];
        }

        return collect(self::VENDOR_FIELDS)
            ->mapWithKeys(fn (string $field): array => [$field => Str::squish((string) $vendor->getAttribute($field))])
            ->filter(fn (string $value): bool => $value !== '')
            ->all();
    }

    private static function matches(string $ocrText, string $registrationValue, bool $isAddress = false): bool
    {
        if ($isAddress) {
            $ocrText = self::normalizeAddress($ocrText);
            $registrationValue = self::normalizeAddress($registrationValue);

            $ocrTokens = preg_split('/[^\p{L}\p{N}]+/u', $ocrText, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $regTokens = preg_split('/[^\p{L}\p{N}]+/u', $registrationValue, -1, PREG_SPLIT_NO_EMPTY) ?: [];

            foreach ($regTokens as $token) {
                if (! in_array($token, $ocrTokens, true)) {
                    return false;
                }
            }

            return true;
        }

        $tokens = preg_split(
            '/[^\p{L}\p{N}]+/u',
            $registrationValue,
            -1,
            PREG_SPLIT_NO_EMPTY,
        );

        if ($tokens === false || $tokens === []) {
            return false;
        }

        $pattern = implode(
            '[\s\p{P}\p{S}_]*',
            array_map(
                static fn (string $token): string => preg_quote($token, '~'),
                $tokens,
            ),
        );

        return preg_match(
            '~(?<![\p{L}\p{N}])'.$pattern.'(?![\p{L}\p{N}])~iu',
            $ocrText,
        ) === 1;
    }

    public static function normalizeAddress(string $text): string
    {
        $text = Str::upper($text);
        $replacements = [
            '/\bBRGY\b/u' => 'BARANGAY',
            '/\bST\b/u' => 'STREET',
            '/\bRD\b/u' => 'ROAD',
            '/\bAVE\b/u' => 'AVENUE',
            '/\bBLVD\b/u' => 'BOULEVARD',
            '/\bBLK\b/u' => 'BLOCK',
        ];

        return preg_replace(array_keys($replacements), array_values($replacements), $text) ?? $text;
    }
}
