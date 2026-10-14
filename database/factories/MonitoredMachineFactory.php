<?php

namespace Database\Factories;

use App\Models\MonitoredMachine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MonitoredMachine>
 */
class MonitoredMachineFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'line' => 'SW '.fake()->unique()->numberBetween(1, 9).'.'.fake()->numberBetween(1, 9),
            'name' => null,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
