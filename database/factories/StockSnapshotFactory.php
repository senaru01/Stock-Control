<?php

namespace Database\Factories;

use App\Models\StockSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockSnapshot>
 */
class StockSnapshotFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'taken_at' => now(),
            'local_date' => now('Asia/Jakarta')->toDateString(),
            'local_time' => now('Asia/Jakarta')->format('H:i'),
            'total_stock' => fake()->numberBetween(1000, 100000),
            'item_count' => fake()->numberBetween(10, 500),
            'source' => 'schedule',
        ];
    }
}
