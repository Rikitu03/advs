<?php

namespace App\Providers;

use App\Models\RetentionPolicy;
use App\Models\SystemSetting;
use App\Models\User;
use App\Policies\RetentionPolicyPolicy;
use Illuminate\Support\Facades\Auth;
use App\Policies\SystemSettingPolicy;
use App\Policies\UserPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Map the User model to its policy so $user->can('delete', $otherUser)
        // and Gate::authorize('viewAny', User::class) work everywhere.
        Gate::policy(User::class, UserPolicy::class);

        // System settings (admin-only thresholds/parameters) — see
        // ADVS_System_Reference.md §9 and SystemSettingsService.
        Gate::policy(SystemSetting::class, SystemSettingPolicy::class);

        // Data retention policies are admin-only configuration records.
        Gate::policy(RetentionPolicy::class, RetentionPolicyPolicy::class);

        // "Preload once": emit Vite preload hints only for visitors who haven't
        // warmed their cache yet. Returning visitors (assets_warm cookie present)
        // already have the entry chunks cached, so suppress the redundant hints.
        // The cookie is set client-side after load (partials/head) and exempted
        // from encryption in bootstrap/app.php, exactly like the `theme` cookie.
        Vite::usePreloadTagAttributes(
            fn (): array|false => request()->cookie('assets_warm') ? false : []
        );
    }
}
