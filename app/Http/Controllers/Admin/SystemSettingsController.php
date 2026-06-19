<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Services\SystemSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Thin controller for the admin System Settings pages. Mirrors the pattern
 * used by {@see UserController}:
 *
 * - `index()` renders the settings overview (a Volt page does the live edit).
 * - `reset()` resets one key to its schema default.
 *
 * The bulk "Save changes" submit goes through the Volt component's
 * `save()` action which uses {@see UpdateSystemSettingsRequest} for
 * validation and {@see SystemSettingsService::updateMany()} for persistence.
 */
class SystemSettingsController extends Controller
{
    public function __construct(protected SystemSettingsService $settings) {}

    /**
     * Render the settings overview. The actual interactive UI is the
     * admin.settings.index Volt component; this controller just authorises
     * the request and returns the Blade shell.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', SystemSetting::class);

        return view('admin.settings.index');
    }

    /**
     * Reset a single setting to its schema-defined default. Called by the
     * "Reset" button on each row.
     */
    public function reset(Request $request, string $key): RedirectResponse
    {
        $this->authorize('update', SystemSetting::class);

        try {
            $this->settings->reset($key, $request->user('web'));
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['key' => $e->getMessage()]);
        }

        return back()->with('status', "Setting \"{$key}\" reset to its default value.");
    }
}
