<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

/**
 * Session-backed mutable overlay on top of the static {@see DemoData} set.
 *
 * It lets the officer-workflow prototype behave like a real system without a
 * database: approve/reject decisions, notification read-state, and the vendor
 * accreditation side effects are recorded in the session and overlaid onto the
 * demo submissions, so every tab (Pending, Archived, Vendors, Notifications,
 * Home KPIs) stays internally consistent for the duration of the demo session.
 *
 * Replace with Eloquent-backed services when the document pipeline lands
 * (Phase 7–8). Behavior mirrors ADVS_System_Reference.md §5 Stage 6 (officer
 * decision), §5 4a/4b (reference enrollment on first approval), and §7
 * (notification events).
 */
class DemoStore
{
    private const DECISIONS_KEY = 'demo.decisions';

    private const NOTIFICATION_READS_KEY = 'demo.notification_reads';

    /**
     * Every submission with session decisions overlaid.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function submissions(): Collection
    {
        $decisions = self::decisions();

        return DemoData::submissions()->map(function (array $s) use ($decisions): array {
            $decision = $decisions[$s['id']] ?? null;

            if ($decision === null) {
                return $s;
            }

            $s['status'] = $decision['decision'];
            $s['decision'] = $decision['decision'];
            $s['reviewed_by'] = $decision['by'];
            $s['reviewed_at'] = Carbon::parse($decision['at']);
            $s['review_comments'] = $decision['comments'] !== '' ? $decision['comments'] : null;

            return $s;
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findSubmission(int|string $id): ?array
    {
        return self::submissions()->first(
            fn (array $s): bool => (string) $s['id'] === (string) $id || $s['ref'] === $id
        );
    }

    /**
     * Pending-review queue, highest risk first (§5 Stage 6).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function pendingSubmissions(): Collection
    {
        return self::submissions()
            ->where('status', 'pending_review')
            ->sortByDesc('risk_score')
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public static function urgentSubmissions(int $limit = 5): Collection
    {
        return self::pendingSubmissions()->take($limit);
    }

    /**
     * Decided submissions, newest decision first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function archivedReports(): Collection
    {
        return self::submissions()
            ->whereIn('status', ['approved', 'rejected'])
            ->sortByDesc('reviewed_at')
            ->values();
    }

    /**
     * Record the officer's final approve/reject call (§5 Stage 6).
     */
    public static function decide(int $id, string $decision, string $comments = ''): void
    {
        $decisions = self::decisions();

        $decisions[$id] = [
            'decision' => $decision,
            'comments' => trim($comments),
            'by' => Auth::user()?->name ?? 'Compliance Officer',
            'at' => Carbon::now()->toIso8601String(),
        ];

        Session::put(self::DECISIONS_KEY, $decisions);
    }

    public static function undoDecision(int $id): void
    {
        $decisions = self::decisions();
        unset($decisions[$id]);

        Session::put(self::DECISIONS_KEY, $decisions);
    }

    /**
     * Home KPI cards, computed live from the overlaid submissions.
     *
     * @return array<string, int|string>
     */
    public static function kpis(): array
    {
        $pending = self::pendingSubmissions();
        $archived = self::archivedReports();
        $approvalRate = $archived->isEmpty()
            ? '—'
            : round($archived->where('decision', 'approved')->count() / $archived->count() * 100).'%';

        return [
            'pending' => $pending->count(),
            'flagged_today' => $pending
                ->where('submitted_at', '>=', Carbon::now()->startOfDay())
                ->filter(fn (array $s): bool => count($s['flags']) > 0)
                ->count(),
            'high_risk' => $pending->where('risk_level', 'high')->count(),
            'approval_rate' => $approvalRate,
        ];
    }

