<?php

namespace Tests\Feature\Console;

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RepairSignatureEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A vendor the UI considers enrolled (`users.signature_enrolled_at`) but with no
     * reference in `vendor_embeddings` — the state seeded and legacy accounts land in.
     */
    private function unbackedVendor(): Vendor
    {
        $user = User::factory()->role(User::ROLE_VENDOR)->create([
            'signature_path' => 'signatures/placeholder.jpg',
            'signature_enrolled_at' => now(),
        ]);

        return Vendor::factory()->for($user)->create();
    }

    private function backedVendor(): Vendor
    {
        $vendor = $this->unbackedVendor();

        DB::table('vendor_embeddings')->insert([
            'vendor_id' => $vendor->id,
            'signature_embedding' => json_encode([0.1, 0.2, 0.3]),
            'signature_enrolled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $vendor;
    }

    public function test_reports_vendors_enrolled_without_a_usable_reference(): void
    {
        $unbacked = $this->unbackedVendor();
        $this->backedVendor();

        $this->artisan('advs:repair-signature-enrollment')
            ->expectsOutputToContain($unbacked->company_name)
            ->assertExitCode(0);
    }

    public function test_reports_nothing_when_every_enrollment_is_backed(): void
    {
        $this->backedVendor();

        $this->artisan('advs:repair-signature-enrollment')
            ->expectsOutputToContain('no unbacked signature enrollments')
            ->assertExitCode(0);
    }

    public function test_reporting_does_not_modify_anything_without_clear(): void
    {
        $vendor = $this->unbackedVendor();

        $this->artisan('advs:repair-signature-enrollment')->assertExitCode(0);

        $this->assertNotNull($vendor->user->fresh()->signature_enrolled_at);
    }

    public function test_clear_resets_enrollment_so_the_vendor_is_routed_to_re_enroll(): void
    {
        $vendor = $this->unbackedVendor();

        $this->artisan('advs:repair-signature-enrollment --clear')->assertExitCode(0);

        $user = $vendor->user->fresh();
        $this->assertNull($user->signature_enrolled_at);
        $this->assertNull($user->signature_path);
        $this->assertFalse($user->hasEnrolledSignature());
    }

    public function test_clear_leaves_backed_enrollments_untouched(): void
    {
        $vendor = $this->backedVendor();

        $this->artisan('advs:repair-signature-enrollment --clear')->assertExitCode(0);

        $this->assertNotNull($vendor->user->fresh()->signature_enrolled_at);
    }

    /**
     * A row present but holding SQL/JSON null is the same broken state as no row —
     * the pipeline's reference lookup treats both as "not enrolled".
     */
    public function test_treats_a_null_embedding_row_as_unbacked(): void
    {
        $vendor = $this->unbackedVendor();
        DB::table('vendor_embeddings')->insert([
            'vendor_id' => $vendor->id,
            'signature_embedding' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('advs:repair-signature-enrollment --clear')->assertExitCode(0);

        $this->assertNull($vendor->user->fresh()->signature_enrolled_at);
    }
}
