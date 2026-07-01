<?php

use App\Http\Middleware\EnsureSignatureEnrolled;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\EnsureVendorProfileComplete;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role' => EnsureUserHasRole::class,
        ]);

        // The theme preference is written client-side as a plaintext cookie so
        // the layouts can read it to guard the initial <html> theme class.
        // Exclude it from cookie encryption, otherwise EncryptCookies fails to
        // decrypt it and request()->cookie('theme') reads null.
        $middleware->encryptCookies(except: ['theme']);

        // Gate every web route. Order matters: a registered vendor declares
        // business/owner details first, then enrolls a signature, before reaching
        // email verification or the app.
        $middleware->web(append: [
            EnsureVendorProfileComplete::class,
            EnsureSignatureEnrolled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
