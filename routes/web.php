<?php

use App\Models\User;
use App\Support\DemoData;
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

    // Compliance officer / admin dashboard + review workflow
    Route::middleware('role:admin,compliance_officer')->group(function () {
        Route::view('admin/dashboard', 'admin.dashboard')->name('admin.dashboard');

        // Pending Submissions queue (ADVS_System_Reference.md §4).
        Route::view('admin/pending', 'admin.pending')->name('admin.pending');

        // Validation Results drill-down for one submission (§6).
        Route::get('admin/submissions/{submission}', function (string $submission) {
            $data = DemoData::findSubmission($submission);

            abort_if($data === null, 404);

            return view('admin.submissions.show', ['submission' => $data]);
        })->name('admin.submissions.show');

        // Archived Reports — searchable archive of decided submissions (§4).
        Route::view('admin/archived', 'admin.archived')->name('admin.archived');

        // Vendor Profiles — directory of registered vendors (§4 / §8).
        Route::view('admin/vendors', 'admin.vendors.index')->name('admin.vendors');

        Route::get('admin/vendors/{vendor}', function (string $vendor) {
            $data = DemoData::findVendor($vendor);

            abort_if($data === null, 404);

            return view('admin.vendors.show', ['vendor' => $data]);
        })->name('admin.vendors.show');

        // Risk Logs — chronological audit log of raised flags (§4).
        Route::view('admin/risk-logs', 'admin.risk-logs')->name('admin.risk-logs');

        // Notifications — officer alert feed (§7).
        Route::view('admin/notifications', 'admin.notifications')->name('admin.notifications');
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
