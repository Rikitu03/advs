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
    Route::middleware('role:vendor')->group(function () {
        Volt::route('vendor/dashboard', 'vendor.dashboard')->name('vendor.dashboard');
        Volt::route('vendor/submit', 'vendor.submit')->name('vendor.submit');
        Volt::route('vendor/submissions', 'vendor.submissions')->name('vendor.submissions');
        Volt::route('vendor/notifications', 'vendor.notifications')->name('vendor.notifications');
        Volt::route('vendor/profile', 'vendor.profile')->name('vendor.profile');
    });

    // Compliance officer / admin dashboard + review workflow.
    // All pages are full-page Volt components backed by the session-scoped
    // DemoStore, so officer actions (decisions, read-states) work end to end.
    Route::middleware('role:admin,compliance_officer')->group(function () {
        Volt::route('admin/dashboard', 'admin.dashboard')->name('admin.dashboard');

        // Pending Submissions queue (ADVS_System_Reference.md §4).
        Volt::route('admin/pending', 'admin.pending')->name('admin.pending');

        // Validation Results drill-down + officer decision for one submission (§6).
        Volt::route('admin/submissions/{submission}', 'admin.submissions.show')->name('admin.submissions.show');

        // Archived Reports — searchable archive of decided submissions (§4).
        Volt::route('admin/archived', 'admin.archived')->name('admin.archived');

        // Vendor Profiles — directory of registered vendors (§4 / §8).
        Volt::route('admin/vendors', 'admin.vendors.index')->name('admin.vendors');
        Volt::route('admin/vendors/{vendor}', 'admin.vendors.show')->name('admin.vendors.show');

        // Risk Logs — chronological audit log of raised flags (§4).
        Volt::route('admin/risk-logs', 'admin.risk-logs')->name('admin.risk-logs');

        // Notifications — officer alert feed (§7).
        Volt::route('admin/notifications', 'admin.notifications')->name('admin.notifications');
    });
});

/*
|--------------------------------------------------------------------------
| Account settings (Livewire/Volt)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth'])->group(function () {
    // Step 2 of vendor registration: enroll a reference signature before email
    // verification. Auth-only (the user is not verified yet) and exempt from the
    // EnsureSignatureEnrolled gate by its route name.
    Volt::route('signature/enroll', 'auth.signature-enroll')->name('signature.create');

    Route::redirect('settings', 'settings/profile');

    Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
    Volt::route('settings/password', 'settings.password')->name('settings.password');
    Volt::route('settings/appearance', 'settings.appearance')->name('settings.appearance');
});