    /**
     * Activity feed: pipeline alerts merged with this session's decisions.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function activity(int $limit = 6): Collection
    {
        $decisionEvents = collect(self::decisions())->map(function (array $d, int $id): ?array {
            $s = DemoData::findSubmission($id);

            if ($s === null) {
                return null;
            }

            $approved = $d['decision'] === 'approved';

            return [
                'type' => 'decision_made',
                'icon' => $approved ? 'check-circle' : 'x-circle',
                'color' => $approved ? 'emerald' : 'rose',
                'text' => sprintf('You %s %s (%s).', $approved ? 'approved' : 'rejected', $s['ref'], $s['company']),
                'at' => Carbon::parse($d['at']),
            ];
        })->filter()->values();

        return DemoData::activity()
            ->concat($decisionEvents)
            ->sortByDesc('at')
            ->take($limit)
            ->values();
    }

    /**
     * Vendor directory with accreditation side effects of session decisions:
     * the latest decision on a vendor's submission updates its status, and a
     * first approval enrolls the reference signature/stamp (§5 4a/4b).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function vendors(): Collection
    {
        $latestDecisionByCompany = self::archivedReports()
            ->sortByDesc('reviewed_at')
            ->unique('company')
            ->keyBy('company');

        return DemoData::vendors()->map(function (array $v) use ($latestDecisionByCompany): array {
            $decided = $latestDecisionByCompany->get($v['company']);

            if ($decided === null) {
                return $v;
            }

            $v['status'] = $decided['decision'];
            $v['risk_score'] = $decided['risk_score'];

            if ($decided['decision'] === 'approved' && ! $v['enrolled']) {
                $enrolledAt = $decided['reviewed_at'];
                $v['enrolled'] = true;
                $v['signature_ref'] = ['model' => 'Siamese CNN', 'dimensions' => 128, 'enrolled_at' => $enrolledAt];
                $v['stamp_ref'] = ['model' => 'EfficientNet', 'metric' => 'cosine', 'enrolled_at' => $enrolledAt];
            }

            return $v;
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findVendor(int|string $id): ?array
    {
        $vendor = self::vendors()->first(fn (array $v): bool => (string) $v['id'] === (string) $id);

        if ($vendor === null) {
            return null;
        }

        // Rebuild the submission history from the overlaid set so decisions
        // made this session show up in the vendor's history too.
        $history = self::submissions()
            ->where('company', $vendor['company'])
            ->sortByDesc('submitted_at')
            ->values();

        $vendor['submissions'] = $history;
        $vendor['submissions_count'] = $history->count();
        $vendor['last_submission_at'] = $history->first()['submitted_at'] ?? null;

        return $vendor;
    }

    /**
     * Officer notification feed: base alerts plus this session's decision
     * receipts (§7 decision_made events), with read-state overlaid.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function notifications(): Collection
    {
        $reads = self::notificationReads();

        $decisionNotifications = collect(self::decisions())->map(function (array $d, int $id): ?array {
            $s = DemoData::findSubmission($id);

            if ($s === null) {
                return null;
            }

            $approved = $d['decision'] === 'approved';

            return [
                // Offset keeps generated ids clear of the static feed's ids.
                'id' => 1000 + $id,
                'type' => 'decision_made',
                'icon' => $approved ? 'check-circle' : 'x-circle',
                'color' => $approved ? 'emerald' : 'rose',
                'title' => 'Decision recorded',
                'body' => sprintf('You %s %s (%s).', $approved ? 'approved' : 'rejected', $s['ref'], $s['company']),
                'at' => Carbon::parse($d['at']),
                'read' => true, // the officer's own action needs no unread dot
                'submission_id' => $id,
            ];
        })->filter()->values();

        return DemoData::notifications()
            ->concat($decisionNotifications)
            ->map(function (array $n) use ($reads): array {
                $n['read'] = $reads[$n['id']] ?? $n['read'];

                return $n;
            })
            ->sortByDesc('at')
            ->values();
    }

    public static function unreadCount(): int
    {
        return self::notifications()->where('read', false)->count();
    }

    public static function markNotificationRead(int $id): void
    {
        $reads = self::notificationReads();
        $reads[$id] = true;

        Session::put(self::NOTIFICATION_READS_KEY, $reads);
    }

    public static function markAllNotificationsRead(): void
    {
        $reads = self::notificationReads();

        foreach (self::notifications() as $n) {
            $reads[$n['id']] = true;
        }

        Session::put(self::NOTIFICATION_READS_KEY, $reads);
    }

    /**
     * Risk Logs are derived from the static pipeline results and are not
     * affected by officer decisions — pass straight through.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function riskLogs(): Collection
    {
        return DemoData::riskLogs();
    }

    /**
     * Clear every overlay and return the demo to its initial state.
     */
    public static function reset(): void
    {
        Session::forget([self::DECISIONS_KEY, self::NOTIFICATION_READS_KEY]);
    }

    /**
     * Simulate pipeline/processing latency so loading states are visible in
     * the prototype. No-op in tests.
     */
    public static function simulateProcessing(int $milliseconds = 600): void
    {
        if (! app()->runningUnitTests()) {
            usleep($milliseconds * 1000);
        }
    }

    /**
     * @return array<int, array{decision: string, comments: string, by: string, at: string}>
     */
    private static function decisions(): array
    {
        return Session::get(self::DECISIONS_KEY, []);
    }

    /**
     * @return array<int, bool>
     */
    private static function notificationReads(): array
    {
        return Session::get(self::NOTIFICATION_READS_KEY, []);
    }
}
