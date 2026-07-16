<?php

namespace Tests\Feature;

use App\Models\Submission;
use App\Models\User;
use App\Models\Vendor;
use App\Support\SubmissionPresenter;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfficerReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();

        // Created before seeding so DemoDataSeeder targets this officer with
        // its review notifications.
        $this->officer = User::factory()->role(User::ROLE_COMPLIANCE_OFFICER)->create();
    }

    public function test_compliance_officer_can_view_the_pending_submissions_queue(): void
    {
        $this->seed(DemoDataSeeder::class);

        $this->actingAs($this->officer)
            ->get(route('admin.pending'))
            ->assertOk()
            ->assertSee('Pending Submissions')
            ->assertSee('Santos Trading Corp.');
    }

    public function test_admin_can_view_the_pending_submissions_queue(): void
    {
        $user = User::factory()->role(User::ROLE_ADMIN)->create();

        $this->actingAs($user)->get(route('admin.pending'))->assertOk();
    }

    public function test_officer_can_open_a_submission_drill_down(): void
    {
        $this->seed(DemoDataSeeder::class);

        $submission = Submission::query()
            ->where('status', Submission::STATUS_PENDING_REVIEW)
            ->firstOrFail();

        $this->actingAs($this->officer)
            ->get(route('admin.submissions.show', $submission->id))
            ->assertOk()
            ->assertSee(SubmissionPresenter::reference($submission))
            ->assertSee('Risk score breakdown')
            ->assertSee('Officer decision');
    }

    public function test_unknown_submission_returns_not_found(): void
    {
        $this->actingAs($this->officer)->get(route('admin.submissions.show', 999999))->assertNotFound();
    }

    public function test_officer_can_view_the_archived_reports(): void
    {
        $this->seed(DemoDataSeeder::class);

        $this->actingAs($this->officer)
            ->get(route('admin.archived'))
            ->assertOk()
            ->assertSee('Archived Reports')
            ->assertSee('Garcia Textiles');
    }

    public function test_officer_can_view_the_vendor_directory(): void
    {
        $this->seed(DemoDataSeeder::class);

        $this->actingAs($this->officer)
            ->get(route('admin.vendors'))
            ->assertOk()
            ->assertSee('Vendor Profiles')
            ->assertSee('Santos Trading Corp.');
    }

    public function test_officer_can_open_a_vendor_profile(): void
    {
        $this->seed(DemoDataSeeder::class);

        $vendor = Vendor::where('company_name', 'Santos Trading Corp.')->firstOrFail();

        $this->actingAs($this->officer)
            ->get(route('admin.vendors.show', $vendor->id))
            ->assertOk()
            ->assertSee('Santos Trading Corp.')
            ->assertSee('Reference biometrics')
            ->assertSee('Submission history');
    }

    public function test_unknown_vendor_returns_not_found(): void
    {
        $this->actingAs($this->officer)->get(route('admin.vendors.show', 999999))->assertNotFound();
    }

    public function test_officer_can_view_the_risk_logs(): void
    {
        $this->seed(DemoDataSeeder::class);

        $this->actingAs($this->officer)
            ->get(route('admin.risk-logs'))
            ->assertOk()
            ->assertSee('Risk Logs')
            ->assertSee('Document tampering suspected')
            ->assertSee('Signature verification unavailable')
            ->assertSee('Santos Trading Corp.');
    }

    public function test_officer_can_view_the_notifications(): void
    {
        $this->seed(DemoDataSeeder::class);

        $this->actingAs($this->officer)
            ->get(route('admin.notifications'))
            ->assertOk()
            ->assertSee('Notifications')
            ->assertSee('High-risk submission detected')
            ->assertSee('Mark all as read');
    }

    public function test_vendor_cannot_access_the_review_pages(): void
    {
        $user = User::factory()->role(User::ROLE_VENDOR)->create();

        $this->actingAs($user)->get(route('admin.pending'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.submissions.show', 1042))->assertForbidden();
        $this->actingAs($user)->get(route('admin.archived'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.vendors'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.vendors.show', 1))->assertForbidden();
        $this->actingAs($user)->get(route('admin.risk-logs'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.notifications'))->assertForbidden();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('admin.pending'))->assertRedirect('/login');
        $this->get(route('admin.archived'))->assertRedirect('/login');
        $this->get(route('admin.vendors'))->assertRedirect('/login');
        $this->get(route('admin.risk-logs'))->assertRedirect('/login');
        $this->get(route('admin.notifications'))->assertRedirect('/login');
    }
}
