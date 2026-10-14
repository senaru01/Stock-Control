<?php

namespace App\Console\Commands;

use App\Models\MachineSnapshot;
use App\Models\MonitoredMachine;
use App\Services\MachineStockService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

#[Signature('stock:machines {--force : Take a snapshot of every active machine now, ignoring the 2-hour slots}')]
#[Description('Store the stock and stock prod of every monitored machine once per 2-hour slot')]
class TakeMachineSnapshot extends Command
{
    /**
     * After a failed API call, scheduled runs wait this long before trying again.
     */
    public const RETRY_AFTER_MINUTES = 10;

    public const RETRY_CACHE_KEY = 'machine_stock.retry_after';

    /**
     * Execute the console command.
     */
    public function handle(MachineStockService $machineStock): int
    {
        $force = (bool) $this->option('force');

        if (! $force && Cache::has(self::RETRY_CACHE_KEY)) {
            return self::SUCCESS;
        }

        $machines = $force
            ? MonitoredMachine::query()->active()->orderBy('line')->get()
            : $machineStock->dueMachines($machineStock->now());

        if ($machines->isEmpty()) {
            return self::SUCCESS;
        }

        try {
            $snapshots = $machineStock->takeSnapshots($machines, $force ? 'manual' : 'schedule');
        } catch (RuntimeException $exception) {
            Cache::put(self::RETRY_CACHE_KEY, true, now()->addMinutes(self::RETRY_AFTER_MINUTES));
            $this->error('Snapshot mesin gagal: '.$exception->getMessage());

            return self::FAILURE;
        }

        $snapshots->each(fn (MachineSnapshot $snapshot) => $this->info(
            "{$snapshot->machine->line} · {$snapshot->local_date} {$snapshot->local_time} (slot {$snapshot->slot_time}): {$snapshot->item_count} part."
        ));

        return self::SUCCESS;
    }
}
