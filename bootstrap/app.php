<?php

use App\Http\Middleware\DisableRememberedAuthentication;
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

        // Both cookies are written client-side as plaintext and read back
        // server-side, so they must skip cookie encryption — otherwise
        // EncryptCookies fails to decrypt them and request()->cookie() reads null.
        //   theme       — layouts read it to guard the initial <html> theme class.
        //   assets_warm — AppServiceProvider reads it to skip already-cached Vite
        //                 preload hints for returning visitors (partials/head).
        $middleware->encryptCookies(except: ['theme', 'assets_warm']);

        // Gate every web route. Order matters: a registered vendor declares
        // business/owner details first, then enrolls a signature, before reaching
        // email verification or the app.
        $middleware->web(append: [
            DisableRememberedAuthentication::class,
            EnsureVendorProfileComplete::class,
            EnsureSignatureEnrolled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->reportable(static function (Throwable $e) {
            if (app()->bound('honeybadger')) {
                app('honeybadger')->notify($e, app('request'));
            }
        });
    })->create();
