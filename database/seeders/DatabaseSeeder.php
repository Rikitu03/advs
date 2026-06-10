<?php

namespace Database\Seeders;

use App\Models\User;
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
            User::firstOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'role' => $role,
                    'password' => 'password', // hashed automatically via the model cast
                    'email_verified_at' => now(),
                ],
            );
        }

        $this->call([
            DocumentTypeSeeder::class,
            SystemSettingSeeder::class,
        ]);
    }
}
