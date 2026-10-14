<?php

namespace App\Console\Commands;

use App\Services\StockHistoryService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

#[Signature('stock:snapshot {--force : Take a snapshot now, ignoring the schedule}')]
#[Description('Store the total stock per store from the SOS API once per scheduled moment of the history schedule')]
class TakeStockSnapshot extends Command
{
    /**
     * After a failed API call, scheduled runs wait this long before trying again.
     */
    public const RETRY_AFTER_MINUTES = 10;

    public const RETRY_CACHE_KEY = 'stock_history.retry_after';

    /**
     * Execute the console command.
     *
     * A scheduled moment that was missed (scheduler busy or briefly down) is still taken on a later run,
     * as long as no snapshot was stored since that moment.
     */
    public function handle(StockHistoryService $history): int
    {
        $force = (bool) $this->option('force');

        if (! $force) {
            $dueAt = $history->lastDueAt($history->now());

            if ($dueAt === null || $history->takenSince($dueAt) || Cache::has(self::RETRY_CACHE_KEY)) {
                return self::SUCCESS;
            }
        }

        try {
            $snapshot = $history->takeSnapshot($force ? 'manual' : 'schedule');
        } catch (RuntimeException $exception) {
            Cache::put(self::RETRY_CACHE_KEY, true, now()->addMinutes(self::RETRY_AFTER_MINUTES));
            $this->error('Snapshot gagal: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Snapshot {$snapshot->local_date} {$snapshot->local_time}: total stock ".number_format($snapshot->total_stock).' pcs.');

        return self::SUCCESS;
    }
}
