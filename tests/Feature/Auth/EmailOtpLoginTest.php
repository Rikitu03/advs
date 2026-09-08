<?php

namespace Tests\Feature\Auth;

use App\Models\EmailOtp;
use App\Models\User;
use App\Notifications\EmailLoginCode;
use App\Services\EmailOtpService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EmailOtpLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_code_is_encrypted_and_queued_on_the_mail_queue(): void
    {
        $notification = new EmailLoginCode('123456');

        $this->assertInstanceOf(ShouldQueue::class, $notification);
        $this->assertInstanceOf(ShouldBeEncrypted::class, $notification);
        $this->assertSame('mail', $notification->queue);
    }

    public function test_challenge_screen_requires_pending_session(): void
    {
        $this->get(route('mfa-challenge'))->assertNotFound();
    }

    public function test_leading_zero_code_is_accepted(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
        $otp = EmailOtp::query()->firstOrFail();
        $otp->update(['code' => bcrypt('000042')]);
        $this->post(route('mfa-challenge.verify'), ['code' => '000042'])->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public function test_expired_code_cannot_authenticate(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
        $otp = EmailOtp::query()->firstOrFail();
        $otp->update(['expires_at' => now()->subMinute(), 'code' => bcrypt('123456')]);
        $this->post(route('mfa-challenge.verify'), ['code' => '123456'])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_resending_a_code_invalidates_the_previous_code(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
        Notification::assertSentTo($user, EmailLoginCode::class);
        $firstCode = '111111';
        EmailOtp::query()->firstOrFail()->update(['code' => bcrypt($firstCode)]);

        $this->post(route('mfa-challenge.resend'))->assertSessionHasErrors('code');

        $this->travel(EmailOtpService::RESEND_COOLDOWN_SECONDS + 1)->seconds();
        $this->post(route('mfa-challenge.resend'))
            ->assertRedirect();
        $codes = Notification::sent($user, EmailLoginCode::class)->values();
        $secondCode = $codes->last()->code;

        $this->post(route('mfa-challenge.verify'), ['code' => $firstCode])
            ->assertSessionHasErrors('code');
        $this->assertGuest();

        $this->post(route('mfa-challenge.verify'), ['code' => $secondCode])
            ->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public function test_new_code_expires_after_ten_minutes(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
        $otp = EmailOtp::query()->firstOrFail();
        $emailedCode = null;

        Notification::assertSentTo($user, EmailLoginCode::class, function (EmailLoginCode $notification) use (&$emailedCode): bool {
            $emailedCode = $notification->code;

            return true;
        });

        $this->assertTrue($otp->expires_at->equalTo($otp->sent_at->copy()->addMinutes(EmailOtpService::EXPIRES_IN_MINUTES)));
        $this->assertIsString($emailedCode);

        $this->travel((EmailOtpService::EXPIRES_IN_MINUTES * 60) + 1)->seconds();
        $this->post(route('mfa-challenge.verify'), ['code' => $emailedCode])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_exhausted_code_cannot_bypass_resend_cooldown(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
        $otp = EmailOtp::query()->firstOrFail();
        $otp->update(['attempts' => $otp->max_attempts, 'code' => bcrypt('123456')]);

        $this->post(route('mfa-challenge.resend'))->assertSessionHasErrors('code');
        $this->assertDatabaseCount('email_otps', 1);
    }

    public function test_pending_session_records_code_sent_at(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
        $otp = EmailOtp::query()->firstOrFail();

        $this->assertSame($otp->sent_at->timestamp, session('email_otp.pending.sent_at'));
    }

    public function test_challenge_screen_renders_digit_boxes_and_resend_cooldown(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        $response = $this->get(route('mfa-challenge'));
        $html = $response->getContent();

        $response->assertOk();
        $response->assertSee('Resend Code', false);
        $response->assertSee('mfaChallenge(', false);

        $this->assertSame(6, preg_match_all('/x-ref="code-[0-5]"/', $html), 'Six individual digit boxes are rendered.');
        $this->assertSame(6, preg_match_all('/autocomplete="one-time-code"/', $html), 'Every digit box opts into one-time-code autofill.');
        $this->assertSame(1, preg_match_all('/name="code"/', $html), 'The digits are assembled into a single code field on submit.');
        $this->assertStringContainsString('x-bind:disabled="cooldownRemaining > 0"', $html);
        $this->assertStringContainsString("x-bind:class=\"{ 'cursor-not-allowed opacity-40': cooldownRemaining > 0 }\"", $html);
        $this->assertSame(1, preg_match('/<button[^>]*x-bind:disabled="cooldownRemaining > 0"[^>]*>(.*?)<\/button>/s', $html, $resendButton));
        $this->assertStringContainsString('Resend Code', $resendButton[1]);
        $this->assertStringNotContainsString('data-flux-loading-indicator', $resendButton[1]);
        $this->assertStringContainsString('aria-live="polite"', $html);
        $this->assertStringNotContainsString(__('Email verification code'), $html);
    }

    public function test_invalid_code_preserves_submitted_digits_for_retry(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        $this->post(route('mfa-challenge.verify'), ['code' => '000000'])
            ->assertSessionHasErrors('code')
            ->assertSessionHas('_old_input.code', '000000');

        $html = $this->get(route('mfa-challenge'))->getContent();

        $this->assertSame(6, preg_match_all('/value="0"/', $html), 'Each visible digit input keeps the submitted value after an invalid code.');
    }
}
