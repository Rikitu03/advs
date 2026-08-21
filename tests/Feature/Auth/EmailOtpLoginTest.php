<?php

namespace Tests\Feature\Auth;

use App\Models\EmailOtp;
use App\Models\User;
use App\Notifications\EmailLoginCode;
use App\Services\EmailOtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EmailOtpLoginTest extends TestCase
{
    use RefreshDatabase;

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
}
