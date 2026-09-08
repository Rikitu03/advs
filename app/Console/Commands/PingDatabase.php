<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class PingDatabase extends Command
{
    protected $signature = 'advs:db-ping';

    protected $description = 'Verify that the configured database connection is reachable.';

    public function handle(): int
    {
        try {
            DB::connection()->getPdo();
        } catch (Throwable) {
            $this->error('Database is not reachable. Start MySQL and verify the DB_* settings, then run this command again.');

            return self::FAILURE;
        }

        $this->info('Database connection is healthy.');

        return self::SUCCESS;
    }
}
