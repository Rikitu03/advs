<?php

namespace Tests\Feature\Admin;

use App\Models\SystemSetting;
use App\Models\User;
use App\Services\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Feature tests for the admin System Settings module.
 *
 * Coverage:
 *  - route gating (`role:admin` + SystemSettingPolicy)
 *  - the Volt page mounts and renders every schema field
 *  - `save()` persists the typed values + records the actor + updated_at
 *  - per-field validation messages surface on the Volt error bag
 *  - `resetKey()` restores the schema default for one key
 *  - the SystemSettingsService handles coercion + cross-field constraints
 */
class SystemSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('admin.settings.index'))->assertRedirect(route('login'));
    }

    public function test_vendors_cannot_access_system_settings(): void
    {
        $vendor = User::factory()->role(User::ROLE_VENDOR)->create();

        $this->actingAs($vendor)
            ->get(route('admin.settings.index'))
            ->assertForbidden();
    }

    public function test_compliance_officers_cannot_access_system_settings(): void
    {
        $officer = User::factory()->role(User::ROLE_COMPLIANCE_OFFICER)->create();

        $this->actingAs($officer)
            ->get(route('admin.settings.index'))
            ->assertForbidden();
    }

    public function test_admin_can_view_system_settings_page(): void
    {
        $admin = User::factory()->role(User::ROLE_ADMIN)->create();

        $this->actingAs($admin)
            ->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSee('System Settings')
            ->assertSee('Max file size (MB)')
            ->assertSee('High-risk threshold')
            ->assertSee('Stamp similarity threshold');
    }

    public function test_volt_component_renders_every_schema_field(): void
    {
        $admin = User::factory()->role(User::ROLE_ADMIN)->create();

        Livewire::actingAs($admin)
            ->test('admin.settings.index')
            ->assertSet('values.max_file_size_mb', '10')
            ->assertSet('values.high_risk_threshold', '61')
            ->assertSet('values.stamp_similarity_threshold', '0.85')
            ->assertSet('values.accepted_formats', 'pdf,png,jpg,jpeg')
            ->assertSee('File Upload Constraints')
            ->assertSee('Risk Bands & Retention');
    }

    public function test_admin_can_persist_updated_values(): void
    {
        Log::spy();
        $admin = User::factory()->role(User::ROLE_ADMIN)->create();

        Livewire::actingAs($admin)
            ->test('admin.settings.index')
            ->set('values.max_file_size_mb', '25')
            ->set('values.high_risk_threshold', '70')
            ->set('values.stamp_similarity_threshold', '0.90')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('dirty.max_file_size_mb', null);

        $this->assertSame('25', SystemSetting::get('max_file_size_mb'));
        $this->assertSame('70', SystemSetting::get('high_risk_threshold'));
        $this->assertSame('0.9', SystemSetting::get('stamp_similarity_threshold'));

        // Every persisted row is stamped with the acting admin.
        $this->assertSame(
            $admin->id,
            SystemSetting::query()->where('key', 'max_file_size_mb')->value('updated_by'),
        );

        Log::shouldHaveReceived('info')->once();
    }

    public function test_invalid_value_surfaces_validation_error_on_volt(): void
    {
        $admin = User::factory()->role(User::ROLE_ADMIN)->create();

        Livewire::actingAs($admin)
            ->test('admin.settings.index')
            ->set('values.max_file_size_mb', '0')
            ->set('values.high_risk_threshold', '999')
            ->call('save')
            ->assertHasErrors(['max_file_size_mb', 'high_risk_threshold']);
    }

    public function test_float_field_rejects_non_numeric_input(): void
    {
        $admin = User::factory()->role(User::ROLE_ADMIN)->create();

        Livewire::actingAs($admin)
            ->test('admin.settings.index')
            ->set('values.stamp_similarity_threshold', 'not-a-number')
            ->call('save')
            ->assertHasErrors(['stamp_similarity_threshold']);
    }

    public function test_risk_weights_must_sum_to_one_or_less(): void
    {
        $admin = User::factory()->role(User::ROLE_ADMIN)->create();

        Livewire::actingAs($admin)
            ->test('admin.settings.index')
            ->set('values.risk_weight_text', '0.50')
            ->set('values.risk_weight_classification', '0.50')
            ->set('values.risk_weight_signature', '0.50')
            ->set('values.risk_weight_stamp', '0.50')
            ->call('save')
            ->assertHasErrors(['risk_weight_text']);
    }

    public function test_reset_key_restores_schema_default(): void
    {
        $admin = User::factory()->role(User::ROLE_ADMIN)->create();
        SystemSetting::set('max_file_size_mb', '99');

        Livewire::actingAs($admin)
            ->test('admin.settings.index')
            ->assertSet('values.max_file_size_mb', '99')
            ->call('resetKey', 'max_file_size_mb')
            ->assertSet('values.max_file_size_mb', '10');

        $this->assertSame('10', SystemSetting::get('max_file_size_mb'));
    }

    public function test_non_admin_cannot_mount_settings_component(): void
    {
        $officer = User::factory()->role(User::ROLE_COMPLIANCE_OFFICER)->create();

        $this->actingAs($officer)
            ->get(route('admin.settings.index'))
            ->assertForbidden();
    }

    public function test_service_persists_actor_and_timestamp(): void
    {
        $admin = User::factory()->role(User::ROLE_ADMIN)->create();
        /** @var SystemSettingsService $service */
        $service = app(SystemSettingsService::class);

        $service->updateMany([
            'max_file_size_mb' => '30',
            'high_risk_threshold' => '75',
        ], $admin);

        $row = SystemSetting::query()->where('key', 'max_file_size_mb')->first();
        $this->assertNotNull($row);
        $this->assertSame('30', $row->value);
        $this->assertSame($admin->id, $row->updated_by);
        $this->assertNotNull($row->updated_at);
    }

    public function test_service_typed_readers_coerce_values(): void
    {
        SystemSetting::set('max_file_size_mb', 25);
        SystemSetting::set('stamp_similarity_threshold', 0.92);

        $this->assertSame(25, SystemSetting::int('max_file_size_mb'));
        $this->assertSame(0.92, SystemSetting::float('stamp_similarity_threshold'));
    }

    public function test_service_returns_default_when_key_missing(): void
    {
        $this->assertSame(10, SystemSetting::int('max_file_size_mb', 10));
        $this->assertSame(0.85, SystemSetting::float('stamp_similarity_threshold', 0.85));
        $this->assertNull(SystemSetting::get('does_not_exist'));
    }

    public function test_admin_sees_settings_link_in_sidebar(): void
    {
        $admin = User::factory()->role(User::ROLE_ADMIN)->create();

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('href="'.route('admin.settings.index').'"', false);
    }

    public function test_compliance_officer_does_not_see_settings_link_in_sidebar(): void
    {
        $officer = User::factory()->role(User::ROLE_COMPLIANCE_OFFICER)->create();

        $this->actingAs($officer)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('href="'.route('admin.settings.index').'"', false);
    }

    public function test_save_action_handles_partial_update(): void
    {
        $admin = User::factory()->role(User::ROLE_ADMIN)->create();
        SystemSetting::set('max_file_size_mb', '15');

        Livewire::actingAs($admin)
            ->test('admin.settings.index')
            ->set('values.high_risk_threshold', '75')
            ->call('save')
            ->assertHasNoErrors();

        // Untouched key keeps its persisted value.
        $this->assertSame('15', SystemSetting::get('max_file_size_mb'));
        // Touched key is updated.
        $this->assertSame('75', SystemSetting::get('high_risk_threshold'));
    }
}
