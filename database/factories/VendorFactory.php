<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vendor>
 */
class VendorFactory extends Factory
{
    protected $model = Vendor::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'company_name' => $this->faker->company(),
            'registration_number' => $this->faker->numerify('###-###-###-000'),
            'phone_number' => $this->faker->unique()->numerify('+63 9## ### ####'),
            'address' => $this->faker->address(),
            'risk_score' => 0,
            'status' => Vendor::STATUS_PENDING,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (): array => ['status' => Vendor::STATUS_APPROVED]);
    }
}
