<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'jasonjayrecto@gmail.com'],
            [
                'name' => 'Jason Jay Recto',
                'role' => User::ROLE_ADMIN,
                'is_active' => true,
                'password' => '12345678Jasongpogi!',
                'email_verified_at' => now(),
            ],
        );
    }
}
