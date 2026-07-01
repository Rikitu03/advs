<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendorProfileStepTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_factory_vendor_has_a_completed_profile(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($user->hasCompletedVendorProfile());
        $this->assertNotNull($user->vendor_profile_completed_at);
    }

    public function test_without_vendor_profile_state_marks_the_profile_incomplete(): void
    {
        $user = User::factory()->withoutVendorProfile()->create();

        $this->assertFalse($user->hasCompletedVendorProfile());
        $this->assertNull($user->vendor_profile_completed_at);
    }
}
