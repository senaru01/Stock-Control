<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Stock history: checked every minute; the command itself only stores a snapshot when the
 * schedule configured on the History page (interval or fixed times) is due.
 */
Schedule::command('stock:snapshot')->everyMinute()->withoutOverlapping();

/*
 * Daily stock: checked every minute; the command records the finish goods stock and rebuilds the monthly
 * Excel once per active day, at (or, when the scheduler was down, after) the time set on the Daily Stock page.
 */
Schedule::command('stock:daily')->everyMinute()->withoutOverlapping();

/*
 * Machine stock control: checked every minute; each active machine gets one snapshot per 2-hour slot
 * (00:00, 02:00, ... 22:00 WIB), so when the scheduler or the API is late the current slot is still filled.
 */
Schedule::command('stock:machines')->everyMinute()->withoutOverlapping();
