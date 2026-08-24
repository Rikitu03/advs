<?php

namespace Tests\Feature\Database;

use App\Models\Notification;
use App\Models\Submission;
use App\Models\ValidationResult;
use App\Models\Vendor;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoDataSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seeder_populates_a_reviewable_dashboard(): void
    {
        $this->seed(DemoDataSeeder::class);

        $this->assertSame(5, Vendor::count());
        $this->assertSame(3, Submission::where('status', Submission::STATUS_PENDING_REVIEW)->count());
        $this->assertSame(1, Submission::where('status', Submission::STATUS_APPROVED)->count());
        $this->assertSame(1, Submission::where('status', Submission::STATUS_RESUBMISSION_REQUESTED)->count());

        // Every document carries a scored validation result, and officers have alerts.
        $this->assertSame(10, ValidationResult::count());
        $this->assertGreaterThan(0, Notification::count());

        Submission::with('documents')->get()->each(function (Submission $submission): void {
            $this->assertCount(2, $submission->documents);
            $this->assertNotNull($submission->composite_risk_score);
        });
    }
}
