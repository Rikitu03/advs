<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forces a registered vendor through the signature-enrollment step (the second
 * registration step) before they can reach email verification or any app page.
 *
 * Appended to the `web` group, so it runs on every web route. It no-ops for
 * guests, non-vendors (officers/admins), and already-enrolled vendors, and
 * exempts the signature routes and logout to avoid a redirect loop.
 */
class EnsureSignatureEnrolled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Livewire's own endpoints (component updates / file uploads) must stay
        // reachable, or the signature page itself could not submit. Livewire v4
        // names the update route "default-livewire.update", so both prefixes
        // must be exempted.
        // Runs after EnsureVendorProfileComplete; only fires once the business profile is complete.
        if ($user instanceof User
            && $user->hasRole(User::ROLE_VENDOR)
            && $user->hasCompletedVendorProfile()
            && ! $user->hasEnrolledSignature()
            && ! $request->routeIs('signature.*', 'logout', 'livewire.*', 'default-livewire.*')
        ) {
            return redirect()->route('signature.create');
        }

        return $next($request);
    }
}
