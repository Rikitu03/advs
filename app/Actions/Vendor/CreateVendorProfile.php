<?php

namespace App\Actions\Vendor;

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;

/**
 * Persists the business/owner-details step of vendor registration: one Vendor
 * profile row plus its one VendorRepresentative, in a single transaction, and
 * stamps the user's vendor_profile_completed_at so the onboarding gate releases.
 */
class CreateVendorProfile
{
    /**
     * @param  array<string, mixed>  $data  Validated business + representative fields.
     */
    public function execute(User $user, array $data): Vendor
    {
        return DB::transaction(function () use ($user, $data): Vendor {
            $entityType = $data['business_entity_type'];

            $vendor = Vendor::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'company_name' => $data['company_name'],
                    'trade_name' => $this->nullable($data, 'trade_name'),
                    'business_entity_type' => $entityType,
                    'tin' => $data['tin'],
                    'dti_registration_number' => Vendor::entityRequiresDti($entityType)
                        ? $this->nullable($data, 'dti_registration_number')
                        : null,
                    'nature_of_business' => $data['nature_of_business'],
                    'business_street' => $data['business_street'],
                    'business_barangay' => $data['business_barangay'],
                    'business_city' => $data['business_city'],
                    'business_province' => $data['business_province'],
                    'business_postal_code' => $data['business_postal_code'],
                    'status' => Vendor::STATUS_PENDING,
                ],
            );

            $vendor->representative()->updateOrCreate([], [
                'first_name' => $data['first_name'],
                'middle_name' => $this->nullable($data, 'middle_name'),
                'last_name' => $data['last_name'],
                'suffix' => $this->nullable($data, 'suffix'),
                'date_of_birth' => $data['date_of_birth'],
                'gender' => $data['gender'],
                'contact_number' => $data['contact_number'],
            ]);

            $user->forceFill(['vendor_profile_completed_at' => now()])->save();

            return $vendor->load('representative');
        });
    }

    /**
     * Return the value for $key, or null when it is absent/blank.
     *
     * @param  array<string, mixed>  $data
     */
    private function nullable(array $data, string $key): ?string
    {
        return filled($data[$key] ?? null) ? (string) $data[$key] : null;
    }
}
