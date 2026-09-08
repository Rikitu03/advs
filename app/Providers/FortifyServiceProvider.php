<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\IssueEmailOtpChallenge;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Http\Responses\LoginResponse;
use App\Http\Responses\RegisterResponse;
use App\Models\EmailOtp;
use App\Models\User;
use App\Services\EmailOtpService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\CanonicalizeUsername;
use Laravel\Fortify\Actions\EnsureLoginIsNotThrottled;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Passkeys;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Send freshly registered vendors to the signature-enrollment step.
        $this->app->singleton(RegisterResponseContract::class, RegisterResponse::class);

        // Skip the /dashboard dispatcher hop: log users straight into their role dashboard.
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::authenticateThrough(function (): array {
            return [
                config('fortify.limiters.login') ? null : EnsureLoginIsNotThrottled::class,
                config('fortify.lowercase_usernames') ? CanonicalizeUsername::class : null,
                IssueEmailOtpChallenge::class,
            ];
        });

        Passkeys::authorizeLoginUsing(function (Request $request, $user): bool {
            if (! $user instanceof User || ! $user->is_active || ! $user->hasVerifiedEmail()) {
                throw ValidationException::withMessages([
                    'credential' => [__('This account is not eligible for passkey login.')],
                ]);
            }

            $pending = $request->session()->get(EmailOtpService::SESSION_KEY);

            if (! is_array($pending) || ($pendingUserId = $pending['user_id'] ?? null) === null || ! is_string($pending['challenge_id'] ?? null) || $pending['challenge_id'] === '') {
                throw ValidationException::withMessages([
                    'credential' => [__('Enter your email and password before using a passkey.')],
                ]);
            }

            if ((string) $pendingUserId !== (string) $user->getAuthIdentifier()) {
                Log::warning('auth.passkey_mfa_user_mismatch', [
                    'pending_user_id' => $pendingUserId,
                    'passkey_user_id' => $user->getAuthIdentifier(),
                ]);

                throw ValidationException::withMessages([
                    'credential' => [__('Use a passkey registered to the account that just entered its password.')],
                ]);
            }

            DB::transaction(function () use ($pending, $user): void {
                $otp = EmailOtp::query()
                    ->where('user_id', $user->getKey())
                    ->where('challenge_id', $pending['challenge_id'])
                    ->lockForUpdate()
                    ->first();

                if (! $otp instanceof EmailOtp || ! $otp->isUsable()) {
                    throw ValidationException::withMessages([
                        'credential' => [__('This sign-in challenge has expired. Enter your password again.')],
                    ]);
                }

                $otp->update(['verified_at' => now()]);
            });
            $request->session()->forget([EmailOtpService::SESSION_KEY, 'login.id', 'login.remember']);

            $request->merge(['remember' => false]);

            Log::info('auth.mfa_factor_succeeded', [
                'user_id' => $user->getAuthIdentifier(),
                'factor' => 'passkey',
                'step_up' => true,
            ]);

            return true;
        });

        // Fortify is the auth backend; these callbacks point its view routes at
        // our own Flux-styled Blade forms (which POST back to Fortify endpoints).
        Fortify::loginView(fn () => view('auth.login'));
        Fortify::registerView(fn () => view('auth.register'));
        Fortify::requestPasswordResetLinkView(fn () => view('auth.forgot-password'));
        Fortify::resetPasswordView(fn (Request $request) => view('auth.reset-password', ['request' => $request]));
        Fortify::verifyEmailView(fn () => view('auth.verify-email'));
        Fortify::confirmPasswordView(fn () => view('auth.confirm-password'));
        Event::listen(PasskeyRegistered::class, function (PasskeyRegistered $event): void {
            Log::info('auth.passkey_registered', ['user_id' => $event->user->getAuthIdentifier()]);
        });

        Event::listen(PasskeyDeleted::class, function (PasskeyDeleted $event): void {
            Log::notice('auth.mfa_factor_removed', ['user_id' => $event->user->getAuthIdentifier(), 'factor' => 'passkey']);
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('passkeys', function (Request $request) {
            $credentialId = $request->input('credential.id');

            return Limit::perMinute(10)->by(
                ($credentialId ?: $request->session()->getId()).'|'.$request->ip()
            );
        });

        RateLimiter::for('email-otp-verify', function (Request $request) {
            return Limit::perMinute(10)->by($request->session()->getId().'|'.$request->ip());
        });

        RateLimiter::for('email-otp-resend', function (Request $request) {
            $pending = $request->session()->get(EmailOtpService::SESSION_KEY, []);

            return Limit::perMinute(3)->by(($pending['user_id'] ?? 'guest').'|'.$request->ip());
        });
    }
}
