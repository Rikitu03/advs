<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\MlModel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Feature tests for the admin ML Model Management module.
 *
 * Coverage:
 *  - route gating (`role:admin` + MlModelPolicy)
 *  - the Volt page mounts and renders every seeded row
 *  - search / purpose / status filters push down to the DB
 *  - `syncAll()` refreshes size + mtime + status and writes an audit row
 *  - `syncOne()` re-probes a single model
 *  - `saveEditing()` validates the JSON metrics payload, persists the
 *    update, and writes a before/after audit row
 *  - invalid enum / non-array-metric values raise ValidationException
 */
class MlModelManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('admin.models.index'))->assertRedirect(route('login'));
    }

    public function test_vendors_cannot_access_ml_model_management(): void
    {
        $vendor = User::factory()->role(User::ROLE_VENDOR)->create();

        $this->actingAs($vendor)
            ->get(route('admin.models.index'))
            ->assertForbidden();
    }

    public function test_compliance_officers_cannot_access_ml_model_management(): void
    {
        $officer = User::factory()->role(User::ROLE_COMPLIANCE_OFFICER)->create();

        $this->actingAs($officer)
            ->get(route('admin.models.index'))
            ->assertForbidden();
    }

    public function test_admin_can_view_ml_model_management_page(): void
    {
        $admin = User::factory()->role(User::ROLE_ADMIN)->create();
        $model = MlModel::factory()->create([
            'name' => 'Test ResNet-50',
            'purpose' => 'classification',
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.models.index'))
            ->assertOk()
            ->assertSee('ML Model Management')
            ->assertSee('Test ResNet-50')
            ->assertSee('Classification');
    }

    public function test_volt_component_renders_seeded_rows_and_kpis(): void
    {
        $admin = User::factory()->role(User::ROLE_ADMIN)->create();
        MlModel::factory()->count(3)->create(['status' => 'active']);
        MlModel::factory()->missing()->create();

        Livewire::actingAs($admin)
            ->test('admin.models.index')
            ->assertSet('perPage', 25)
            ->assertSee('Total registered')
            ->assertSee('Active')
            ->assertSee('Missing on disk')
            ->assertSee('ML Model Management', false);
    }

    public function test_search_filter_narrows_results(): void
    {
        $admin = User::factory()->role(User::ROLE_ADMIN)->create();
        MlModel::factory()->create(['name' => 'Document Classifier']);
        MlModel::factory()->create(['name' => 'Signature Detector']);

        Livewire::actingAs($admin)
            ->test('admin.models.index')
            ->set('search', 'Document')
            ->assertSee('Document Classifier')
            ->assertDontSee('Signature Detector');
    }

    public function test_purpose_and_status_filters_combine(): void
    {
        $admin = User::factory()->role(User::ROLE_ADMIN)->create();
        MlModel::factory()->create(['name' => 'A1', 'purpose' => 'classification', 'status' => 'active']);
        MlModel::factory()->create(['name' => 'A2', 'purpose' => 'classification', 'status' => 'standby']);
        MlModel::factory()->create(['name' => 'B1', 'purpose' => 'signature', 'status' => 'active']);

        // Filter at the query level — Livewire's test HTML includes the
        // initial wire:snapshot payload (which serialises the full model
        // list) so we can't reliably assertDontSee on names. Verify the
        // computed paginator totals instead.
        $component = Livewire::actingAs($admin)
            ->test('admin.models.index')
            ->set('purpose', 'classification')
            ->set('status', 'active');

        $component->assertSet('purpose', 'classification');
        $component->assertSet('status', 'active');

        $models = $component->viewData('models');
        $this->assertSame(1, $models->total());

        $names = collect($models->items())->pluck('name')->all();
        $this->assertSame(['A1'], $names);
    }

    public function test_sync_all_refreshes_status_and_writes_summary_audit(): void
    {
        Log::spy();
        $admin = User::factory()->role(User::ROLE_ADMIN)->create();
        $present = MlModel::factory()->create([
            'name' => 'On Disk',
            'storage_path' => 'tests/fixtures/__does_not_need_real_file__.txt',
            'status' => 'standby',
        ]);

        // Use a non-existent path so the model flips to `missing` on sync.
        $absent = MlModel::factory()->create([
            'name' => 'Off Disk',
            'storage_path' => 'tests/fixtures/zz-missing-'.uniqid().'.txt',
            'status' => 'active',
        ]);

        Livewire::actingAs($admin)
            ->test('admin.models.index')
            ->call('syncAll')
            ->assertHasNoErrors();

        $this->assertSame('missing', $absent->refresh()->status);
        $absentAudit = AuditLog::query()
            ->where('action', 'ml_model.synced')
            ->where('entity_id', null)
            ->latest('id')
            ->first();
        $this->assertNotNull($absentAudit, 'Expected a summary ml_model.synced audit row.');
        $this->assertSame($admin->id, $absentAudit->user_id);
        $this->assertSame(MlModel::class, $absentAudit->entity_type);
    }

    public function test_sync_one_re_checks_a_single_row(): void
    {
        Log::spy();
        $admin = User::factory()->role(User::ROLE_ADMIN)->create();
        $model = MlModel::factory()->create([
            'name' => 'Sync One',
            'storage_path' => 'tests/fixtures/__never_needed_for_test__.bin',
            'status' => 'standby',
        ]);

        Livewire::actingAs($admin)
            ->test('admin.models.index')
            ->call('syncOne', $model->id)
            ->assertHasNoErrors();

        $model->refresh();
        $this->assertNotNull($model->last_synced_at);

        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => MlModel::class,
            'entity_id' => $model->id,
            'action' => 'ml_model.missing',
        ]);
    }

    public function test_save_editing_persists_changes_and_writes_audit_diff(): void
    {
        Log::spy();
        $admin = User::factory()->role(User::ROLE_ADMIN)->create();
        $model = MlModel::factory()->create([
            'name' => 'Editable',
            'purpose' => 'classification',
            'status' => 'standby',
            'version' => '1.0.0',
            'notes' => 'old notes',
        ]);

        Livewire::actingAs($admin)
            ->test('admin.models.index')
            ->call('startEditing', $model->id)
            ->set("editing.{$model->id}.status", 'active')
            ->set("editing.{$model->id}.version", '1.1.0')
            ->set("editing.{$model->id}.notes", 'fresh notes')
            ->set("editing.{$model->id}.metrics_text", '{"top1_accuracy": 0.93}')
            ->call('saveEditing', $model->id)
            ->assertHasNoErrors();

        $model->refresh();
        $this->assertSame('active', $model->status);
        $this->assertSame('1.1.0', $model->version);
        $this->assertSame('fresh notes', $model->notes);
        $this->assertSame(['top1_accuracy' => 0.93], $model->metrics);
        $this->assertSame($admin->id, $model->updated_by);

        $audit = AuditLog::query()
            ->where('action', 'ml_model.updated')
            ->where('entity_id', $model->id)
            ->latest('id')
            ->first();
        $this->assertNotNull($audit);
        $this->assertArrayHasKey('status', $audit->details['changes']);
        $this->assertSame('standby', $audit->details['changes']['status']['before']);
        $this->assertSame('active', $audit->details['changes']['status']['after']);
    }

    public function test_save_editing_rejects_invalid_metrics_json(): void
    {
        $admin = User::factory()->role(User::ROLE_ADMIN)->create();
        $model = MlModel::factory()->create(['name' => 'Bad Metrics']);

        Livewire::actingAs($admin)
            ->test('admin.models.index')
            ->call('startEditing', $model->id)
            ->set("editing.{$model->id}.metrics_text", 'not-json')
            ->call('saveEditing', $model->id)
            ->assertHasErrors(["editing.{$model->id}.metrics_text"]);
    }

    public function test_save_editing_rejects_unknown_purpose_enum(): void
    {
        $admin = User::factory()->role(User::ROLE_ADMIN)->create();
        $model = MlModel::factory()->create(['name' => 'Bad Enum']);

        Livewire::actingAs($admin)
            ->test('admin.models.index')
            ->call('startEditing', $model->id)
            ->set("editing.{$model->id}.purpose", 'not-a-real-purpose')
            ->call('saveEditing', $model->id)
            ->assertHasErrors(["editing.{$model->id}"]);
    }

    public function test_sidebar_nav_link_is_visible_to_admins(): void
    {
        $admin = User::factory()->role(User::ROLE_ADMIN)->create();
        $this->actingAs($admin)
            ->get(route('admin.models.index'))
            ->assertOk()
            ->assertSee('href="'.route('admin.models.index').'"', false);
    }
}
