<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forces a registered vendor through the business/owner-details step (the second
 * registration step) before signature enrollment, email verification, or any app
 * page.
 *
 * Appended to the `web` group BEFORE EnsureSignatureEnrolled, so business details
 * are collected before the signature. It no-ops for guests, non-vendors, and
 * vendors who have completed their profile, and exempts the business-step routes,
 * logout, and Livewire's own endpoints to avoid a redirect loop.
 */
class EnsureVendorProfileComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User
            && $user->hasRole(User::ROLE_VENDOR)
            && ! $user->hasCompletedVendorProfile()
            && ! $request->routeIs('business.*', 'logout', 'livewire.*', 'default-livewire.*')
        ) {
            return redirect()->route('business.create');
        }

        return $next($request);
    }
}
