<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Services\EmailOtpService;
use Closure;
use Illuminate\Auth\Events\Failed;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;

class IssueEmailOtpChallenge
{
    public function __construct(private readonly EmailOtpService $otps) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $user = User::query()->where('email', $request->string(Fortify::username())->lower()->toString())->first();

        if (! $user instanceof User || ! $user->is_active || ! $user->hasVerifiedEmail() || ! Hash::check($request->string('password')->toString(), $user->password)) {
            event(new Failed(config('fortify.guard'), null, $request->only(Fortify::username(), 'password')));

            throw ValidationException::withMessages([Fortify::username() => [trans('auth.failed')]]);
        }

        if (Hash::needsRehash($user->password)) {
            $user->forceFill(['password' => $request->string('password')->toString()])->save();
        }

        $otp = $this->otps->issue($user);
        $this->otps->send($user, $otp);
        $request->session()->regenerate();
        $request->session()->put(EmailOtpService::SESSION_KEY, [
            'user_id' => $user->getKey(),
            'challenge_id' => $otp->challenge_id,
            'sent_at' => $otp->sent_at?->timestamp ?? now()->timestamp,
        ]);
        $request->session()->forget(['login.id', 'login.remember']);

        return $next($request);
    }
}
