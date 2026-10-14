<?php

namespace Tests\Feature;

use App\Console\Commands\TakeMachineSnapshot;
use App\Livewire\MachineStock\Index;
use App\Livewire\MachineStock\Machines;
use App\Models\MachineSnapshot;
use App\Models\MachineStockControl;
use App\Models\MonitoredMachine;
use App\Models\Partlist;
use App\Services\MachineStockService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class MachineStockTest extends TestCase
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
                ['id' => '1', 'store' => 'FINISH GOODS', 'line' => 'SW 5.3', 'part_no' => 'A', 'rack_no' => 'F1', 'stock' => '120', 'stock_prod' => '30', 'std_min' => '60'],
                ['id' => '2', 'store' => 'WIPS 3', 'line' => 'sw 5.3 ', 'part_no' => 'B', 'rack_no' => 'W1', 'stock' => '-5', 'stock_prod' => '10', 'std_min' => '20'],
                ['id' => '3', 'store' => 'FINISH GOODS', 'line' => 'SW 5.2', 'part_no' => 'C', 'rack_no' => 'F2', 'stock' => '999', 'stock_prod' => '0', 'std_min' => '0'],
            ]]),
        ]);

        Partlist::factory()->create(['part_no' => 'A', 'level' => 'FINISH GOODS', 'qty_kbn' => 30, 'stock_max' => 3, 'po_day' => '45']);
        Partlist::factory()->create(['part_no' => 'B', 'level' => 'WIPS 3', 'po_day' => null]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_slots_run_every_two_hours_from_seven_and_early_hours_belong_to_the_previous_day(): void
    {
        $service = app(MachineStockService::class);

        $this->assertSame('07:00', $service->slotFor($this->wib('2026-10-13 08:59')));
        $this->assertSame('09:00', $service->slotFor($this->wib('2026-10-13 09:30')));
        $this->assertSame('01:00', $service->slotFor($this->wib('2026-10-14 01:59')));
        $this->assertSame('05:00', $service->slotFor($this->utc('2026-10-14 06:10')));
        $this->assertSame('2026-10-13', $service->productionDate($this->wib('2026-10-14 06:59')));
        $this->assertSame('2026-10-14', $service->productionDate($this->wib('2026-10-14 07:00')));
    }

    public function test_snapshot_stores_every_part_of_the_machine_line_with_its_po_day(): void
    {
        CarbonImmutable::setTestNow($this->utc('2026-10-14 03:15'));
        $machine = MonitoredMachine::factory()->create(['line' => 'SW 5.3']);

        $snapshot = app(MachineStockService::class)->takeSnapshots(collect([$machine]))->first();

        $this->assertSame('2026-10-14', $snapshot->local_date);
        $this->assertSame('2026-10-13', $snapshot->production_date);
        $this->assertSame('03:00', $snapshot->slot_time);
        $this->assertSame([
            ['part_no' => 'A', 'stock' => 120, 'stock_prod' => 30, 'po_day' => 45],
            ['part_no' => 'B', 'stock' => 0, 'stock_prod' => 10, 'po_day' => null],
        ], $snapshot->items()->orderBy('part_no')->get(['part_no', 'stock', 'stock_prod', 'po_day'])->toArray());
    }

    public function test_command_takes_one_snapshot_per_active_machine_per_slot(): void
    {
        $machine = MonitoredMachine::factory()->create(['line' => 'SW 5.3']);
        MonitoredMachine::factory()->inactive()->create(['line' => 'SW 5.2']);

        CarbonImmutable::setTestNow($this->utc('2026-10-13 07:00'));
        $this->artisan('stock:machines')->assertSuccessful();

        CarbonImmutable::setTestNow($this->utc('2026-10-13 08:59'));
        $this->artisan('stock:machines')->assertSuccessful();
        $this->assertSame(1, MachineSnapshot::count());

        CarbonImmutable::setTestNow($this->utc('2026-10-13 09:01'));
        $this->artisan('stock:machines')->assertSuccessful();

        $this->assertSame(['07:00', '09:00'], $machine->snapshots()->orderBy('id')->pluck('slot_time')->all());
        $this->assertSame(2, MachineSnapshot::count());
    }

    public function test_failed_api_call_stores_nothing_and_waits_before_retrying(): void
    {
        MonitoredMachine::factory()->create(['line' => 'SW 5.3']);
        $this->apiFails = true;

        $this->artisan('stock:machines')->assertFailed();
        $this->assertTrue(Cache::has(TakeMachineSnapshot::RETRY_CACHE_KEY));

        $this->apiFails = false;
        $this->artisan('stock:machines')->assertSuccessful();
        $this->assertSame(0, MachineSnapshot::count());

        $this->artisan('stock:machines --force')->assertSuccessful();
        $this->assertSame('manual', MachineSnapshot::sole()->source);
    }

    public function test_command_is_scheduled(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('stock:machines')->assertSuccessful();
    }

    public function test_day_chart_runs_from_seven_to_seven_with_min_and_max_from_days_times_po_day(): void
    {
        $machine = MonitoredMachine::factory()->create();
        $this->control($machine, 'A', 1, 2);
        $this->snapshot($machine, '2026-10-13 06:30', ['A' => [999, 999, 30]]);
        $this->snapshot($machine, '2026-10-13 07:05', ['A' => [100, 40, 30]]);
        $this->snapshot($machine, '2026-10-13 12:00', ['A' => [20, 50, 30]]);
        $this->snapshot($machine, '2026-10-14 03:00', ['A' => [70, 0, 30]]);
        $this->snapshot($machine, '2026-10-14 07:00', ['A' => [999, 999, 30]]);

        $chart = app(MachineStockService::class)->chart($machine, 'day', '2026-10-13');

        $this->assertSame(['07:00', '09:00', '11:00', '13:00', '15:00', '17:00', '19:00', '21:00', '23:00', '01:00', '03:00', '05:00'], $chart['labels']);
        $this->assertSame(3, $chart['snapshot_count']);
        $this->assertSame([100, null, 20, null, null, null, null, null, null, null, 70, null], $chart['parts'][0]['stock']);
        $this->assertSame(array_fill(0, 12, 30), $chart['parts'][0]['min']);
        $this->assertSame(array_fill(0, 12, 60), $chart['total']['max']);
        $this->assertSame([30, 1.0, 2.0], [$chart['parts'][0]['po_day'], $chart['parts'][0]['min_days'], $chart['parts'][0]['max_days']]);
        // 07:00 stock over max, 11:00 stock below min, 03:00 stock prod below min and stock over max.
        $this->assertSame(4, $chart['parts'][0]['alerts']);
    }

    public function test_each_part_has_its_own_setting_valid_from_when_it_is_saved_until_it_is_changed(): void
    {
        $machine = MonitoredMachine::factory()->create();
        $this->control($machine, 'A', 1, 2);
        $this->control($machine, 'B', 1, 2);
        $this->snapshot($machine, '2026-10-13 07:00', ['A' => [10, 10, 10], 'B' => [10, 10, 10]]);
        $this->snapshot($machine, '2026-10-13 09:00', ['A' => [10, 10, 10], 'B' => [10, 10, 10]]);

        CarbonImmutable::setTestNow($this->utc('2026-10-13 08:30'));
        $service = app(MachineStockService::class);
        $changed = $service->saveControls($machine, [
            ['store' => 'FINISH GOODS', 'part_no' => 'A', 'min_days' => '0.5', 'max_days' => '3'],
            ['store' => 'FINISH GOODS', 'part_no' => 'B', 'min_days' => '1', 'max_days' => '2'],
        ]);

        $parts = collect($service->chart($machine, 'day', '2026-10-13')['parts'])->keyBy('part_no');

        $this->assertSame(1, $changed);
        $this->assertSame(3, $machine->controls()->count());
        $this->assertSame([10, 5, 5], array_slice($parts['A']['min'], 0, 3));
        $this->assertSame([20, 30, 30], array_slice($parts['A']['max'], 0, 3));
        $this->assertSame([10, 10, 10], array_slice($parts['B']['min'], 0, 3));
    }

    public function test_parts_without_a_setting_or_po_day_have_no_min_or_max(): void
    {
        CarbonImmutable::setTestNow($this->utc('2026-10-13 10:00'));
        $machine = MonitoredMachine::factory()->create(['line' => 'SW 5.3']);
        $this->control($machine, 'A', 1, 2);
        $this->control($machine, 'B', 1, 2, 'WIPS 3');
        $service = app(MachineStockService::class);
        $service->takeSnapshots(collect([$machine]));

        $latest = collect($service->latest($machine)['items'])->keyBy('part_no');

        $this->assertSame(['min' => 45, 'max' => 90, 'stock_days' => 2.7, 'stock_status' => 'over', 'stock_prod_status' => 'below_min'], collect($latest['A'])->only(['min', 'max', 'stock_days', 'stock_status', 'stock_prod_status'])->all());
        $this->assertSame(['po_day' => null, 'min_days' => 1.0, 'min' => null, 'stock_status' => 'no_param'], collect($latest['B'])->only(['po_day', 'min_days', 'min', 'stock_status'])->all());

        $machine->controls()->where('part_no', 'B')->delete();
        $this->assertNull(collect($service->latest($machine)['items'])->firstWhere('part_no', 'B')['min_days']);
    }

    public function test_week_chart_starts_monday_seven_and_month_chart_on_the_first(): void
    {
        $machine = MonitoredMachine::factory()->create();
        $this->snapshot($machine, '2026-10-12 06:00', ['A' => [1, 0, 10]]);
        $this->snapshot($machine, '2026-10-12 07:00', ['A' => [10, 0, 10]]);
        $this->snapshot($machine, '2026-10-19 05:00', ['A' => [20, 0, 10]]);
        $this->snapshot($machine, '2026-10-01 07:00', ['A' => [30, 0, 10]]);

        $service = app(MachineStockService::class);
        $week = $service->chart($machine, 'week', '2026-10-15');
        $month = $service->chart($machine, 'month', '2026-10');

        $this->assertCount(7 * 12, $week['labels']);
        $this->assertSame('Sen, 12 Okt 07:00', $week['labels'][0]);
        $this->assertSame('Sen, 19 Okt 05:00', $week['labels'][83]);
        $this->assertSame(10, $week['total']['stock'][0]);
        $this->assertSame(20, $week['total']['stock'][83]);
        $this->assertSame(2, $week['snapshot_count']);

        $this->assertCount(31 * 12, $month['labels']);
        $this->assertSame('1', $month['ticks'][0]);
        $this->assertSame(30, $month['total']['stock'][0]);
        $this->assertSame('2026-10-12', $service->normalizeAnchor('week', '2026-10-18'));
        $this->assertSame('2026-09', $service->shiftAnchor('month', '2026-10', -1));
    }

    public function test_adding_a_machine_lists_its_parts_to_set_min_and_max_each(): void
    {
        Livewire::test(Machines::class)
            ->call('create')
            ->set('line', ' sw  5.3 ')
            ->assertCount('parts', 2)
            ->assertSet('parts.0.part_no', 'A')
            ->assertSet('parts.0.po_day', 45)
            ->assertSet('parts.1.min_days', '1')
            ->set('bulkMinDays', '2')
            ->set('bulkMaxDays', '4')
            ->call('applyToAll')
            ->assertSet('parts.1.max_days', '4')
            ->set('parts.0.min_days', '0.5')
            ->set('parts.0.max_days', '1.5')
            ->call('save')
            ->assertHasNoErrors();

        $machine = MonitoredMachine::sole();
        $controls = $machine->controls()->orderBy('part_no')->get(['store', 'part_no', 'min_days', 'max_days'])->toArray();

        $this->assertSame('SW 5.3', $machine->line);
        $this->assertSame([
            ['store' => 'FINISH GOODS', 'part_no' => 'A', 'min_days' => 0.5, 'max_days' => 1.5],
            ['store' => 'WIPS 3', 'part_no' => 'B', 'min_days' => 2.0, 'max_days' => 4.0],
        ], $controls);
        $this->assertSame(2, $machine->snapshots()->sole()->item_count);
    }

    public function test_min_and_max_accept_any_decimal_including_a_comma(): void
    {
        Livewire::test(Machines::class)
            ->call('create')
            ->set('line', 'SW 5.3')
            ->set('parts.0.min_days', '0.1')
            ->set('parts.0.max_days', '2,4')
            ->set('parts.1.min_days', '0,125')
            ->set('parts.1.max_days', '0.3337')
            ->call('save')
            ->assertHasNoErrors();

        $controls = MonitoredMachine::sole()->controls()->orderBy('part_no')->get();

        $this->assertSame([0.1, 2.4], [$controls[0]->min_days, $controls[0]->max_days]);
        $this->assertSame([0.125, 0.334], [$controls[1]->min_days, $controls[1]->max_days]);
        $this->assertSame('0.125', Machines::formatDays($controls[1]->min_days));

        Livewire::withQueryParams(['edit' => MonitoredMachine::sole()->id])
            ->test(Machines::class)
            ->assertSet('parts.0.min_days', '0.1')
            ->assertSee('step="any"', false);
    }

    public function test_editing_a_machine_shows_current_settings_and_only_stores_changes(): void
    {
        $machine = MonitoredMachine::factory()->create(['line' => 'SW 5.3']);
        $this->control($machine, 'A', 1, 2);

        Livewire::withQueryParams(['edit' => $machine->id])
            ->test(Machines::class)
            ->assertSet('showForm', true)
            ->assertSet('parts.0.min_days', '1')
            ->assertSet('parts.1.since', null)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(2, $machine->controls()->count());
        $this->assertSame(1, $machine->controls()->where('part_no', 'B')->count());
    }

    public function test_a_machine_must_exist_in_stock_part_all_be_unique_and_have_max_above_min(): void
    {
        MonitoredMachine::factory()->create(['line' => 'SW 5.3']);

        Livewire::test(Machines::class)
            ->call('create')
            ->set('line', 'SW 9.9')
            ->call('save')
            ->assertHasErrors(['line'])
            ->set('line', 'SW 5.3')
            ->set('parts.0.min_days', '2')
            ->set('parts.0.max_days', '1')
            ->call('save')
            ->assertHasErrors(['line' => 'unique', 'parts.0.max_days' => 'gt']);

        $this->assertSame(1, MonitoredMachine::count());
    }

    public function test_deleting_a_machine_removes_its_history_and_parameters(): void
    {
        $machine = MonitoredMachine::factory()->create();
        $this->control($machine, 'A', 1, 2);
        $this->snapshot($machine, '2026-10-13 08:00', ['A' => [1, 1, 1]]);

        Livewire::test(Machines::class)
            ->call('confirmDelete', $machine->id)
            ->call('delete');

        $this->assertDatabaseCount('monitored_machines', 0);
        $this->assertDatabaseCount('machine_snapshot_items', 0);
        $this->assertDatabaseCount('machine_stock_controls', 0);
    }

    public function test_control_page_shows_charts_and_how_many_parts_are_set(): void
    {
        CarbonImmutable::setTestNow($this->utc('2026-10-14 03:00'));
        $machine = MonitoredMachine::factory()->create(['line' => 'SW 5.3']);
        $this->control($machine, 'A', 1, 2);
        $this->snapshot($machine, '2026-10-13 21:00', ['A' => [100, 40, 30], 'B' => [25, 25, 20]]);

        $this->get(route('machine-stock.index'))
            ->assertOk()
            ->assertSee('SW 5.3')
            ->assertSee('1 dari 2 part diatur')
            ->assertSee(route('machine-stock.machines', ['edit' => $machine->id]), false)
            ->assertSee('machineStockCharts', false);

        Livewire::test(Index::class)
            ->assertSet('machine', $machine->id)
            ->assertSet('anchor', '2026-10-13')
            ->set('onlyAlerts', true)
            ->assertCount('visibleParts', 1)
            ->set('period', 'week')
            ->assertSet('anchor', '2026-10-12')
            ->set('period', 'month')
            ->assertSet('anchor', '2026-10');
    }

    public function test_control_page_invites_adding_a_machine_when_none_exist(): void
    {
        $this->get(route('machine-stock.index'))->assertOk()->assertSee('Belum ada mesin yang dimonitor.');
        $this->get(route('machine-stock.machines'))->assertOk()->assertSee('Tambah Mesin');
    }

    protected function control(MonitoredMachine $machine, string $partNo, float $minDays, float $maxDays, string $store = 'FINISH GOODS'): void
    {
        MachineStockControl::factory()->for($machine, 'machine')->create([
            'store' => $store,
            'part_no' => $partNo,
            'min_days' => $minDays,
            'max_days' => $maxDays,
        ]);
    }

    /**
     * @param  array<string, array{int, int, ?int}>  $parts  part_no => [stock, stock_prod, po_day]
     */
    protected function snapshot(MonitoredMachine $machine, string $localDateTime, array $parts): MachineSnapshot
    {
        $snapshot = MachineSnapshot::factory()->for($machine, 'machine')->at($localDateTime)->create(['item_count' => count($parts)]);

        foreach ($parts as $partNo => [$stock, $stockProd, $poDay]) {
            $snapshot->items()->create([
                'store' => 'FINISH GOODS',
                'part_no' => $partNo,
                'stock' => $stock,
                'stock_prod' => $stockProd,
                'po_day' => $poDay,
            ]);
        }

        return $snapshot;
    }

    protected function utc(string $time): CarbonImmutable
    {
        return $this->wib($time)->utc();
    }

    protected function wib(string $time): CarbonImmutable
    {
        return CarbonImmutable::parse($time, 'Asia/Jakarta');
    }
}
