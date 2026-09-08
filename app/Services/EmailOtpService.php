<?php

namespace App\Services;

use App\Models\EmailOtp;
use App\Models\User;
use App\Notifications\EmailLoginCode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class EmailOtpService
{
    public const EXPIRES_IN_MINUTES = 10;

    public const RESEND_COOLDOWN_SECONDS = 120;

    public const SESSION_KEY = 'email_otp.pending';

    public function issue(User $user, bool $enforceCooldown = false, bool $invalidatePrevious = true): EmailOtp
    {
        if ($enforceCooldown && $this->cooldownCached($user)) {
            throw ValidationException::withMessages(['code' => __('Please wait before requesting another code.')]);
        }

        [$otp, $code] = DB::transaction(function () use ($user, $enforceCooldown, $invalidatePrevious): array {
            User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $latestOtp = EmailOtp::query()->where('user_id', $user->getKey())->latest('id')->first();

            if ($enforceCooldown && $latestOtp?->sent_at?->gt(now()->subSeconds(self::RESEND_COOLDOWN_SECONDS))) {
                throw ValidationException::withMessages(['code' => __('Please wait before requesting another code.')]);
            }

            EmailOtp::query()->where('user_id', $user->getKey())->where(function ($query): void {
                $query->whereNotNull('verified_at')->orWhere('expires_at', '<', now());
            })->delete();

            if ($invalidatePrevious) {
                EmailOtp::query()->where('user_id', $user->getKey())->whereNull('verified_at')->update(['verified_at' => now()]);
            }
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $otp = $user->emailOtps()->create([
                'challenge_id' => hash('sha256', Str::random(64)),
                'code' => Hash::make($code),
                'expires_at' => now()->addMinutes(self::EXPIRES_IN_MINUTES),
                'max_attempts' => 5,
                'sent_at' => now(),
            ]);

            return [$otp, $code];
        });
        $otp->setAttribute('plaintext_code', $code);

        Log::info('auth.email_otp_issued', [
            'user_id' => $user->getAuthIdentifier(),
            'otp_id' => $otp->getKey(),
            'challenge_prefix' => substr($otp->challenge_id, 0, 12),
            'expires_at' => $otp->expires_at?->toIso8601String(),
        ]);

        if ($enforceCooldown) {
            $this->cacheCooldown($user);
        }

        return $otp;
    }

    private function cooldownCached(User $user): bool
    {
        try {
            return Cache::has($this->cooldownKey($user));
        } catch (Throwable) {
            return false;
        }
    }

    private function cacheCooldown(User $user): void
    {
        try {
            Cache::put(
                $this->cooldownKey($user),
                true,
                now()->addSeconds(self::RESEND_COOLDOWN_SECONDS),
            );
        } catch (Throwable) {
            // Cache is an optimization only; the database cooldown remains authoritative.
        }
    }

    private function cooldownKey(User $user): string
    {
        return 'auth:email-otp:resend-cooldown:'.$user->getKey();
    }

    public function send(User $user, EmailOtp $otp): void
    {
        try {
            $user->notify(new EmailLoginCode((string) $otp->getAttribute('plaintext_code')));

            Log::info('auth.email_otp_sent', [
                'user_id' => $user->getAuthIdentifier(),
                'otp_id' => $otp->getKey(),
                'challenge_prefix' => substr($otp->challenge_id, 0, 12),
            ]);
        } catch (Throwable $exception) {
            $otp->update(['verified_at' => now()]);
            report($exception);
            throw ValidationException::withMessages(['email' => [__('We could not send your sign-in code. Please try again shortly.')]]);
        }
    }

    public function verify(User $user, string $challengeId, string $code): void
    {
        $otp = $user->emailOtps()->where('challenge_id', $challengeId)->lockForUpdate()->first();
        if (! $otp instanceof EmailOtp || ! $otp->isUsable()) {
            Log::warning('auth.email_otp_unusable', [
                'user_id' => $user->getAuthIdentifier(),
                'challenge_prefix' => substr($challengeId, 0, 12),
                'otp_id' => $otp?->getKey(),
                'attempts' => $otp?->attempts,
                'expired' => $otp?->isExpired(),
                'verified' => $otp?->verified_at !== null,
            ]);

            throw ValidationException::withMessages(['code' => __('This code is invalid or has expired. Request a new code.')]);
        }
        $otp->increment('attempts');
        if (! Hash::check($code, $otp->code)) {
            Log::warning('auth.email_otp_mismatch', [
                'user_id' => $user->getAuthIdentifier(),
                'otp_id' => $otp->getKey(),
                'challenge_prefix' => substr($challengeId, 0, 12),
                'attempts' => $otp->fresh()->attempts,
                'submitted_code_length' => strlen($code),
                'submitted_code_is_numeric' => ctype_digit($code),
            ]);

            if ($otp->fresh()->attempts >= $otp->max_attempts) {
                $otp->update(['verified_at' => now()]);
            }
            throw ValidationException::withMessages(['code' => __('This code is invalid.')]);
        }
        $otp->update(['verified_at' => now()]);

        Log::info('auth.email_otp_verified', [
            'user_id' => $user->getAuthIdentifier(),
            'otp_id' => $otp->getKey(),
            'challenge_prefix' => substr($challengeId, 0, 12),
            'attempts' => $otp->attempts,
        ]);
    }
}
