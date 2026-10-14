<?php

namespace Database\Seeders;

use App\Models\MonitoredMachine;
use Illuminate\Database\Seeder;

class MonitoredMachineSeeder extends Seeder
{
    /**
     * Seed the first machine under stock control; its part min / max are set on the Mesin Monitoring page.
     */
    public function run(): void
    {
        MonitoredMachine::firstOrCreate(['line' => 'SW 5.3'], ['is_active' => true]);
    }
}
