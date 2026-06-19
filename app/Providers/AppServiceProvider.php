<?php

namespace App\Providers;

use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Support\Facades\Gate;
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
    }
}
