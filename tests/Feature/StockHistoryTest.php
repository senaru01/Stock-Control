<?php

namespace Tests\Feature;

use App\Livewire\StockHistory\Index;
use App\Models\StockSnapshot;
use App\Services\StockHistoryService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class StockHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected bool $apiFails = false;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.sos.url' => 'https://sos.test/sos/Ajax/tData',
            'services.sos.key' => 'secret-key',
            'services.sos.history_timezone' => 'Asia/Jakarta',
        ]);

        Http::fake([
            'sos.test/*' => fn () => $this->apiFails ? Http::response('error', 500) : Http::response(['data' => [
                ['id' => '1', 'store' => 'FINISH GOODS', 'part_no' => 'A', 'stock' => '100', 'std_min' => '0', 'stock_prod' => '0'],
                ['id' => '2', 'store' => 'FINISH GOODS', 'part_no' => 'B', 'stock' => '50', 'std_min' => '0', 'stock_prod' => '0'],
                ['id' => '3', 'store' => 'OUTGOING', 'part_no' => 'C', 'stock' => '-20', 'std_min' => '0', 'stock_prod' => '0'],
                ['id' => '4', 'store' => 'STORE 3', 'part_no' => 'D', 'stock' => '30', 'std_min' => '0', 'stock_prod' => '0'],
                ['id' => '5', 'store' => '', 'part_no' => null, 'stock' => '0', 'std_min' => '0', 'stock_prod' => '0'],
            ]]),
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_default_schedule_is_every_15_minutes(): void
    {
        $history = app(StockHistoryService::class);

        $this->assertSame(['mode' => 'interval', 'interval_minutes' => 15, 'times' => ['07:00', '16:00']], $history->schedule());
        $this->assertTrue($history->isDue($this->wib('2026-10-09 07:15')));
        $this->assertTrue($history->isDue($this->wib('2026-10-09 00:00')));
        $this->assertFalse($history->isDue($this->wib('2026-10-09 07:10')));
        $this->assertSame('2026-10-09 07:30', $history->nextRunAt($this->wib('2026-10-09 07:15'))->format('Y-m-d H:i'));
    }

    public function test_fixed_times_schedule_uses_local_time(): void
    {
        $history = app(StockHistoryService::class);
        $history->saveSchedule(['mode' => 'times', 'interval_minutes' => 15, 'times' => ['16:00', ' 07:00', 'bad']]);

        $this->assertSame(['07:00', '16:00'], $history->schedule()['times']);
        $this->assertTrue($history->isDue($this->wib('2026-10-09 07:00')));
        $this->assertTrue($history->isDue(CarbonImmutable::parse('2026-10-09 09:00', 'UTC')));
        $this->assertFalse($history->isDue($this->wib('2026-10-09 07:15')));
        $this->assertSame('2026-10-10 07:00', $history->nextRunAt($this->wib('2026-10-09 16:00'))->format('Y-m-d H:i'));
        $this->assertSame('Setiap hari jam 07:00, 16:00', $history->describeSchedule());
    }

    public function test_snapshot_stores_total_stock_per_store(): void
    {
        CarbonImmutable::setTestNow($this->utc('2026-10-09 07:00'));

        $snapshot = app(StockHistoryService::class)->takeSnapshot();

        $this->assertSame('2026-10-09', $snapshot->local_date);
        $this->assertSame('07:00', $snapshot->local_time);
        $this->assertTrue($snapshot->fresh()->taken_at->equalTo($this->wib('2026-10-09 07:00')));
        $this->assertSame(180, $snapshot->total_stock);
        $this->assertSame(
            ['FINISH GOODS' => 150, 'OUTGOING' => 0, 'STORE 3' => 30],
            $snapshot->stores()->orderBy('store')->pluck('total_stock', 'store')->all(),
        );
    }

    public function test_failed_api_call_does_not_store_a_snapshot(): void
    {
        $this->apiFails = true;

        $this->artisan('stock:snapshot --force')->assertFailed();

        $this->assertDatabaseCount('stock_snapshots', 0);
    }

    public function test_command_only_takes_a_snapshot_when_due_and_not_twice(): void
    {
        CarbonImmutable::setTestNow($this->utc('2026-10-09 07:10'));
        $this->artisan('stock:snapshot')->assertSuccessful();
        $this->assertDatabaseCount('stock_snapshots', 0);

        CarbonImmutable::setTestNow($this->utc('2026-10-09 07:15'));
        $this->artisan('stock:snapshot')->assertSuccessful();
        $this->artisan('stock:snapshot')->assertSuccessful();
        $this->assertDatabaseCount('stock_snapshots', 1);

        $this->artisan('stock:snapshot --force')->assertSuccessful();
        $this->assertDatabaseCount('stock_snapshots', 2);
        $this->assertSame('manual', StockSnapshot::latest('id')->first()->source);
    }

    public function test_command_is_scheduled_every_minute(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('stock:snapshot')->assertSuccessful();
    }

    public function test_history_per_snapshot_lists_every_capture_of_the_day(): void
    {
        $this->snapshot('2026-10-09', '07:00', ['FINISH GOODS' => 100, 'OUTGOING' => 10]);
        $this->snapshot('2026-10-09', '16:00', ['FINISH GOODS' => 120]);
        $this->snapshot('2026-10-10', '07:00', ['FINISH GOODS' => 999]);

        $history = app(StockHistoryService::class)->history('snapshot', '2026-10-09');

        $this->assertSame(['07:00', '16:00'], $history['labels']);
        $this->assertSame([110, 120], $history['total']);
        $this->assertSame(['FINISH GOODS' => [100, 120], 'OUTGOING' => [10, null]], $history['stores']);
    }

    public function test_history_per_day_uses_last_capture_or_average(): void
    {
        $this->snapshot('2026-10-09', '07:00', ['FINISH GOODS' => 100]);
        $this->snapshot('2026-10-09', '16:00', ['FINISH GOODS' => 200]);
        $this->snapshot('2026-10-10', '07:00', ['FINISH GOODS' => 50]);
        $this->snapshot('2026-11-01', '07:00', ['FINISH GOODS' => 999]);

        $service = app(StockHistoryService::class);
        $last = $service->history('day', '2026-10', 'last');
        $average = $service->history('day', '2026-10', 'avg');

        $this->assertSame(['9 Okt', '10 Okt'], $last['labels']);
        $this->assertSame([200, 50], $last['total']);
        $this->assertSame([2, 1], $last['counts']);
        $this->assertSame([150, 50], $average['total']);
        $this->assertSame(['FINISH GOODS' => [150, 50]], $average['stores']);
    }

    public function test_history_per_week_covers_monday_to_sunday(): void
    {
        $this->snapshot('2026-10-04', '07:00', ['FINISH GOODS' => 999]);
        $this->snapshot('2026-10-05', '07:00', ['FINISH GOODS' => 100]);
        $this->snapshot('2026-10-05', '16:00', ['FINISH GOODS' => 200]);
        $this->snapshot('2026-10-11', '16:00', ['FINISH GOODS' => 50]);
        $this->snapshot('2026-10-12', '07:00', ['FINISH GOODS' => 888]);

        $service = app(StockHistoryService::class);
        $last = $service->history('week', '2026-10-05', 'last');
        $average = $service->history('week', '2026-10-05', 'avg');

        $this->assertSame(['Sen, 5 Okt', 'Min, 11 Okt'], $last['labels']);
        $this->assertSame([200, 50], $last['total']);
        $this->assertSame([150, 50], $average['total']);
        $this->assertSame('2026-10-05', $service->weekStart('2026-10-11'));
        $this->assertSame('5 – 11 Okt 2026', $service->weekRangeLabel('2026-10-05'));
        $this->assertSame('28 Sep – 4 Okt 2026', $service->weekRangeLabel('2026-09-28'));
    }

    public function test_history_page_defaults_to_the_latest_week(): void
    {
        $this->snapshot('2026-10-08', '07:00', ['FINISH GOODS' => 100]);

        Livewire::test(Index::class)
            ->assertSet('granularity', 'week')
            ->assertSet('week', '2026-10-05')
            ->assertSeeText('5 – 11 Okt 2026')
            ->assertSeeText('Kam, 8 Okt')
            ->set('week', '2026-10-14')
            ->assertSet('week', '2026-10-12')
            ->call('previousWeek')
            ->assertSet('week', '2026-10-05')
            ->call('nextWeek')
            ->assertSet('week', '2026-10-12')
            ->assertSeeText('Belum ada data history untuk periode ini.');
    }

    public function test_history_per_month_and_per_year(): void
    {
        $this->snapshot('2025-12-31', '16:00', ['FINISH GOODS' => 10]);
        $this->snapshot('2026-01-15', '07:00', ['FINISH GOODS' => 20]);
        $this->snapshot('2026-10-09', '07:00', ['FINISH GOODS' => 30]);

        $service = app(StockHistoryService::class);
        $months = $service->history('month', '2026');
        $years = $service->history('year', null);

        $this->assertSame(['Jan 2026', 'Okt 2026'], $months['labels']);
        $this->assertSame([20, 30], $months['total']);
        $this->assertSame(['2025', '2026'], $years['labels']);
        $this->assertSame([10, 30], $years['total']);
        $this->assertSame(['2026', '2025'], $service->availableYears());
    }

    public function test_history_page_renders_charts_for_the_latest_day(): void
    {
        $this->snapshot('2026-10-09', '07:00', ['FINISH GOODS' => 100, 'STORE 3' => 40]);

        $this->get(route('stock-history.index'))
            ->assertOk()
            ->assertSeeLivewire(Index::class)
            ->assertSee('stockHistoryCharts', false)
            ->assertSee('data-store="FINISH GOODS"', false)
            ->assertSee('data-store="STORE 3"', false)
            ->assertSeeText('Setiap 15 menit');
    }

    public function test_history_page_shows_empty_state_without_data(): void
    {
        Livewire::test(Index::class)
            ->assertSeeText('Belum ada data history untuk periode ini.')
            ->assertDontSeeHtml('stockHistoryCharts');
    }

    public function test_schedule_can_be_changed_from_the_page(): void
    {
        Livewire::test(Index::class)
            ->set('scheduleMode', 'times')
            ->set('times', '16:00, 07:00')
            ->call('saveSchedule')
            ->assertHasNoErrors();

        $this->assertSame('times', app(StockHistoryService::class)->schedule()['mode']);
        $this->assertSame(['07:00', '16:00'], app(StockHistoryService::class)->schedule()['times']);

        Livewire::test(Index::class)
            ->set('scheduleMode', 'times')
            ->set('times', '7 pagi')
            ->call('saveSchedule')
            ->assertHasErrors('times');
    }

    public function test_snapshot_can_be_taken_from_the_page(): void
    {
        Livewire::test(Index::class)
            ->call('takeSnapshotNow')
            ->assertSeeHtml('data-store="FINISH GOODS"');

        $this->assertSame('manual', StockSnapshot::first()->source);
    }

    /**
     * A local (WIB) time expressed in UTC, the application timezone.
     */
    protected function utc(string $time): CarbonImmutable
    {
        return $this->wib($time)->utc();
    }

    protected function wib(string $time): CarbonImmutable
    {
        return CarbonImmutable::parse($time, 'Asia/Jakarta');
    }

    /**
     * @param  array<string, int>  $stores
     */
    protected function snapshot(string $date, string $time, array $stores): StockSnapshot
    {
        $snapshot = StockSnapshot::factory()->create([
            'taken_at' => $this->wib("{$date} {$time}")->setTimezone(config('app.timezone')),
            'local_date' => $date,
            'local_time' => $time,
            'total_stock' => array_sum($stores),
        ]);

        foreach ($stores as $store => $total) {
            $snapshot->stores()->create(['store' => $store, 'total_stock' => $total, 'item_count' => 1]);
        }

        return $snapshot;
    }
}
