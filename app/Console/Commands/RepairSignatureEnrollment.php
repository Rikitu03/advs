<?php

namespace App\Console\Commands;

use App\Http\Middleware\EnsureSignatureEnrolled;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Surfaces (and optionally repairs) vendors the UI considers signature-enrolled but
 * who have no reference the pipeline can verify against.
 *
 * Two columns answer "is this vendor enrolled?" and they can disagree:
 * `users.signature_enrolled_at` gates the UI ({@see EnsureSignatureEnrolled},
 * which reads it off the already-loaded user with no extra query), while Stage 4a needs
 * `vendor_embeddings.signature_embedding`. The real enrollment flow writes both in one
 * transaction, so it can never split them — but seeded and legacy accounts carry only
 * the timestamp, and those vendors silently score `no_signature_reference` on every
 * document forever, taking the missing-component penalty with it.
 *
 * This is deliberately a REPAIR, not a backfill. An embedding cannot be recomputed from
 * a placeholder `signature_path` with no image behind it, so the only honest remedy is to
 * withdraw the unbacked claim (`--clear`) and let the vendor enroll through the real flow.
 * Seeding a stand-in vector instead would be actively worse than the current state: the
 * pipeline would compare a genuine signature against an unrelated reference and report
 * `signature_mismatch` — accusing the vendor of forgery — rather than the honest
 * "verification unavailable".
 */
class RepairSignatureEnrollment extends Command
{
    protected $signature = 'advs:repair-signature-enrollment
        {--clear : Withdraw the unbacked enrollment so the vendor is routed to re-enroll}';

    protected $description = 'Report vendors marked signature-enrolled that have no reference embedding (optionally reset them).';

    public function handle(): int
    {
        $vendors = $this->unbackedEnrollments();

        if ($vendors->isEmpty()) {
            $this->info('All good — no unbacked signature enrollments found.');

            return self::SUCCESS;
        }

        $this->warn("{$vendors->count()} vendor(s) are marked enrolled with no reference embedding:");
        $this->table(
            ['Vendor ID', 'Company', 'Email', 'Enrolled at'],
            $vendors->map(fn (object $row): array => [
                $row->vendor_id,
                $row->company_name,
                $row->email,
                $row->signature_enrolled_at,
            ])->all(),
        );

        if (! $this->option('clear')) {
            $this->line('');
            $this->line('These vendors score "no_signature_reference" on every document.');
            $this->line('Re-run with --clear to withdraw the claim so they re-enroll for real.');

            return self::SUCCESS;
        }

        $cleared = User::query()
            ->whereIn('id', $vendors->pluck('user_id')->all())
            ->update(['signature_path' => null, 'signature_enrolled_at' => null]);

        $this->info("Cleared {$cleared} unbacked enrollment(s); those vendors will be asked to enroll on next request.");

        return self::SUCCESS;
    }

    /**
     * Vendor users whose `signature_enrolled_at` is set but whose embedding is missing,
     * or present-but-null (the pipeline's lookup treats both as "not enrolled" —
     * see MlPipelineService::resolveSignatureReference).
     *
     * @return Collection<int, object>
     */
    private function unbackedEnrollments(): Collection
    {
        return DB::table('vendors')
            ->join('users', 'users.id', '=', 'vendors.user_id')
            ->leftJoin('vendor_embeddings', 'vendor_embeddings.vendor_id', '=', 'vendors.id')
            ->whereNotNull('users.signature_enrolled_at')
            ->where(function ($query): void {
                $query->whereNull('vendor_embeddings.signature_embedding')
                    ->orWhere('vendor_embeddings.signature_embedding', '');
            })
            ->orderBy('vendors.id')
            ->get([
                'vendors.id as vendor_id',
                'vendors.company_name',
                'users.id as user_id',
                'users.email',
                'users.signature_enrolled_at',
            ]);
    }
}
