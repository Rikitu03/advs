<?php

namespace Database\Factories;

use App\Models\SystemSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SystemSetting>
 */
class SystemSettingFactory extends Factory
{
    protected $model = SystemSetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => $this->faker->unique()->slug(3).'.'.$this->faker->unique()->numberBetween(1, 9999),
            'value' => (string) $this->faker->numberBetween(1, 100),
            'description' => $this->faker->sentence(),
            'updated_by' => null,
        ];
    }

    /**
     * Convenience state: a known key with the supplied value.
     */
    public function withKey(string $key, string|int|float $value, ?string $description = null): static
    {
        return $this->state(fn (): array => [
            'key' => $key,
            'value' => SystemSetting::stringify($value),
            'description' => $description ?? "Seed value for {$key}",
        ]);
    }
}
