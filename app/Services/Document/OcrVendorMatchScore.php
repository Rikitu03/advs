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
            fn (string $value): bool => self::matches($ocrText, $value),
        ));

        return [
            'score' => $matched / $expected,
            'matched' => $matched,
            'expected' => $expected,
        ];
    }

    public static function compare(?string $ocrValue, ?string $registrationValue): ?bool
    {
        $ocrValue = Str::squish((string) $ocrValue);
        $registrationValue = Str::squish((string) $registrationValue);

        if ($registrationValue === '') {
            return null;
        }

        if ($ocrValue === '') {
            return false;
        }

        return self::matches($ocrValue, $registrationValue);
    }

    /** @return list<string> */
    private function registrationValues(?Vendor $vendor): array
    {
        if ($vendor === null) {
            return [];
        }

        return collect(self::VENDOR_FIELDS)
            ->map(fn (string $field): string => Str::squish((string) $vendor->getAttribute($field)))
            ->filter(fn (string $value): bool => $value !== '')
            ->values()
            ->all();
    }

    private static function matches(string $ocrText, string $registrationValue): bool
    {
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
}
