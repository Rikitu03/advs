<?php

namespace App\Http\Responses;

use App\Models\User;
use App\Services\EmailOtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

/**
 * After login, send each user straight to their role dashboard instead of
 * bouncing through the /dashboard dispatcher. This removes one full redirect
 * round-trip per login. The role-to-route mapping lives on the User model
 * (dashboardRoute()), so the dispatcher route stays as a fallback.
 */
class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): RedirectResponse|JsonResponse
    {
        if ($request->session()->has(EmailOtpService::SESSION_KEY)) {
            if ($request->wantsJson()) {
                return new JsonResponse(['email_otp' => true], 202);
            }

            return redirect()->route('mfa-challenge');
        }

        if ($request->wantsJson()) {
            return new JsonResponse(['email_otp' => false]);
        }

        /** @var User $user */
        $user = $request->user();

        return redirect()->intended(route($user->dashboardRoute()));
    }
}
