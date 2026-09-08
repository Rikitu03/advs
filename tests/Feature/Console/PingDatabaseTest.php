<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PingDatabaseTest extends TestCase
{
    public function test_it_reports_a_healthy_database_connection(): void
    {
        $this->artisan('advs:db-ping')
            ->expectsOutputToContain('Database connection is healthy.')
            ->assertSuccessful();
    }

    public function test_it_reports_an_unreachable_database_connection(): void
    {
        config(['database.connections.sqlite.database' => '']);
        DB::purge('sqlite');
        config(['database.default' => 'sqlite']);

        $this->artisan('advs:db-ping')
            ->expectsOutputToContain('Database is not reachable.')
            ->assertFailed();
    }
}
