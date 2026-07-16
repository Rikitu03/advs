<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\SystemSettingsController;
use App\Http\Controllers\Admin\UserController;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
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
        Route::get('vendor/documents/{document}', function (Document $document) {
            $vendor = Auth::user()?->vendor;

            abort_unless($vendor !== null && $document->vendor_id === $vendor->id, 404);

            $path = ltrim($document->file_path, '/');

            abort_unless(Storage::exists($path), 404);

            return Storage::response($path, $document->original_filename, [
                'Content-Type' => $document->mime_type,
            ]);
        })->name('vendor.documents.show');
        Volt::route('vendor/notifications', 'vendor.notifications')->name('vendor.notifications');
        Volt::route('vendor/profile', 'vendor.profile')->name('vendor.profile');
    });

    // Compliance officer / admin dashboard + review workflow. All pages are
    // full-page Volt components backed by Eloquent; officer decisions persist
    // to the database and cascade to the vendor's accreditation status.
    Route::middleware('role:admin,compliance_officer')->group(function () {
        Volt::route('admin/dashboard', 'admin.dashboard')->name('admin.dashboard');

        // Pending Submissions queue (ADVS_System_Reference.md §4).
        Volt::route('admin/pending', 'admin.pending')->name('admin.pending');

        // Validation Results drill-down + officer decision for one submission (§6).
        Volt::route('admin/submissions/{submission}', 'admin.submissions.show')->name('admin.submissions.show');

        // Original uploaded file for review — streamed from private storage;
        // reaching here already requires the admin/compliance_officer role.
        Route::get('admin/documents/{document}', function (Document $document) {
            $path = ltrim($document->file_path, '/');

            abort_unless(Storage::exists($path), 404);

            return Storage::response($path, $document->original_filename, [
                'Content-Type' => $document->mime_type,
            ]);
        })->name('admin.documents.show');

        // Archived Reports — searchable archive of decided submissions (§4).
        Volt::route('admin/archived', 'admin.archived')->name('admin.archived');

        // Vendor Profiles — directory of registered vendors (§4 / §8).
        Volt::route('admin/vendors', 'admin.vendors.index')->name('admin.vendors');
        Volt::route('admin/vendors/{vendor}', 'admin.vendors.show')->name('admin.vendors.show');

        // Risk Logs — chronological audit log of raised flags (§4).
        Volt::route('admin/risk-logs', 'admin.risk-logs')->name('admin.risk-logs');

        // Notifications — officer alert feed (§7).
        Volt::route('admin/notifications', 'admin.notifications')->name('admin.notifications');

        // User Management — admin-only user CRUD (§3 / §5).
        // All routes are gated by `role:admin` AND the UserPolicy inside the
        // controller so an admin cannot delete/demote themselves.
        Route::middleware('role:admin')->prefix('admin/users')->name('admin.users.')->group(function () {
            Route::get('/', [UserController::class, 'index'])->name('index');
            Route::get('create', [UserController::class, 'create'])->name('create');
            Route::post('/', [UserController::class, 'store'])->name('store');
            Route::get('{user}/edit', [UserController::class, 'edit'])->name('edit');
            Route::put('{user}', [UserController::class, 'update'])->name('update');
            Route::patch('{user}/role', [UserController::class, 'updateRole'])->name('update-role');
            Route::delete('{user}', [UserController::class, 'destroy'])->name('destroy');
        });

        // System Settings — admin-only threshold / parameter tuning UI.
        // All routes are gated by `role:admin` AND the SystemSettingPolicy
        // inside the controller/Volt component.
        Route::middleware('role:admin')->prefix('admin/settings')->name('admin.settings.')->group(function () {
            Volt::route('/', 'admin.settings.index')->name('index');
            Route::post('{key}/reset', [SystemSettingsController::class, 'reset'])->name('reset');
        });

        // Data Retention Configuration — admin-only policy management UI.
        // The Volt page owns the live editing experience; the model/policy
        // pair keeps the records secure and future-proof for more rules.
        Route::middleware('role:admin')->prefix('admin/retention')->name('admin.retention.')->group(function () {
            Volt::route('/', 'admin.retention.index')->name('index');
        });

        // Audit Trail — admin-only viewer for the append-only audit log.
        // The Volt page owns the index, filtering, and search; the
        // controller serves the detail drill-down and CSV export.
        Route::middleware('role:admin')->prefix('admin/audit')->name('admin.audit.')->group(function () {
            Volt::route('/', 'admin.audit.index')->name('index');
            Route::get('export', [AuditLogController::class, 'export'])->name('export');
            Route::get('{audit}', [AuditLogController::class, 'show'])->name('show');
        });

        // ML Model Management — admin-only catalogue of the four ML weight
        // files the pipeline consumes (ResNet-50, YOLOv8, Siamese, EfficientNet).
        // The Volt page handles filtering, sync, and per-row edits. Authorization
        // is also enforced inside the component via the MlModelPolicy.
        Route::middleware('role:admin')->prefix('admin/models')->name('admin.models.')->group(function () {
            Volt::route('/', 'admin.models.index')->name('index');
        });
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

    // Step 2 of vendor registration: declare business + owner details before
    // signature enrollment. Auth-only (the user is not verified yet) and exempt
    // from the EnsureVendorProfileComplete gate by its route name.
    Volt::route('register/business', 'auth.business-details')->name('business.create');

    Route::redirect('settings', 'settings/profile');

    Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
    Volt::route('settings/password', 'settings.password')->name('settings.password');
    Volt::route('settings/preference', 'settings.preference')->name('settings.preference');
    Volt::route('settings/appearance', 'settings.appearance')->name('settings.appearance');
});
