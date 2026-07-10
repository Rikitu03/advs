<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_vendor_has_a_complete_profile_and_is_not_gated(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::where('email', 'vendor@advs.test')->firstOrFail();

        $this->assertTrue($user->hasCompletedVendorProfile());
        $this->assertTrue($user->hasEnrolledSignature());
        $this->assertNotNull($user->vendor);
        $this->assertNotNull($user->vendor->representative);

        $this->actingAs($user)->get(route('vendor.dashboard'))->assertOk();
    }
}
