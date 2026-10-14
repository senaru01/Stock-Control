<?php

namespace Database\Factories;

use App\Models\MachineSnapshot;
use App\Models\MonitoredMachine;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MachineSnapshot>
 */
class MachineSnapshotFactory extends Factory
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
            ...$this->moment(CarbonImmutable::now('Asia/Jakarta')),
            'item_count' => 0,
            'source' => 'schedule',
        ];
    }

    /**
     * Place the snapshot at a local (WIB) date and time, filling the matching slot of its production day.
     */
    public function at(string $localDateTime): static
    {
        return $this->state(fn () => $this->moment(CarbonImmutable::parse($localDateTime, 'Asia/Jakarta')));
    }

    /**
     * @return array{taken_at: CarbonImmutable, local_date: string, local_time: string, production_date: string, slot_time: string}
     */
    protected function moment(CarbonImmutable $local): array
    {
        $sinceDayStart = ($local->hour - 7 + 24) % 24;

        return [
            'taken_at' => $local,
            'local_date' => $local->toDateString(),
            'local_time' => $local->format('H:i'),
            'production_date' => $local->subHours(7)->toDateString(),
            'slot_time' => sprintf('%02d:00', (7 + $sinceDayStart - $sinceDayStart % 2) % 24),
        ];
    }
}
