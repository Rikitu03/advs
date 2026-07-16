<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorRepresentative;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Creates one verified account per ADVS role for local testing.
     * All seeded accounts use the password "password".
     */
    public function run(): void
    {
        $accounts = [
            ['Vendor User', 'vendor@advs.test', User::ROLE_VENDOR],
            ['Compliance Officer', 'officer@advs.test', User::ROLE_COMPLIANCE_OFFICER],
            ['Compliance Officer 2', 'officer2@advs.test', User::ROLE_COMPLIANCE_OFFICER],
            ['System Admin', 'admin@advs.test', User::ROLE_ADMIN],
            ['System Admin 2', 'admin2@advs.test', User::ROLE_ADMIN],
        ];

        foreach ($accounts as [$name, $email, $role]) {
            $user = User::firstOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'role' => $role,
                    'password' => 'password', // hashed automatically via the model cast
                    'email_verified_at' => now(),
                ],
            );

            if ($role === User::ROLE_VENDOR) {
                $vendor = Vendor::firstOrCreate(
                    ['user_id' => $user->id],
                    [
                        'company_name' => 'Negofood Demo Trading',
                        'business_entity_type' => 'sole_proprietorship',
                        'tin' => '123-456-789-000',
                        'dti_registration_number' => 'DTI-2026000',
                        'business_permit_number' => 'BP-2026-000',
                        'nature_of_business' => 'Food retail and distribution',
                        'business_street' => '1 Caruncho Ave',
                        'business_barangay' => 'Barangay San Nicolas',
                        'business_city' => 'Pasig',
                        'business_province' => 'Metro Manila',
                        'business_postal_code' => '1600',
                        'status' => Vendor::STATUS_PENDING,
                    ],
                );

                // Seeded vendors skip the onboarding gates so the demo account lands
                // on the dashboard: a complete declared profile + an enrolled
                // signature. (signature_path is a placeholder keyed by vendor id;
                // no real reference image exists for seeded data.)
                $user->forceFill([
                    'signature_path' => "vendor_signatures/vendor{$vendor->id}/seeded-reference.jpg",
                    'signature_enrolled_at' => now(),
                    'vendor_profile_completed_at' => now(),
                ])->save();

                VendorRepresentative::firstOrCreate(
                    ['vendor_id' => $vendor->id],
                    [
                        'first_name' => 'Demo',
                        'last_name' => 'Vendor',
                        'date_of_birth' => '1990-01-01',
                        'gender' => 'male',
                        'contact_number' => '+63 917 000 0000',
                        'government_id_type' => 'national_id',
                        'government_id_number' => '1234-5678-9012',
                        'home_address' => '1 Caruncho Ave, Pasig, Metro Manila',
                    ],
                );
            }
        }

        $this->call([
            DocumentTypeSeeder::class,
            NegofoodSubmissionSeeder::class,
            SystemSettingSeeder::class,
            RetentionPolicySeeder::class,
            MlModelSeeder::class,
            AuditLogSeeder::class,
        ]);
    }
}
