<?php

namespace Database\Factories;

use App\Models\ReportRecipient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReportRecipient>
 */
class ReportRecipientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
