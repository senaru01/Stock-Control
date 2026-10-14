<?php

namespace Database\Factories;

use App\Models\MachineStockControl;
use App\Models\MonitoredMachine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MachineStockControl>
 */
class MachineStockControlFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'monitored_machine_id' => MonitoredMachine::factory(),
            'store' => 'FINISH GOODS',
            'part_no' => fake()->unique()->numerify('PART-####'),
            'min_days' => 1,
            'max_days' => 2,
            'effective_from' => now()->subYear(),
        ];
    }
}
