<?php

namespace App\Services\Document;

use App\Models\Vendor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final class BusinessPermitEvidence
{
    /**
     * @param  array<string, mixed>  $evidence
     * @return array{score: float|null, checks: array<string, array<string, mixed>>, flags: list<string>, validity: string}
     */
    public function score(array $evidence, ?Vendor $vendor): array
    {
        $fields = is_array($evidence['fields'] ?? null) ? $evidence['fields'] : [];
        $checks = [];
        $flags = [];

        $cityEvidence = [
            'value' => $evidence['issuer_city_canonical'] ?? null,
            'confidence' => $evidence['issuer_city_confidence'] ?? null,
            'source_page' => $evidence['source_page'] ?? null,
            'warnings' => $evidence['warnings'] ?? [],
        ];
        $this->check($checks, ['issuer_city_canonical' => $cityEvidence], 'issuer_city_canonical', 'city', (string) ($vendor?->business_city ?? ''));
        $this->check($checks, $fields, 'permit_no', 'permit_number', (string) ($vendor?->business_permit_number ?? ''));
        $this->check($checks, $fields, 'owner_or_proprietor', 'owner', (string) ($vendor?->company_name ?? ''));
        if ($vendor?->representative !== null && ! ($checks['owner']['matched'] ?? false)) {
            $representative = $vendor->representative->fullName();
            $checks['owner']['matched'] = $this->matches((string) ($checks['owner']['actual'] ?? ''), $representative, 'owner');
            if ($checks['owner']['matched']) {
                $checks['owner']['expected'] = $representative;
            }
        }
        $tradeExpected = array_values(array_filter([
            (string) ($vendor?->trade_name ?? ''),
            (string) ($vendor?->company_name ?? ''),
        ]));
        $this->check($checks, $fields, 'trade_name', 'trade_name', $tradeExpected[0] ?? '');
        if (count($tradeExpected) > 1 && ! ($checks['trade_name']['matched'] ?? false)) {
            $checks['trade_name']['matched'] = $this->matches((string) ($checks['trade_name']['actual'] ?? ''), $tradeExpected[1], 'trade_name');
        }
        $this->check($checks, $fields, 'business_address', 'address', (string) ($vendor?->business_address ?? ''));
        $this->check($checks, $fields, 'nature_of_business', 'nature', (string) ($vendor?->nature_of_business ?? ''));

        foreach ($checks as $key => $check) {
            if (($check['available'] ?? false) && ! ($check['matched'] ?? false)) {
                $flags[] = 'business_permit_identity_mismatch:'.$key;
            }
        }

        $validity = $this->validity($fields, $flags);
        $available = array_filter($checks, static fn (array $check): bool => $check['available'] === true);
        $score = $available === []
            ? null
            : round(array_sum(array_map(static fn (array $check): float => $check['matched'] ? 1.0 : 0.0, $available)) / count($available), 3);

        return [
            'score' => $score,
            'checks' => $checks,
            'flags' => array_values(array_unique($flags)),
            'validity' => $validity,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $checks
     * @param  array<string, mixed>  $fields
     */
    private function check(array &$checks, array $fields, string $fieldKey, string $checkKey, string $expected): void
    {
        $field = $fields[$fieldKey] ?? null;
        $actual = is_array($field) ? trim((string) ($field['value'] ?? '')) : '';
        $expected = trim($expected);
        $available = $actual !== '' && $expected !== '';

        $checks[$checkKey] = [
            'field' => $fieldKey,
            'actual' => $actual !== '' ? $actual : null,
            'expected' => $expected !== '' ? $expected : null,
            'available' => $available,
            'matched' => $available && $this->matches($actual, $expected, $checkKey),
            'confidence' => is_array($field) ? ($field['confidence'] ?? null) : null,
            'source_page' => is_array($field) ? ($field['source_page'] ?? null) : null,
            'warnings' => is_array($field) ? array_values($field['warnings'] ?? []) : [],
        ];
    }

    /** @param  list<string>  $flags */
    private function validity(array $fields, array &$flags): string
    {
        $field = $fields['valid_until'] ?? null;
        $value = is_array($field) ? trim((string) ($field['value'] ?? '')) : '';
        if ($value === '') {
            $flags[] = 'business_permit_validity_unavailable';

            return 'unavailable';
        }

        try {
            $date = CarbonImmutable::parse($value);
        } catch (\Throwable) {
            $flags[] = 'business_permit_validity_unparseable';

            return 'unparseable';
        }

        if ($date->isPast()) {
            $flags[] = 'business_permit_expired';

            return 'expired';
        }

        return 'valid';
    }

    private function matches(string $actual, string $expected, string $kind): bool
    {
        if ($kind === 'address') {
            return OcrVendorMatchScore::compare($actual, $expected, true) === true;
        }

        if ($kind === 'city') {
            return $this->city($actual) === $this->city($expected);
        }

        return OcrVendorMatchScore::compare($actual, $expected) === true
            || ($kind === 'trade_name' && OcrVendorMatchScore::compare($expected, $actual) === true);
    }

    private function city(string $value): string
    {
        return Str::upper(IssuerCity::canonical($value) ?? Str::squish($value));
    }
}
