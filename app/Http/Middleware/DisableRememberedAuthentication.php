<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DisableRememberedAuthentication
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('login.store', 'passkey.login')) {
            $request->merge(['remember' => false]);
        }

        return $next($request);
    }
}
