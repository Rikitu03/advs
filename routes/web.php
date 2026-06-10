<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

/*
|--------------------------------------------------------------------------
| Public
|--------------------------------------------------------------------------
*/
Route::view('/', 'welcome')->name('home');

/*
|--------------------------------------------------------------------------
| Authenticated
|--------------------------------------------------------------------------
| Auth routes (login, register, password reset, email verification, logout)
| are registered by Laravel Fortify. See App\Providers\FortifyServiceProvider.
*/
Route::middleware(['auth', 'verified'])->group(function () {
    // Role dispatcher: Fortify's `home` (/dashboard) lands here and forwards
    // each user to the dashboard for their role.
    Route::get('dashboard', function () {
        /** @var User $user */
        $user = Auth::user();

        return redirect()->route($user->dashboardRoute());
    })->name('dashboard');

    // Vendor portal
    Route::view('vendor/dashboard', 'vendor.dashboard')
        ->middleware('role:vendor')
        ->name('vendor.dashboard');

    // Compliance officer / admin dashboard
    Route::view('admin/dashboard', 'admin.dashboard')
        ->middleware('role:admin,compliance_officer')
        ->name('admin.dashboard');
});

/*
|--------------------------------------------------------------------------
| Account settings (Livewire/Volt)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
    Volt::route('settings/password', 'settings.password')->name('settings.password');
    Volt::route('settings/appearance', 'settings.appearance')->name('settings.appearance');
});
