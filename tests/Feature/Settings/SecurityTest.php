<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Livewire\Volt\Volt;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_passkey_password_confirmation_unlocks_registration_options(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        Volt::test('settings.security')
            ->set('currentPassword', 'password')
            ->call('confirmPasswordForPasskey')
            ->assertHasNoErrors();

        $this->assertIsInt(session('auth.password_confirmed_at'));

        $this->getJson(route('passkey.registration-options'))
            ->assertOk()
            ->assertJsonPath('options.user.name', $user->email);
    }

    public function test_registration_options_require_password_confirmation(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson(route('passkey.registration-options'))
            ->assertStatus(423);
    }

    public function test_invalid_passkey_password_does_not_confirm_the_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        Volt::test('settings.security')
            ->set('currentPassword', 'wrong-password')
            ->call('confirmPasswordForPasskey')
            ->assertHasErrors('currentPassword');

        $this->assertNull(session('auth.password_confirmed_at'));

        $this->getJson(route('passkey.registration-options'))
            ->assertStatus(423);
    }

    public function test_expired_passkey_password_confirmation_is_rejected(): void
    {
        $this->actingAs(User::factory()->create())
            ->withSession(['auth.password_confirmed_at' => now()->subSeconds(config('auth.password_timeout') + 1)->unix()])
            ->getJson(route('passkey.registration-options'))
            ->assertStatus(423);
    }

    public function test_removing_a_passkey_dispatches_the_package_deletion_event(): void
    {
        Event::fake([PasskeyDeleted::class]);

        $user = User::factory()->create();
        $passkey = $user->passkeys()->create([
            'name' => 'Work laptop',
            'credential_id' => 'credential-id',
            'credential' => [],
        ]);

        $this->actingAs($user);

        Volt::test('settings.security')
            ->set('currentPassword', 'password')
            ->call('deletePasskey', $passkey->id)
            ->assertHasNoErrors();

        $this->assertModelMissing($passkey);
        Event::assertDispatched(PasskeyDeleted::class, fn (PasskeyDeleted $event): bool => $event->user->is($user)
            && $event->passkey->is($passkey));
    }
}
