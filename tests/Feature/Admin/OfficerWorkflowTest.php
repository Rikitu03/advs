<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Support\DemoStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class OfficerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->officer = User::factory()->role(User::ROLE_COMPLIANCE_OFFICER)->create();
    }

    public function test_every_officer_tab_renders(): void
    {
        $this->actingAs($this->officer);

        foreach ([
            route('admin.dashboard'),
            route('admin.pending'),
            route('admin.archived'),
            route('admin.vendors'),
            route('admin.risk-logs'),
            route('admin.notifications'),
            route('admin.submissions.show', 1042),
            route('admin.vendors.show', 1),
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_vendor_cannot_access_officer_tabs(): void
    {
        $vendor = User::factory()->role(User::ROLE_VENDOR)->create();

        $this->actingAs($vendor)->get(route('admin.pending'))->assertForbidden();
        $this->actingAs($vendor)->get(route('admin.submissions.show', 1042))->assertForbidden();
    }

    public function test_unknown_submission_returns_404(): void
    {
        $this->actingAs($this->officer)->get(route('admin.submissions.show', 9999))->assertNotFound();
    }

    public function test_officer_can_approve_a_submission(): void
    {
        $this->actingAs($this->officer);

        Volt::test('admin.submissions.show', ['submission' => '1042'])
            ->call('startDecision', 'approve')
            ->set('comments', 'Verified against enrolled references.')
            ->call('submitDecision')
            ->assertSee('Approved')
            ->assertSee('Verified against enrolled references.');

        $submission = DemoStore::findSubmission(1042);
        $this->assertSame('approved', $submission['decision']);
        $this->assertSame($this->officer->name, $submission['reviewed_by']);

        $this->assertFalse(DemoStore::pendingSubmissions()->contains('id', 1042));
        $this->assertTrue(DemoStore::archivedReports()->contains('id', 1042));
    }

    public function test_officer_can_reject_and_undo_a_decision(): void
    {
        $this->actingAs($this->officer);

        $component = Volt::test('admin.submissions.show', ['submission' => '1039'])
            ->call('startDecision', 'reject')
            ->call('submitDecision')
            ->assertSee('Rejected');

        $this->assertTrue(DemoStore::archivedReports()->contains('id', 1039));

        $component->call('undoDecision')->assertSee('Pending review');

        $this->assertTrue(DemoStore::pendingSubmissions()->contains('id', 1039));
    }

    public function test_approving_a_vendors_submission_updates_the_vendor_profile(): void
    {
        $this->actingAs($this->officer);

        // Villanueva Foods (vendor 6) starts pending and not enrolled (SUB-1045).
        DemoStore::decide(1045, 'approved');

        $vendor = DemoStore::findVendor(6);
        $this->assertSame('approved', $vendor['status']);
        $this->assertTrue($vendor['enrolled']);
        $this->assertNotNull($vendor['signature_ref']);
    }

    public function test_pending_queue_filters_by_search_and_risk(): void
    {
        $this->actingAs($this->officer);

        Volt::test('admin.pending')
            ->assertSee('Santos Trading Corp.')
            ->assertSee('Mendoza Pharma')
            ->set('search', 'Santos')
            ->assertSee('Santos Trading Corp.')
            ->assertDontSee('Mendoza Pharma')
            ->set('search', '')
            ->call('setRisk', 'low')
            ->assertSee('Mendoza Pharma')
            ->assertDontSee('Santos Trading Corp.');
    }

    public function test_archived_reports_filter_by_decision(): void
    {
        $this->actingAs($this->officer);

        Volt::test('admin.archived')
            ->call('setDecision', 'rejected')
            ->assertSee('Tan Imports')
            ->assertDontSee('Garcia Textiles');
    }

    public function test_notifications_can_be_marked_read(): void
    {
        $this->actingAs($this->officer);

        $this->assertGreaterThan(0, DemoStore::unreadCount());

        Volt::test('admin.notifications')
            ->call('markRead', 1)
            ->call('markAllRead');

        $this->assertSame(0, DemoStore::unreadCount());
    }

    public function test_dashboard_kpis_react_to_decisions_and_reset(): void
    {
        $this->actingAs($this->officer);

        $before = DemoStore::kpis()['pending'];

        DemoStore::decide(1042, 'rejected');
        $this->assertSame($before - 1, DemoStore::kpis()['pending']);

        Volt::test('admin.dashboard')->call('resetDemo');
        $this->assertSame($before, DemoStore::kpis()['pending']);
    }
}
