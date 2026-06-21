<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PruneAbandonedRegistrations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'advs:prune-abandoned-registrations
        {--days=7 : Grace period in days before an unfinished registration is pruned}
        {--dry-run : Report what would be deleted without deleting anything}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete vendor accounts abandoned before signature enrollment (unverified and unenrolled).';

    /**
     * Execute the console command.
     *
     * A vendor who registers but never enrolls a reference signature is stuck at
     * step 2 and can never verify their email (the link is only sent after
     * enrollment), so the account is inert. After a grace period these rows are
     * pruned. The predicate is deliberately strict — vendor role, unverified, and
     * unenrolled — so verified vendors, enrolled vendors, and officers/admins are
     * never touched.
     */
    public function handle(): int
    {
        $days = (int) $this->option('days');

        if ($days < 0) {
            $this->error('The --days option must be zero or greater.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);

        $query = User::query()
            ->where('role', User::ROLE_VENDOR)
            ->whereNull('email_verified_at')
            ->whereNull('signature_enrolled_at')
            ->where('created_at', '<=', $cutoff);

        $count = (clone $query)->count();

        if ($count === 0) {
            $this->info('No abandoned registrations to prune.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("[dry-run] {$count} abandoned registration(s) older than {$days} day(s) would be pruned.");

            return self::SUCCESS;
        }

        $deleted = 0;

        // chunkById is delete-safe (it pages by id, so removed rows are never
        // skipped). Prune sets are small, so per-row deletion is acceptable and
        // lets us clean up any stray signature file.
        $query->chunkById(100, function ($users) use (&$deleted) {
            foreach ($users as $user) {
                // Defensive: an unenrolled row should have no stored signature,
                // but remove one if present so nothing is left orphaned on disk.
                if ($user->signature_path) {
                    Storage::disk('local')->delete($user->signature_path);
                }

                $user->delete();
                $deleted++;
            }
        });

        Log::info('Pruned abandoned vendor registrations', [
            'count' => $deleted,
            'days' => $days,
        ]);

        $this->info("Pruned {$deleted} abandoned registration(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
