<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// Daily cleanup of vendor accounts abandoned before signature enrollment
// (unverified + unenrolled). See App\Console\Commands\PruneAbandonedRegistrations.
Schedule::command('advs:prune-abandoned-registrations')->daily();
