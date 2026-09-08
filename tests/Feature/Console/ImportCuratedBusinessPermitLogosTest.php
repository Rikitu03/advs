<?php

namespace Tests\Feature\Console;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportCuratedBusinessPermitLogosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--no-interaction' => true])->assertExitCode(0);
    }

    public function test_dry_run_lists_curated_candidates_without_writing(): void
    {
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');

        $this->artisan('advs:import-business-permit-logos --city=Makati --dry-run')
            ->expectsOutputToContain('Makati: curated candidate')
            ->assertExitCode(0);

        $this->assertDatabaseCount('logo_references', 0);
        Http::assertNothingSent();
    }

    public function test_import_embeds_only_the_curated_asset_and_persists_provenance(): void
    {
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
        Http::fake(['*/v1/stamp/embed' => Http::response(['vector' => [0.1, 0.2, 0.3]], 200)]);

        $this->artisan('advs:import-business-permit-logos --city=Makati')
            ->expectsOutputToContain('Makati: imported reference')
            ->assertExitCode(0);

        $row = DB::table('logo_references')->first();
        $this->assertNotNull($row);
        $this->assertSame('Makati', $row->city);
        $this->assertStringContainsString('approved curated asset 1.0: makati-building-logo', $row->label);
        $this->assertSame([0.1, 0.2, 0.3], json_decode($row->feature_vector, true));
        Storage::disk('local')->assertExists($row->reference_image_path);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/v1/stamp/embed'));
    }
}
