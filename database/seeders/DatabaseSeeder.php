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
            ['Risk Manager', 'risk@advs.test', User::ROLE_RISK_MANAGER],
            ['System Admin', 'admin@advs.test', User::ROLE_ADMIN],
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
    }
}
