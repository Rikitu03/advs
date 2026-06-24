<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    /**
     * Default state — a generic log row attributed to a fresh user.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'action' => 'user.created',
            'entity_type' => User::class,
            'entity_id' => null,
            'details' => ['note' => $this->faker->sentence()],
            'ip_address' => $this->faker->ipv4(),
            'created_at' => now(),
        ];
    }

    /**
     * Force the action identifier.
     */
    public function action(string $action): static
    {
        return $this->state(fn (): array => ['action' => $action]);
    }

    /**
     * Pin the audit row to a specific actor (or null for "deleted user").
     */
    public function forUser(?User $user): static
    {
        return $this->state(fn (): array => ['user_id' => $user?->id]);
    }

    /**
     * Attach the row to a specific target entity (polymorphic).
     */
    public function forEntity(string $type, int|string|null $id = null, ?array $details = null): static
    {
        return $this->state(fn (): array => array_filter([
            'entity_type' => $type,
            'entity_id' => $id,
            'details' => $details,
        ], fn ($v) => $v !== null));
    }

    /**
     * Pin the row to a specific timestamp.
     */
    public function at(\DateTimeInterface|string $when): static
    {
        return $this->state(fn (): array => [
            'created_at' => $when,
        ]);
    }
}
