<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PruneAbandonedRegistrationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_prunes_vendors_abandoned_before_signature_enrollment(): void
    {
        $abandoned = User::factory()->unenrolled()->unverified()->create([
            'created_at' => now()->subDays(30),
        ]);

        $this->artisan('advs:prune-abandoned-registrations')->assertSuccessful();

        $this->assertDatabaseMissing('users', ['id' => $abandoned->id]);
    }

    public function test_it_keeps_registrations_within_the_grace_period(): void
    {
        $recent = User::factory()->unenrolled()->unverified()->create([
            'created_at' => now()->subDay(),
        ]);

        $this->artisan('advs:prune-abandoned-registrations', ['--days' => 7])->assertSuccessful();

        $this->assertDatabaseHas('users', ['id' => $recent->id]);
    }

    public function test_it_keeps_vendors_who_completed_signature_enrollment(): void
    {
        // Enrolled but still unverified — they finished step 2, so they are not
        // abandoned even if they never clicked the verification link.
        $enrolled = User::factory()->unverified()->create([
            'created_at' => now()->subDays(30),
        ]);

        $this->artisan('advs:prune-abandoned-registrations')->assertSuccessful();

        $this->assertDatabaseHas('users', ['id' => $enrolled->id]);
    }

    public function test_it_never_prunes_officers_or_admins(): void
    {
        $officer = User::factory()->role(User::ROLE_COMPLIANCE_OFFICER)->unenrolled()->unverified()->create([
            'created_at' => now()->subDays(30),
        ]);
        $admin = User::factory()->role(User::ROLE_ADMIN)->unenrolled()->unverified()->create([
            'created_at' => now()->subDays(30),
        ]);

        $this->artisan('advs:prune-abandoned-registrations')->assertSuccessful();

        $this->assertDatabaseHas('users', ['id' => $officer->id]);
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_dry_run_reports_without_deleting(): void
    {
        $abandoned = User::factory()->unenrolled()->unverified()->create([
            'created_at' => now()->subDays(30),
        ]);

        $this->artisan('advs:prune-abandoned-registrations', ['--dry-run' => true])
            ->expectsOutputToContain('[dry-run]')
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['id' => $abandoned->id]);
    }

    public function test_it_removes_a_stray_signature_file_for_a_pruned_row(): void
    {
        Storage::fake('local');

        $path = 'signatures/999/reference.png';
        Storage::disk('local')->put($path, 'fake');

        // Unverified, unenrolled vendor that nonetheless has a stray signature
        // file on disk — exercises the defensive cleanup path.
        User::factory()->unenrolled()->unverified()->create([
            'created_at' => now()->subDays(30),
            'signature_path' => $path,
        ]);

        $this->artisan('advs:prune-abandoned-registrations')->assertSuccessful();

        Storage::disk('local')->assertMissing($path);
    }
}
