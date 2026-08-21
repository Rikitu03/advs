<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\VerifyEmailOtpRequest;
use App\Models\EmailOtp;
use App\Models\User;
use App\Services\EmailOtpService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class MfaChallengeController extends Controller
{
    public function show(Request $request): View
    {
        abort_unless($request->session()->has(EmailOtpService::SESSION_KEY) && ! $request->user(), 404);

        return view('auth.mfa-challenge');
    }

    public function verify(VerifyEmailOtpRequest $request, EmailOtpService $otps): RedirectResponse
    {
        $pending = $request->session()->get(EmailOtpService::SESSION_KEY);
        abort_unless(is_array($pending), 404);
        $user = User::query()->find($pending['user_id'] ?? null);
        abort_unless($user instanceof User && $user->is_active && $user->hasVerifiedEmail(), 404);
        DB::transaction(fn () => $otps->verify($user, (string) $pending['challenge_id'], (string) $request->validated()['code']));
        Auth::login($user, false);
        $request->session()->forget(EmailOtpService::SESSION_KEY);
        $request->session()->regenerate();

        return redirect()->intended(route($user->dashboardRoute()));
    }

    public function resend(Request $request, EmailOtpService $otps): RedirectResponse
    {
        $pending = $request->session()->get(EmailOtpService::SESSION_KEY);
        abort_unless(is_array($pending), 404);
        $user = User::query()->find($pending['user_id'] ?? null);
        abort_unless($user instanceof User && ! $request->user(), 404);
        $previousChallengeId = (string) ($pending['challenge_id'] ?? '');
        $otp = $otps->issue($user, true, false);
        $otps->send($user, $otp);
        EmailOtp::query()->where('user_id', $user->getKey())->where('challenge_id', $previousChallengeId)->whereNull('verified_at')->update(['verified_at' => now()]);
        $pending['challenge_id'] = $otp->challenge_id;
        $pending['sent_at'] = $otp->sent_at?->timestamp ?? now()->timestamp;
        $request->session()->put(EmailOtpService::SESSION_KEY, $pending);

        return back()->with('status', __('A new code was sent to your email address.'));
    }
}
