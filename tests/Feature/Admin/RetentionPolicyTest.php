<?php

namespace Tests\Feature\Admin;

use App\Models\RetentionPolicy;
use App\Models\User;
use App\Policies\RetentionPolicyPolicy;
use App\Services\RetentionPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Feature tests for the retention policy service and authorization rules.
 */
class RetentionPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_policy_allows_access_and_non_admin_policy_denies_access(): void
    {
        $admin = User::factory()->role(User::ROLE_ADMIN)->create();
        $officer = User::factory()->role(User::ROLE_COMPLIANCE_OFFICER)->create();

        Gate::policy(RetentionPolicy::class, RetentionPolicyPolicy::class);

        $this->assertTrue(Gate::forUser($admin)->allows('viewAny', RetentionPolicy::class));
        $this->assertTrue(Gate::forUser($admin)->allows('update', new RetentionPolicy));
        $this->assertFalse(Gate::forUser($officer)->allows('viewAny', RetentionPolicy::class));
        $this->assertFalse(Gate::forUser($officer)->allows('update', new RetentionPolicy));
    }

    public function test_service_seeds_and_returns_defaults(): void
    {
        /** @var RetentionPolicyService $service */
        $service = app(RetentionPolicyService::class);

        $policies = $service->all();

        $this->assertCount(4, $policies);
        $this->assertSame('submission_records', $policies[0]['key']);
        $this->assertSame('1825', $policies[0]['value']['retention_days']);
        $this->assertSame('Audit Logs', collect($policies)->firstWhere('key', 'audit_logs')['label']);
    }

    public function test_admin_can_persist_retention_policies(): void
    {
        $admin = User::factory()->role(User::ROLE_ADMIN)->create();
        /** @var RetentionPolicyService $service */
        $service = app(RetentionPolicyService::class);

        $result = $service->save([
            'submission_records' => [
                'retention_days' => '2000',
                'archive_enabled' => true,
                'archive_after_days' => '1500',
                'deletion_enabled' => false,
                'deletion_after_days' => '',
                'enabled' => true,
                'notes' => 'Updated retention rule',
            ],
        ], $admin);

        $this->assertSame(1, $result['saved']);

        $policy = RetentionPolicy::query()->where('key', 'submission_records')->first();
        $this->assertNotNull($policy);
        $this->assertSame(2000, $policy->retention_days);
        $this->assertTrue($policy->archive_enabled);
        $this->assertSame(1500, $policy->archive_after_days);
        $this->assertFalse($policy->deletion_enabled);
        $this->assertTrue($policy->enabled);
        $this->assertSame('Updated retention rule', $policy->notes);
        $this->assertSame($admin->id, $policy->updated_by);
    }

    public function test_validation_blocks_invalid_retention_rules(): void
    {
        $admin = User::factory()->role(User::ROLE_ADMIN)->create();
        /** @var RetentionPolicyService $service */
        $service = app(RetentionPolicyService::class);

        try {
            $service->save([
                'uploaded_documents' => [
                    'retention_days' => '30',
                    'archive_enabled' => true,
                    'archive_after_days' => '',
                    'deletion_enabled' => true,
                    'deletion_after_days' => '90',
                    'enabled' => true,
                    'notes' => '',
                ],
            ], $admin);

            $this->fail('Expected validation to fail.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('policies.uploaded_documents.archive_after_days', $e->errors());
            $this->assertArrayHasKey('policies.uploaded_documents.deletion_after_days', $e->errors());
        }
    }
}
