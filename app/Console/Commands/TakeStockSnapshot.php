<?php

namespace App\Console\Commands;

use App\Services\StockHistoryService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('stock:snapshot {--force : Take a snapshot now, ignoring the schedule}')]
#[Description('Store the total stock per store from the SOS API when the history schedule is due')]
class TakeStockSnapshot extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(StockHistoryService $history): int
    {
        $now = $history->now();

        if (! $this->option('force')) {
            if (! $history->isDue($now) || $history->alreadyTaken($now)) {
                return self::SUCCESS;
            }
        }

        try {
            $snapshot = $history->takeSnapshot($this->option('force') ? 'manual' : 'schedule');
        } catch (RuntimeException $exception) {
            $this->error('Snapshot gagal: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Snapshot {$snapshot->local_date} {$snapshot->local_time}: total stock ".number_format($snapshot->total_stock).' pcs.');

        return self::SUCCESS;
    }
}
