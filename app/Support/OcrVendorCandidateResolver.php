<?php

namespace App\Support;

use App\Models\Vendor;
use App\Models\VendorRepresentative;

final class OcrVendorCandidateResolver
{
    /**
     * Resolve the declared registration values that can be compared to one OCR
     * field in the officer review. This intentionally has no role in aggregate
     * OCR text scoring or document risk calculation.
     *
     * @return list<array{key: string, label: string, value: string, is_address: bool}>
     */
    public function resolve(string $fieldKey, ?Vendor $vendor): array
    {
        if ($vendor === null) {
            return [];
        }

        return match ($fieldKey) {
            'registered_name' => $this->vendorAttributes($vendor, ['company_name']),
            'business_name' => $this->vendorAttributes($vendor, ['company_name', 'trade_name']),
            'trade_name' => $this->vendorAttributes($vendor, ['trade_name']),
            'tin' => $this->vendorAttributes($vendor, ['tin']),
            'ocn' => $this->vendorAttributes($vendor, ['registration_number']),
            'certificate_no' => $this->vendorAttributes($vendor, ['dti_registration_number', 'registration_number']),
            'trn_no', 'dti_registration_number' => $this->vendorAttributes($vendor, ['dti_registration_number']),
            'sec_registration_number' => $this->vendorAttributes($vendor, ['sec_registration_number']),
            'permit_no', 'business_permit_number' => $this->vendorAttributes($vendor, ['business_permit_number']),
            'registration_number' => $this->vendorAttributes($vendor, ['registration_number']),
            'owner_representative_name' => $this->representativeNames($vendor->representative),
            'name_of_proprietor', 'business_owner' => [
                ...$this->representativeNames($vendor->representative),
                ...$this->vendorAttributes($vendor, ['company_name']),
            ],
            'business_location', 'business_address', 'registered_address' => $this->vendorAttributes(
                $vendor,
                ['business_address'],
                true,
            ),
            'kind_of_business', 'nature_of_business', 'line_of_business' => $this->vendorAttributes($vendor, ['nature_of_business']),
            'city_issued', 'business_city' => $this->vendorAttributes($vendor, ['business_city']),
            default => [],
        };
    }

    /**
     * @param  list<string>  $attributes
     * @return list<array{key: string, label: string, value: string, is_address: bool}>
     */
    private function vendorAttributes(Vendor $vendor, array $attributes, bool $isAddress = false): array
    {
        return collect($attributes)
            ->map(function (string $attribute) use ($vendor, $isAddress): ?array {
                $value = trim((string) $vendor->getAttribute($attribute));

                if ($value === '') {
                    return null;
                }

                return [
                    'key' => $attribute,
                    'label' => $this->attributeLabel($attribute),
                    'value' => $value,
                    'is_address' => $isAddress,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return list<array{key: string, label: string, value: string, is_address: bool}>
     */
    private function representativeNames(?VendorRepresentative $representative): array
    {
        if ($representative === null) {
            return [];
        }

        $firstName = trim($representative->first_name);
        $middleName = trim((string) $representative->middle_name);
        $lastName = trim($representative->last_name);
        $suffix = trim((string) $representative->suffix);

        $variants = [
            'representative.full_name' => $this->joinNameParts([$firstName, $middleName, $lastName, $suffix]),
            'representative.first_last' => $this->joinNameParts([$firstName, $lastName]),
            'representative.surname_first' => $this->joinNameParts([$lastName, $firstName]),
            'representative.surname_first_middle' => $this->joinNameParts([$lastName, $firstName, $middleName]),
            'representative.surname_first_full' => $this->joinNameParts([$lastName, $firstName, $middleName, $suffix]),
        ];

        return collect($variants)
            ->filter(fn (string $value): bool => $value !== '')
            ->unique()
            ->map(fn (string $value, string $key): array => [
                'key' => $key,
                'label' => 'Representative name',
                'value' => $value,
                'is_address' => false,
            ])
            ->values()
            ->all();
    }

    /** @param  list<string>  $parts */
    private function joinNameParts(array $parts): string
    {
        return implode(' ', array_filter($parts));
    }

    private function attributeLabel(string $attribute): string
    {
        return match ($attribute) {
            'tin' => 'TIN',
            'dti_registration_number' => 'DTI Registration Number',
            'sec_registration_number' => 'SEC Registration Number',
            'business_permit_number' => 'Business Permit Number',
            default => ucfirst(str_replace('_', ' ', $attribute)),
        };
    }
}
