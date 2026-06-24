<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * Feature tests for the Audit Trail Viewer module.
 *
 * Coverage:
 *   - route + policy gating for `admin.audit.*`
 *   - the Volt index mounts for admins and renders the filter chrome
 *   - filters compose: search, actor, action, module, entity, date range
 *   - pagination + "totalMatching" KPI reflect the filter
 *   - CSV export streams only the currently-filtered rows
 *   - audit rows are immutable from the UI (policy::create is false)
 */
class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('admin.audit.index'))->assertRedirect(route('login'));
    }

    public function test_vendors_cannot_view_the_audit_trail(): void
    {
        $vendor = User::factory()->role(User::ROLE_VENDOR)->create();

        $this->actingAs($vendor)
            ->get(route('admin.audit.index'))
            ->assertForbidden();
    }

    public function test_compliance_officers_cannot_view_the_audit_trail(): void
    {
        $officer = User::factory()->role(User::ROLE_COMPLIANCE_OFFICER)->create();

        $this->actingAs($officer)
            ->get(route('admin.audit.index'))
            ->assertForbidden();
    }

    public function test_admin_can_view_the_audit_trail_page(): void
    {
        $admin = User::factory()->role(User::ROLE_ADMIN)->create();
        AuditLog::factory()->count(3)->forUser($admin)->create();

        $this->actingAs($admin)
            ->get(route('admin.audit.index'))
            ->assertOk()
            ->assertSee('Audit Trail')
            ->assertSee('Export CSV');
    }

    public function test_volt_component_renders_for_an_admin(): void
    {
        $admin = User::factory()->role(User::ROLE_ADMIN)->create();
        AuditLog::factory()->forUser($admin)->create();

        Livewire::actingAs($admin)
            ->test('admin.audit.index')
            ->assertSet('search', '')
            ->assertSet('userId', '')
            ->assertSet('action', '')
            ->assertSet('perPage', 25)
            ->assertSee('Audit Trail');
    }

    public function test_non_admin_cannot_mount_the_volt_component(): void
    {
        $officer = User::factory()->role(User::ROLE_COMPLIANCE_OFFICER)->create();

        Livewire::actingAs($officer)
            ->test('admin.audit.index')
            ->assertForbidden();
    }

    public function test_search_filter_narrows_results(): void
    {
        $admin = User::factory()->role(User::ROLE_ADMIN)->create();

        AuditLog::factory()
            ->forUser($admin)
            ->action('user.created')
            ->create(['details' => ['note' => 'unique-marker-alpha']]);

        AuditLog::factory()
            ->forUser($admin)
            ->action('system_setting.updated')
            ->create(['details' => ['note' => 'completely unrelated']]);

        Livewire::actingAs($admin)
            ->test('admin.audit.index')
            ->set('search', 'unique-marker-alpha')
            ->assertSet('totalMatching', 1);
    }

    public function test_actor_filter_only_returns_rows_for_that_user(): void
    {
        $admin = User::factory()->role(User::ROLE_ADMIN)->create();
        $other = User::factory()->role(User::ROLE_ADMIN)->create();

        AuditLog::factory()->count(2)->forUser($admin)->create();
        AuditLog::factory()->count(3)->forUser($other)->create();

        Livewire::actingAs($admin)
            ->test('admin.audit.index')
            ->set('userId', (string) $admin->id)
            ->assertSet('totalMatching', 2);
    }

    public function test_module_filter_matches_action_prefix(): void
    {
        $admin = User::factory()->role(User::ROLE_ADMIN)->create();

        AuditLog::factory()->forUser($admin)->action('user.created')->create();
        AuditLog::factory()->forUser($admin)->action('user.updated')->create();
        AuditLog::factory()->forUser($admin)->action('system_setting.updated')->create();

        Livewire::actingAs($admin)
            ->test('admin.audit.index')
            ->set('module', 'user')
            ->assertSet('totalMatching', 2);
    }

    public function test_date_range_filter_excludes_rows_outside_window(): void
    {
        $admin = User::factory()->role(User::ROLE_ADMIN)->create();

        AuditLog::factory()->forUser($admin)->at(now()->subDays(10))->create();
        AuditLog::factory()->forUser($admin)->at(now()->subHour())->create();

        Livewire::actingAs($admin)
            ->test('admin.audit.index')
            ->set('from', now()->subDay()->format('Y-m-d\TH:i'))
            ->set('to', now()->addHour()->format('Y-m-d\TH:i'))
            ->assertSet('totalMatching', 1);
    }

    public function test_entity_filter_narrows_to_target_record(): void
    {
        $admin = User::factory()->role(User::ROLE_ADMIN)->create();
        $target = User::factory()->create();

        AuditLog::factory()
            ->forUser($admin)
            ->action('user.updated')
            ->forEntity(User::class, $target->id)
            ->create();

        AuditLog::factory()
            ->forUser($admin)
            ->action('user.updated')
            ->forEntity(User::class, $admin->id)
            ->create();

        Livewire::actingAs($admin)
            ->test('admin.audit.index')
            ->set('entityType', User::class)
            ->set('entityId', (string) $target->id)
            ->assertSet('totalMatching', 1);
    }

    public function test_clear_filters_resets_every_field(): void
    {
        $admin = User::factory()->role(User::ROLE_ADMIN)->create();
        AuditLog::factory()->forUser($admin)->create();

        Livewire::actingAs($admin)
            ->test('admin.audit.index')
            ->set('search', 'something')
            ->set('userId', (string) $admin->id)
            ->set('module', 'user')
            ->call('clearFilters')
            ->assertSet('search', '')
            ->assertSet('userId', '')
            ->assertSet('action', '')
            ->assertSet('module', '')
            ->assertSet('entityType', '')
            ->assertSet('entityId', '')
            ->assertSet('from', '')
            ->assertSet('to', '');
    }

    public function test_csv_export_streams_only_filtered_rows(): void
    {
        $admin = User::factory()->role(User::ROLE_ADMIN)->create();

        AuditLog::factory()->forUser($admin)->action('user.created')->create();
        AuditLog::factory()->forUser($admin)->action('system_setting.updated')->create();

        $response = $this->actingAs($admin)
            ->get(route('admin.audit.export', ['action' => 'user.created']));

        $response->assertOk();
        $this->assertInstanceOf(StreamedResponse::class, $response->baseResponse);

        ob_start();
        $response->sendContent();
        $csv = ob_get_clean();

        $this->assertStringContainsString('user.created', $csv);
        $this->assertStringNotContainsString('system_setting.updated', $csv);
    }

    public function test_compliance_officer_cannot_export(): void
    {
        $officer = User::factory()->role(User::ROLE_COMPLIANCE_OFFICER)->create();

        $this->actingAs($officer)
            ->get(route('admin.audit.export'))
            ->assertForbidden();
    }

    public function test_detail_route_renders_payload_and_before_after(): void
    {
        $admin = User::factory()->role(User::ROLE_ADMIN)->create();

        $log = AuditLog::factory()
            ->forUser($admin)
            ->action('user.role_changed')
            ->forEntity(User::class, $admin->id, [
                'before' => ['role' => User::ROLE_VENDOR],
                'after' => ['role' => User::ROLE_ADMIN],
            ])
            ->create();

        $this->actingAs($admin)
            ->get(route('admin.audit.show', $log))
            ->assertOk()
            ->assertSee('user.role_changed')
            ->assertSee('Before')
            ->assertSee('After');
    }

    public function test_audit_log_model_is_append_only(): void
    {
        // The model's UPDATED_AT constant disables updated_at, and the policy
        // refuses create from a user-driven context. Together they enforce
        // append-only semantics at both the framework and policy layers.
        $log = new AuditLog;

        $this->assertNull(AuditLog::UPDATED_AT);

        $admin = User::factory()->role(User::ROLE_ADMIN)->create();
        $this->assertFalse($admin->can('create', $log));
    }

    public function test_for_entity_allows_a_null_entity_id_for_system_wide_events(): void
    {
        // System-wide events (e.g. system_setting.updated) target a class with
        // no specific row id. The factory must accept a null id and leave the
        // nullable entity_id column unset — mirrors AuditLogSeeder's usage.
        $log = AuditLog::factory()
            ->forEntity(SystemSetting::class, null, [
                'key' => 'risk_threshold_high',
                'before' => '70',
                'after' => '75',
            ])
            ->create();

        $this->assertNull($log->entity_id);
        $this->assertSame(SystemSetting::class, $log->entity_type);
        $this->assertSame('risk_threshold_high', $log->details['key']);
    }
}
