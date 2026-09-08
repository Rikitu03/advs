<?php

namespace Tests\Feature\Auth;

use App\Models\EmailOtp;
use App\Models\User;
use App\Notifications\EmailLoginCode;
use App\Services\EmailOtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Passkeys;
use Tests\TestCase;

class MfaEnforcementTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_login_requires_email_otp_before_authentication(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('mfa-challenge'));
        $this->assertGuest();
        $this->assertSame($user->id, session('email_otp.pending.user_id'));
        $this->assertDatabaseCount('email_otps', 1);
    }

    public function test_invalid_password_does_not_issue_otp(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertDatabaseCount('email_otps', 0);
    }

    public function test_valid_email_otp_completes_login_once(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
        $otp = EmailOtp::query()->firstOrFail();
        $emailedCode = null;

        Notification::assertSentTo($user, EmailLoginCode::class, function (EmailLoginCode $notification) use (&$emailedCode): bool {
            $emailedCode = $notification->code;

            return preg_match('/\A[0-9]{6}\z/', $emailedCode) === 1;
        });

        $this->assertIsString($emailedCode);
        $this->post(route('mfa-challenge.verify'), ['code' => $emailedCode])->assertRedirect();
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($otp->fresh()->verified_at);
    }

    public function test_invalid_code_does_not_authenticate(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
        $this->post(route('mfa-challenge.verify'), ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_remember_flag_does_not_bypass_email_otp(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password', 'remember' => '1'])->assertRedirect(route('mfa-challenge'));
        $this->assertGuest();
    }

    public function test_passkey_login_requires_a_pending_password_challenge(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $request = $this->passkeyRequest();
        $passkey = (new Passkey)->setRelation('user', $user);

        $this->expectException(ValidationException::class);

        Passkeys::allowsLogin($request, $passkey);
    }

    public function test_passkey_login_rejects_an_expired_pending_challenge(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $otp = EmailOtp::factory()->expired()->create(['user_id' => $user->id]);
        $request = $this->passkeyRequest([
            'user_id' => $user->id,
            'challenge_id' => $otp->challenge_id,
            'sent_at' => $otp->sent_at->timestamp,
        ]);
        $passkey = (new Passkey)->setRelation('user', $user);

        $this->expectException(ValidationException::class);

        Passkeys::allowsLogin($request, $passkey);
    }

    public function test_passkey_login_consumes_the_matching_pending_challenge(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $otp = EmailOtp::factory()->create(['user_id' => $user->id]);
        $request = $this->passkeyRequest([
            'user_id' => $user->id,
            'challenge_id' => $otp->challenge_id,
            'sent_at' => $otp->sent_at->timestamp,
        ]);
        $passkey = (new Passkey)->setRelation('user', $user);

        $this->assertTrue(Passkeys::allowsLogin($request, $passkey));
        $this->assertNotNull($otp->fresh()->verified_at);
        $this->assertFalse($request->session()->has(EmailOtpService::SESSION_KEY));
        $this->assertFalse($request->boolean('remember'));
    }

    /**
     * @param  array<string, int|string>  $pending
     */
    private function passkeyRequest(array $pending = []): Request
    {
        $request = Request::create('/passkeys/login', 'POST', ['remember' => '1']);
        $session = app('session')->driver();
        $session->start();

        if ($pending !== []) {
            $session->put(EmailOtpService::SESSION_KEY, $pending);
        }

        $request->setLaravelSession($session);

        return $request;
    }
}
