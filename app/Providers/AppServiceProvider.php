<?php

namespace App\Providers;

use App\Models\RetentionPolicy;
use App\Models\SystemSetting;
use App\Models\User;
use App\Policies\RetentionPolicyPolicy;
use App\Policies\SystemSettingPolicy;
use App\Policies\UserPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
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
     * Public base URL for the Render deployment. Queued mail (verification,
     * password reset) is rendered by the worker with no HTTP request context,
     * so generated links fall back to config('app.url') — force the production
     * base here so those links always point at the live host over https.
     */
    private const PRODUCTION_ROOT_URL = 'https://advs.onrender.com';

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment('production')) {
            URL::forceRootUrl(self::PRODUCTION_ROOT_URL);
            URL::forceScheme('https');
        }

        // Map the User model to its policy so $user->can('delete', $otherUser)
        // and Gate::authorize('viewAny', User::class) work everywhere.
        Gate::policy(User::class, UserPolicy::class);

        // System settings (admin-only thresholds/parameters) — see
        // ADVS_System_Reference.md §9 and SystemSettingsService.
        Gate::policy(SystemSetting::class, SystemSettingPolicy::class);

        // Data retention policies are admin-only configuration records.
        Gate::policy(RetentionPolicy::class, RetentionPolicyPolicy::class);
    }
}
