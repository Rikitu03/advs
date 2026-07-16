<?php

namespace Database\Factories;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    protected $model = Notification::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => Notification::TYPE_GENERAL,
            'subject' => $this->faker->sentence(4),
            'body' => $this->faker->sentence(10),
            'sender' => 'ADVS System',
            'related_submission_id' => null,
            'is_read' => false,
        ];
    }

    public function read(): static
    {
        return $this->state(fn (): array => ['is_read' => true]);
    }
}
