<?php

namespace App\Http\Responses;

use App\Models\User;
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
        if ($request->wantsJson()) {
            return new JsonResponse(['two_factor' => false]);
        }

        /** @var User $user */
        $user = $request->user();

        return redirect()->intended(route($user->dashboardRoute()));
    }
}
