<?php

namespace Tests\Feature\Database;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminUserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_the_requested_active_verified_admin_account_idempotently(): void
    {
        $this->seed(AdminUserSeeder::class);
        $this->seed(AdminUserSeeder::class);

        $admin = User::query()
            ->where('email', 'jasonjayrecto@gmail.com')
            ->firstOrFail();

        $this->assertSame('Jason Jay Recto', $admin->name);
        $this->assertSame(User::ROLE_ADMIN, $admin->role);
        $this->assertTrue((bool) $admin->is_active);
        $this->assertNotNull($admin->email_verified_at);
        $this->assertTrue(Hash::check('12345678Jasongpogi!', $admin->password));
        $this->assertSame(
            1,
            User::query()->where('email', 'jasonjayrecto@gmail.com')->count(),
        );
    }
}
