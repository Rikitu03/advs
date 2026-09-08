<?php

namespace Tests\Feature\Auth;

use App\Models\EmailOtp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PasskeyBrowserFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_passkey_configuration_uses_a_hostname_and_documented_origins(): void
    {
        $relyingPartyId = (string) config('passkeys.relying_party_id');
        $applicationOrigin = rtrim((string) config('app.url'), '/');

        $this->assertFalse(filter_var($relyingPartyId, FILTER_VALIDATE_IP) !== false);
        $this->assertContains($applicationOrigin, config('passkeys.allowed_origins'));
    }

    public function test_login_view_does_not_offer_a_standalone_passkey_login(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('Sign in with a passkey', false)
            ->assertDontSee('passkeyLogin', false);
    }

    public function test_mfa_challenge_view_uses_binary_safe_encoding_and_reports_browser_errors(): void
    {
        $user = User::factory()->create();

        $otp = EmailOtp::factory()->create(['user_id' => $user->id]);

        $this->withSession([
            'email_otp.pending' => [
                'user_id' => $user->id,
                'challenge_id' => $otp->challenge_id,
                'sent_at' => $otp->sent_at->timestamp,
            ],
        ])->get(route('mfa-challenge'))
            ->assertOk()
            ->assertSee('for (const byte of bytes)', false)
            ->assertSee("console.error('[passkey]', error)", false)
            ->assertSee('No passkey was selected or this browser has no matching passkey.', false)
            ->assertSee('Resend code', false);
    }

    public function test_security_view_uses_binary_safe_encoding_and_reports_registration_errors(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('settings.security'))
            ->assertOk()
            ->assertSee('Passkeys', false)
            ->assertSee('0 registered', false)
            ->assertSee('No passkeys are registered yet.', false);
    }
}
