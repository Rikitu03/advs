<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $this->get('/register')->assertOk();
    }

    public function test_new_users_can_register_as_vendors(): void
    {
        Notification::fake();

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ]);

        $this->assertAuthenticated();

        // Step 2 of registration: declare business + owner details before signature.
        $response->assertRedirect(route('business.create'));

        $this->assertDatabaseHas('users', [
            'email' => 'test@example.com',
            'role' => User::ROLE_VENDOR,
            'signature_enrolled_at' => null,
            'vendor_profile_completed_at' => null,
        ]);

        // Email verification is the final step: the link must not be sent at
        // registration, only after signature enrollment succeeds.
        Notification::assertNothingSent();
    }

    public function test_registration_requires_matching_password_confirmation(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'Password1!',
            'password_confirmation' => 'different',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertGuest();
    }

    public function test_registration_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'taken@example.com',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ]);

        $response->assertSessionHasErrors('email');
    }

    public function test_registration_rejects_passwords_without_mixed_case_and_symbol(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
    }
}
