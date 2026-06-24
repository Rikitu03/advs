<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Policies\AuditLogPolicy;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Audit Trail Viewer — companion controller to the {@see admin.audit}
 * Volt page.
 *
 * Endpoints:
 *   GET  /admin/audit            (handled by the Volt page itself)
 *   GET  /admin/audit/{log}      → render a single row's full JSON payload
 *                                  (used by the "View details" drawer)
 *   GET  /admin/audit/export/csv → stream the current filtered set as a
 *                                  CSV download (admin-only).
 *
 * The Volt page owns the filter form (date / user / action / module /
 * affected record / free-text search). When the user clicks "Export CSV"
 * the page POSTs the filter values into {@see export()}, which re-applies
 * the same query and streams the result. This keeps the controller thin
 * and avoids an extra round-trip through a session-scoped filter bag.
 *
 * Authorization is enforced by both the `role:admin` middleware on the
 * route group and the {@see AuditLogPolicy} inside each
 * method, so a non-admin cannot reach the endpoint even if they bypass
 * the route middleware.
 */
class AuditLogController extends Controller
{
    /**
     * Show a single audit log entry — used by the "View details" drawer.
     */
    public function show(Request $request, AuditLog $audit): View
    {
        $this->authorize('view', $audit);

        $audit->loadMissing('user:id,name,email,role');

        return view('admin.audit.show', [
            'log' => $audit,
        ]);
    }

    /**
     * Stream the currently filtered audit set as CSV. The Volt page sends
     * the same query-string filters it uses for pagination.
     *
     * Output columns mirror the on-screen table so an exported CSV can be
     * dropped straight into the compliance team's review spreadsheet.
     */
    public function export(Request $request): StreamedResponse
    {
        $this->authorize('viewAny', AuditLog::class);

        $filters = AuditLog::query()
            ->search($request->query('q'))
            ->byUser($request->query('user_id'))
            ->ofAction($request->query('action'))
            ->forEntity($request->query('entity_type'), $request->query('entity_id'))
            ->betweenDates($request->query('from'), $request->query('to'));

        // Hard cap so an accidental empty filter doesn't stream the entire
        // table. 50k rows is comfortably enough for a single investigation
        // window and well under the memory ceiling for a streamed CSV.
        $logs = $filters->latest('created_at')->limit(50000)->get();

        $filename = 'audit-trail-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($logs): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'Timestamp',
                'User',
                'Email',
                'Role',
                'Action',
                'Entity',
                'Entity ID',
                'IP Address',
                'Details (JSON)',
            ]);

            foreach ($logs as $log) {
                fputcsv($out, [
                    $log->created_at?->toIso8601String(),
                    $log->user?->name ?? '(deleted user)',
                    $log->user?->email ?? '',
                    $log->user?->role ?? '',
                    $log->action,
                    $log->entity_type ? class_basename($log->entity_type) : '',
                    $log->entity_id,
                    $log->ip_address,
                    $log->details ? json_encode($log->details) : '',
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
