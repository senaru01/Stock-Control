<?php

namespace App\Console\Commands;

use App\Services\DailyStockService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('stock:daily {--force : Record now, ignoring the schedule}')]
#[Description('Record the finish goods stock per part, build that day\'s Daily Stock Excel and email it to the active users when the schedule is due')]
class TakeDailyStock extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(DailyStockService $dailyStock): int
    {
        if (! $this->option('force') && ! $dailyStock->isDue($dailyStock->now())) {
            return self::SUCCESS;
        }

        try {
            $recorded = $dailyStock->record($this->option('force') ? 'manual' : 'schedule');
        } catch (RuntimeException $exception) {
            $this->error('Daily stock gagal: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Daily stock {$recorded->stock_date} {$recorded->local_time}: {$recorded->item_count} part, file ".$dailyStock->reportPath($recorded->stock_date).'.');

        $delivery = $dailyStock->sendReport($recorded);

        if ($delivery['sent'] === 0 && $delivery['failed'] === []) {
            $this->warn('Tidak ada user penerima aktif, email tidak dikirim.');

            return self::SUCCESS;
        }

        $this->info("Email terkirim ke {$delivery['sent']} user.");

        if ($delivery['failed'] !== []) {
            $this->error('Email gagal dikirim ke: '.implode(', ', $delivery['failed']));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
