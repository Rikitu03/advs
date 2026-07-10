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
            'trade_name' => null,
            'business_entity_type' => 'sole_proprietorship',
            'tin' => $this->faker->numerify('###-###-###-000'),
            'dti_registration_number' => $this->faker->numerify('DTI-#######'),
            'sec_registration_number' => null,
            'business_permit_number' => $this->faker->numerify('BP-#######'),
            'nature_of_business' => 'Food retail and distribution',
            'business_street' => $this->faker->streetAddress(),
            'business_barangay' => 'Barangay '.$this->faker->numberBetween(1, 200),
            'business_city' => $this->faker->city(),
            'business_province' => 'Metro Manila',
            'business_postal_code' => $this->faker->numerify('1###'),
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
