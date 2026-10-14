<?php

namespace Tests\Feature;

use App\Livewire\DailyStock\Index;
use App\Mail\DailyStockReportMail;
use App\Models\DailyStock;
use App\Models\Partlist;
use App\Models\ReportRecipient;
use App\Services\DailyStockService;
use App\Services\ResumeChartImage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;
use Tests\TestCase;

class DailyStockTest extends TestCase
{
    use RefreshDatabase;

    protected bool $apiFails = false;

    protected int $stockA = 100;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.sos.url' => 'https://sos.test/sos/Ajax/tData',
            'services.sos.key' => 'secret-key',
            'services.sos.history_timezone' => 'Asia/Jakarta',
        ]);

        Storage::fake('local');
        Mail::fake();

        Http::fake([
            'sos.test/*' => fn () => $this->apiFails ? Http::response('error', 500) : Http::response(['data' => [
                ['id' => '1', 'store' => 'FINISH GOODS', 'part_no' => 'A', 'stock' => (string) $this->stockA, 'std_min' => '0', 'stock_prod' => '0'],
                ['id' => '2', 'store' => 'FINISH GOODS', 'part_no' => 'A', 'stock' => '20', 'std_min' => '0', 'stock_prod' => '0'],
                ['id' => '3', 'store' => 'FINISH GOODS', 'part_no' => 'B', 'stock' => '-5', 'std_min' => '0', 'stock_prod' => '0'],
                ['id' => '4', 'store' => 'OUTGOING', 'part_no' => 'A', 'stock' => '999', 'std_min' => '0', 'stock_prod' => '0'],
                ['id' => '5', 'store' => 'FINISH GOODS', 'part_no' => 'D', 'stock' => '4500', 'std_min' => '0', 'stock_prod' => '0'],
            ]]),
        ]);

        Partlist::factory()->create([
            'code' => 'AFINISH GOODS', 'level' => 'FINISH GOODS', 'part_no' => 'A', 'qty_kbn' => 10, 'po_day' => '40',
            'process' => 'SW', 'line' => 'SW 1.0', 'rack_no' => 'A1-01-A', 'stock_min' => 2, 'stock_max' => 8,
        ]);
        Partlist::factory()->create([
            'code' => 'BFINISH GOODS', 'level' => 'FINISH GOODS', 'part_no' => 'B', 'qty_kbn' => 5, 'po_day' => '10',
            'process' => 'PR', 'line' => 'PR 03', 'rack_no' => 'A1-02-A',
        ]);
        Partlist::factory()->create([
            'code' => 'CFINISH GOODS', 'level' => 'FINISH GOODS', 'part_no' => 'C', 'qty_kbn' => 5, 'po_day' => '0',
            'process' => 'PR', 'line' => 'PR 03', 'rack_no' => '-',
        ]);
        Partlist::factory()->create([
            'code' => 'DFINISH GOODS', 'level' => 'FINISH GOODS', 'part_no' => 'D', 'qty_kbn' => 3000, 'po_day' => '400',
            'process' => 'PG', 'line' => 'PG 04', 'rack_no' => 'A1-03-A',
        ]);
        Partlist::factory()->create(['code' => 'AOUTGOING', 'level' => 'OUTGOING', 'part_no' => 'A']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_default_schedule_is_weekdays_at_seven(): void
    {
        $dailyStock = app(DailyStockService::class);

        $this->assertSame(['time' => '07:00', 'days' => [1, 2, 3, 4, 5], 'working_days' => 22], $dailyStock->schedule());
        $this->assertSame('Senin, Selasa, Rabu, Kamis, Jumat jam 07:00', $dailyStock->describeSchedule());
        $this->assertFalse($dailyStock->isDue($this->wib('2026-10-09 06:59')));
        $this->assertTrue($dailyStock->isDue($this->wib('2026-10-09 07:00')));
        $this->assertTrue($dailyStock->isDue($this->wib('2026-10-09 10:30')));
        $this->assertFalse($dailyStock->isDue($this->wib('2026-10-10 07:00')));
        $this->assertSame('2026-10-12 07:00', $dailyStock->nextRunAt($this->wib('2026-10-09 07:00'))->format('Y-m-d H:i'));
    }

    public function test_schedule_can_be_customised(): void
    {
        $dailyStock = app(DailyStockService::class);
        $dailyStock->saveSchedule(['time' => '06:30', 'days' => [6, 7, 1], 'working_days' => 20]);

        $this->assertSame(['time' => '06:30', 'days' => [1, 6, 7], 'working_days' => 20], $dailyStock->schedule());
        $this->assertTrue($dailyStock->isDue($this->wib('2026-10-10 06:30')));
        $this->assertFalse($dailyStock->isDue($this->wib('2026-10-09 06:30')));
        $this->assertSame('2026-10-10 06:30', $dailyStock->nextRunAt($this->wib('2026-10-09 07:00'))->format('Y-m-d H:i'));
    }

    public function test_record_stores_finish_goods_stock_per_part_and_builds_the_excel_for_that_day(): void
    {
        CarbonImmutable::setTestNow($this->utc('2026-10-09 07:00'));

        $recorded = app(DailyStockService::class)->record();

        $this->assertSame('2026-10-09', $recorded->stock_date);
        $this->assertSame('07:00', $recorded->local_time);
        $this->assertSame(['A' => 120, 'B' => 0, 'D' => 4500], $recorded->items()->pluck('stock_pcs', 'part_no')->all());
        Storage::disk('local')->assertExists('daily-stock/Daily Stock FG 2026-10-09.xlsx');

        $sheet = $this->reportSheet('2026-10-09');
        $partRow = $this->findRow($sheet, 'B', 'A');

        $this->assertSame('09 OCT', $sheet->getTitle());
        $this->assertSame(880, $sheet->getCell("I{$partRow}")->getCalculatedValue());
        $this->assertEquals(4, $sheet->getCell("K{$partRow}")->getCalculatedValue());
        $this->assertSame(12, $sheet->getCell("O{$partRow}")->getValue());
        $this->assertEquals(3, $sheet->getCell("P{$partRow}")->getCalculatedValue());
        $this->assertEquals(12, $sheet->getCell("Q{$partRow}")->getCalculatedValue());
        $this->assertSame('OK', $sheet->getCell("S{$partRow}")->getCalculatedValue());
        $this->assertSame(120, $sheet->getCell("T{$partRow}")->getValue());
        $this->assertSame('NOT OK', $sheet->getCell('S'.$this->findRow($sheet, 'B', 'B'))->getCalculatedValue());
        $this->assertSame('-', $sheet->getCell('S'.$this->findRow($sheet, 'B', 'C'))->getCalculatedValue());
        $this->assertSame($this->findRow($sheet, 'B', 'B') + 1, $this->findRow($sheet, 'B', 'D'));
        $this->assertSame(1, $sheet->getCell('O'.$this->findRow($sheet, 'N', 'PR'))->getCalculatedValue());
    }

    public function test_resume_tables_sit_side_by_side_and_count_cumulatively(): void
    {
        CarbonImmutable::setTestNow($this->utc('2026-10-09 07:00'));
        app(DailyStockService::class)->record();

        $sheet = $this->reportSheet('2026-10-09');

        $this->assertSame('STOCK < 1.5 DAY', $sheet->getCell('N6')->getValue());
        $this->assertSame('STOCK < 1.0 DAY', $sheet->getCell('S6')->getValue());
        $this->assertSame(['PG', 'PR', 'SW'], [$sheet->getCell('N9')->getValue(), $sheet->getCell('N10')->getValue(), $sheet->getCell('N11')->getValue()]);
        $this->assertSame('TOTAL:', $sheet->getCell('N12')->getValue());
        $this->assertSame('TOTAL:', $sheet->getCell('S12')->getValue());

        // The threshold is outside the quotes so Excel with a comma decimal separator (Indonesian locale) still compares numbers.
        $this->assertStringContainsString('"<"&1.5', $sheet->getCell('O10')->getValue());
        $this->assertStringContainsString('"<"&1', $sheet->getCell('T10')->getValue());

        // Part B (PR) is below 1.0 day, so it is counted in both resumes; part D (PG) covers exactly 1.0 day.
        $this->assertSame(1, $sheet->getCell('O10')->getCalculatedValue());
        $this->assertSame(1, $sheet->getCell('T10')->getCalculatedValue());
        $this->assertSame(1, $sheet->getCell('O9')->getCalculatedValue());
        $this->assertSame(0, $sheet->getCell('T9')->getCalculatedValue());
        $this->assertEquals(2, $sheet->getCell('O12')->getCalculatedValue());
        $this->assertEquals(1, $sheet->getCell('T12')->getCalculatedValue());
    }

    public function test_dashboard_resume_uses_the_same_processes_and_counts_as_the_excel(): void
    {
        CarbonImmutable::setTestNow($this->utc('2026-10-09 07:00'));
        $recorded = app(DailyStockService::class)->record();

        $summary = app(DailyStockService::class)->coverageSummary($recorded);

        $this->assertSame(['PG', 'PR', 'SW'], array_keys($summary));
        $this->assertSame(1, $summary['PR']['not_ok']);
        $this->assertSame(1, $summary['PR']['under_one']);
        $this->assertSame(0, $summary['SW']['not_ok']);

        Livewire::test(Index::class)
            ->assertSeeInOrder(['STOCK &lt; 1.5 DAY', 'PG', 'PR', 'SW', 'TOTAL:', 'STOCK &lt; 1.0 DAY', 'PG', 'PR', 'SW', 'TOTAL:'], false);
    }

    public function test_excel_only_has_columns_for_that_day(): void
    {
        CarbonImmutable::setTestNow($this->utc('2026-10-09 07:00'));
        app(DailyStockService::class)->record();

        $sheet = $this->reportSheet('2026-10-09');
        $headerRow = $this->findRow($sheet, 'A', 'NO');

        $this->assertSame('2026-10-09', ExcelDate::excelToDateTimeObject($sheet->getCell("O{$headerRow}")->getValue())->format('Y-m-d'));
        $this->assertSame('STOCK (Box)', $sheet->getCell('O'.($headerRow + 1))->getValue());
        $this->assertSame('TOTAL STOCK (Box)', $sheet->getCell("Q{$headerRow}")->getValue());
        $this->assertSame('REMARKS', $sheet->getCell("W{$headerRow}")->getValue());
        $this->assertSame('W', $sheet->getHighestColumn());
    }

    public function test_stock_is_rounded_down_and_delivery_per_day_rounded_up_to_whole_boxes(): void
    {
        CarbonImmutable::setTestNow($this->utc('2026-10-09 07:00'));
        $recorded = app(DailyStockService::class)->record();

        $sheet = $this->reportSheet('2026-10-09');
        $row = $this->findRow($sheet, 'B', 'D');

        // 4500 pcs / 3000 per box = 1 box; 400 pcs per day / 3000 per box rounds up to 1 box per day.
        $this->assertSame(1, $sheet->getCell("O{$row}")->getValue());
        $this->assertEquals(1, $sheet->getCell("K{$row}")->getCalculatedValue());
        $this->assertEquals(1, $sheet->getCell("P{$row}")->getCalculatedValue());
        $this->assertSame('NOT OK', $sheet->getCell("S{$row}")->getCalculatedValue());
        $this->assertSame(4500, $sheet->getCell("T{$row}")->getValue());

        $summary = app(DailyStockService::class)->coverageSummary($recorded);
        $this->assertSame(['parts' => 1, 'under_one' => 0, 'not_ok' => 1, 'ok' => 0, 'over' => 0], $summary['PG']);
    }

    public function test_press_parts_take_the_last_process_from_the_fix_machine(): void
    {
        $this->assertSame('PR', DailyStockService::lastProcess('PG', 'PR 06'));
        $this->assertSame('PG', DailyStockService::lastProcess('PG', 'PG 13'));
        $this->assertSame('PG', DailyStockService::lastProcess('PG', 'PB 01'));
        $this->assertSame('PG', DailyStockService::lastProcess('PG', null));
        $this->assertSame('PLANT 2', DailyStockService::lastProcess('PLANT 2', 'PR 01'));
        $this->assertSame('SW', DailyStockService::lastProcess('ASSY', 'ASSY 1'));
        $this->assertSame('TD', DailyStockService::lastProcess('RWK', 'REWORK'));

        Partlist::factory()->create([
            'code' => 'EFINISH GOODS', 'level' => 'FINISH GOODS', 'part_no' => 'E', 'qty_kbn' => 10, 'po_day' => '40',
            'process' => 'PG', 'line' => 'PR 06', 'rack_no' => 'A1-04-A',
        ]);

        CarbonImmutable::setTestNow($this->utc('2026-10-09 07:00'));
        $recorded = app(DailyStockService::class)->record();

        $sheet = $this->reportSheet('2026-10-09');
        $this->assertSame('PR', $sheet->getCell('M'.$this->findRow($sheet, 'B', 'E'))->getValue());
        $this->assertSame(2, $sheet->getCell('O'.$this->findRow($sheet, 'N', 'PR'))->getCalculatedValue());
        $this->assertSame(2, app(DailyStockService::class)->coverageSummary($recorded)['PR']['under_one']);
    }

    public function test_resume_sheets_count_short_parts_and_boxes_for_each_recorded_day_of_the_month(): void
    {
        Partlist::factory()->create([
            'code' => 'EFINISH GOODS', 'level' => 'FINISH GOODS', 'part_no' => 'E', 'qty_kbn' => 10, 'po_day' => '40',
            'process' => 'PG', 'line' => 'PG 13', 'rack_no' => 'A1-04-A',
        ]);

        // 8 Oct: part A has 2 boxes for 4 boxes a day (0.5 day); 9 Oct: 12 boxes (3 days).
        $this->stockA = 0;
        CarbonImmutable::setTestNow($this->utc('2026-10-08 07:00'));
        app(DailyStockService::class)->record();

        $this->stockA = 100;
        CarbonImmutable::setTestNow($this->utc('2026-10-09 07:00'));
        app(DailyStockService::class)->record();

        $book = IOFactory::load(Storage::disk('local')->path('daily-stock/Daily Stock FG 2026-10-09.xlsx'));
        $this->assertSame(['09 OCT', 'Resume Stock FG (PG-Item)', 'Resume Stock FG (Item)', 'Resume Stock FG (Box)'], $book->getSheetNames());
        $this->assertSame('09 OCT', $book->getActiveSheet()->getTitle());

        // Day N of the month sits in column 2 + N: 8 Oct in J, 9 Oct in K, 10 Oct in L.
        $items = $book->getSheetByName('Resume Stock FG (Item)');
        $this->assertSame('STOCK < 1.5 DAY', $items->getCell('A4')->getValue());
        $this->assertSame('2026-10-01', ExcelDate::excelToDateTimeObject($items->getCell('C5')->getValue())->format('Y-m-d'));
        $swRow = $this->findRow($items, 'B', 'SW');
        $prRow = $this->findRow($items, 'B', 'PR');
        $this->assertSame(1, $items->getCell("J{$swRow}")->getValue());
        $this->assertSame(0, $items->getCell("K{$swRow}")->getValue());
        $this->assertSame(1, $items->getCell("K{$prRow}")->getValue());
        $this->assertNull($items->getCell("I{$swRow}")->getValue());
        $this->assertNull($items->getCell("L{$swRow}")->getValue());
        $this->assertEquals(4, $items->getCell('J'.$this->findRow($items, 'B', 'TOTAL:'))->getCalculatedValue());

        $boxes = $book->getSheetByName('Resume Stock FG (Box)');
        $this->assertSame(2, $boxes->getCell('J'.$this->findRow($boxes, 'B', 'SW'))->getValue());
        $this->assertSame(1, $boxes->getCell('K'.$this->findRow($boxes, 'B', 'PG'))->getValue());

        // Part D (PG 04) covers 1.0 day: below 1.5 but not below 1.0. Part E (PG 13) has no stock.
        $machines = $book->getSheetByName('Resume Stock FG (PG-Item)');
        $this->assertSame('FIX MACHINE', $machines->getCell('B5')->getValue());
        $this->assertSame(['PG 04', 'PG 13'], [$machines->getCell('B6')->getValue(), $machines->getCell('B7')->getValue()]);
        $this->assertSame([1, 1], [$machines->getCell('K6')->getValue(), $machines->getCell('K7')->getValue()]);
        $this->assertSame('STOCK < 1.0 DAY', $machines->getCell('A11')->getValue());
        $this->assertSame([0, 1], [$machines->getCell('K13')->getValue(), $machines->getCell('K14')->getValue()]);
    }

    public function test_resume_sheets_have_a_stacked_chart_with_the_total_line_under_their_tables(): void
    {
        CarbonImmutable::setTestNow($this->utc('2026-10-09 07:00'));
        app(DailyStockService::class)->record();

        $reader = IOFactory::createReader('Xlsx');
        $reader->setIncludeCharts(true);
        $book = $reader->load(Storage::disk('local')->path('daily-stock/Daily Stock FG 2026-10-09.xlsx'));
        $items = $book->getSheetByName('Resume Stock FG (Item)');
        $charts = $items->getChartCollection();

        $this->assertCount(0, $book->getSheetByName('09 OCT')->getChartCollection());
        $this->assertCount(2, $charts);
        $this->assertSame(['Grafik Stock FG < 1.5 Day', 'Grafik Stock FG < 1.0 Day'], array_map(fn ($chart) => $chart->getTitle()->getCaptionText(), [...$charts]));
        $this->assertSame('Item', $charts[0]->getYAxisLabel()->getCaptionText());
        $this->assertGreaterThan($this->findRow($items, 'B', 'TOTAL:'), (int) substr($charts[0]->getTopLeftCell(), 1));

        $plotArea = $charts[0]->getPlotArea();
        [$bars, $line] = $plotArea->getPlotGroup();
        $processCount = $this->findRow($items, 'B', 'TOTAL:') - 6;
        $this->assertSame([DataSeries::TYPE_BARCHART, DataSeries::GROUPING_STACKED, $processCount], [$bars->getPlotType(), $bars->getPlotGrouping(), $bars->getPlotSeriesCount()]);
        $this->assertSame(DataSeries::TYPE_LINECHART, $line->getPlotType());
        $this->assertStringEndsWith('$C$'.$this->findRow($items, 'B', 'TOTAL:').':$AG$'.$this->findRow($items, 'B', 'TOTAL:'), array_values($line->getPlotValues())[0]->getDataSource());
        $this->assertNotNull($plotArea->getDataTable());
        $this->assertSame(DataSeries::EMPTY_AS_SPAN, $charts[0]->getDisplayBlanksAs());

        $this->assertSame('Grafik Stock FG PG < 1.5 Day', $book->getSheetByName('Resume Stock FG (PG-Item)')->getChartCollection()[0]->getTitle()->getCaptionText());
        $this->assertSame('Box', $book->getSheetByName('Resume Stock FG (Box)')->getChartCollection()[0]->getYAxisLabel()->getCaptionText());
    }

    public function test_dashboard_shows_the_monthly_resume_charts_of_the_excel_resume_sheets(): void
    {
        // 8 Oct: A (SW) and B (PR) have nothing, D (PG) covers exactly 1.0 day; 9 Oct: A covers 3 days.
        $this->stockA = 0;
        CarbonImmutable::setTestNow($this->utc('2026-10-08 07:00'));
        app(DailyStockService::class)->record();

        $this->stockA = 100;
        CarbonImmutable::setTestNow($this->utc('2026-10-09 07:00'));
        app(DailyStockService::class)->record();

        $this->get(route('daily-stock.index'))->assertOk()->assertSee('Resume bulanan Stock FG')->assertSee('dailyStockResumeCharts', false);

        $component = Livewire::test(Index::class)->assertSet('resumeMonth', '2026-10')->assertSet('resumeView', 'item');
        [$underOneAndHalf, $underOne] = $component->instance()->resumeCharts;

        $this->assertSame(['Grafik Stock FG < 1.5 Day', 'Grafik Stock FG < 1.0 Day'], [$underOneAndHalf['title'], $underOne['title']]);
        $this->assertCount(31, $underOneAndHalf['labels']);
        $this->assertSame([null, 3, 2, null], array_slice($underOneAndHalf['total'], 6, 4));
        $this->assertSame([2, 1], array_slice($underOne['total'], 7, 2));
        $sw = collect($underOneAndHalf['series'])->firstWhere('label', 'SW');
        $this->assertSame([1, 0], array_slice($sw['data'], 7, 2));
        $this->assertSame(array_column($underOneAndHalf['rows'], 'label'), array_column($underOneAndHalf['series'], 'label'));

        $component->set('resumeView', 'box');
        $this->assertSame(['Box', 3], [$component->instance()->resumeCharts[0]['unit'], $component->instance()->resumeCharts[0]['total'][7]]);

        $component->set('resumeView', 'pg_item');
        $this->assertSame('Grafik Stock FG PG < 1.5 Day', $component->instance()->resumeCharts[0]['title']);

        $component->call('shiftResumeMonth', -1)
            ->assertSet('resumeMonth', '2026-09')
            ->assertSee('Belum ada data Daily Stock di');
    }

    public function test_resume_chart_folds_the_smallest_pg_machines_into_lainnya_past_eight_series(): void
    {
        foreach (range(11, 19) as $machine) {
            Partlist::factory()->create([
                'code' => "PG{$machine}FINISH GOODS", 'level' => 'FINISH GOODS', 'part_no' => "PG-{$machine}", 'qty_kbn' => 10, 'po_day' => '10',
                'process' => 'PG', 'line' => "PG {$machine}", 'rack_no' => "B1-{$machine}-A",
            ]);
        }

        CarbonImmutable::setTestNow($this->utc('2026-10-09 07:00'));
        app(DailyStockService::class)->record();

        $component = Livewire::test(Index::class)->set('resumeView', 'pg_item');
        $chart = $component->instance()->resumeCharts[0];
        $lainnya = end($chart['series']);

        $this->assertCount(10, $chart['rows']);
        $this->assertCount(8, $chart['series']);
        $this->assertSame(range(0, 6), array_column(array_slice($chart['series'], 0, 7), 'slot'));
        $this->assertSame(['Lainnya (3)', null, 3], [$lainnya['label'], $lainnya['slot'], $lainnya['data'][8]]);
        $this->assertSame(10, $chart['total'][8]);
    }

    public function test_recording_twice_on_the_same_day_replaces_that_day(): void
    {
        CarbonImmutable::setTestNow($this->utc('2026-10-09 07:00'));
        app(DailyStockService::class)->record('manual');

        $this->stockA = 300;
        CarbonImmutable::setTestNow($this->utc('2026-10-09 08:00'));
        app(DailyStockService::class)->record();

        $this->assertDatabaseCount('daily_stocks', 1);
        $this->assertDatabaseCount('daily_stock_items', 3);
        $this->assertSame(320, DailyStock::first()->items()->where('part_no', 'A')->value('stock_pcs'));
    }

    public function test_failed_api_call_does_not_record_anything(): void
    {
        $this->apiFails = true;

        $this->artisan('stock:daily --force')->assertFailed();

        $this->assertDatabaseCount('daily_stocks', 0);
        Storage::disk('local')->assertMissing('daily-stock/Daily Stock FG 2026-10-09.xlsx');
    }

    public function test_command_records_once_per_day_when_due(): void
    {
        CarbonImmutable::setTestNow($this->utc('2026-10-09 06:59'));
        $this->artisan('stock:daily')->assertSuccessful();
        $this->assertDatabaseCount('daily_stocks', 0);

        CarbonImmutable::setTestNow($this->utc('2026-10-09 07:00'));
        $this->artisan('stock:daily')->assertSuccessful();
        $this->assertSame('schedule', DailyStock::first()->source);

        CarbonImmutable::setTestNow($this->utc('2026-10-09 07:01'));
        $this->artisan('stock:daily')->assertSuccessful();
        $this->assertSame('07:00', DailyStock::first()->local_time);

        $this->artisan('stock:daily --force')->assertSuccessful();
        $this->assertSame('manual', DailyStock::first()->source);
    }

    public function test_scheduled_run_emails_the_excel_to_active_users_only(): void
    {
        ReportRecipient::factory()->create(['name' => 'Budi', 'email' => 'budi@example.com']);
        ReportRecipient::factory()->create(['name' => 'Ani', 'email' => 'ani@example.com']);
        ReportRecipient::factory()->inactive()->create(['email' => 'off@example.com']);

        CarbonImmutable::setTestNow($this->utc('2026-10-09 07:00'));
        $this->artisan('stock:daily')
            ->expectsOutputToContain('Email terkirim ke 2 user.')
            ->assertSuccessful();

        Mail::assertSent(DailyStockReportMail::class, 2);
        Mail::assertSent(DailyStockReportMail::class, fn (DailyStockReportMail $mail) => $mail->hasTo('budi@example.com') && $mail->recipientName === 'Budi');
        Mail::assertSent(DailyStockReportMail::class, fn (DailyStockReportMail $mail) => $mail->hasTo('ani@example.com'));
        Mail::assertNotSent(DailyStockReportMail::class, fn (DailyStockReportMail $mail) => $mail->hasTo('off@example.com'));
        Mail::assertSent(DailyStockReportMail::class, fn (DailyStockReportMail $mail) => $mail->reportPath === 'daily-stock/Daily Stock FG 2026-10-09.xlsx');

        $recorded = DailyStock::first();
        $this->assertSame(2, $recorded->emailed_count);
        $this->assertNotNull($recorded->emailed_at);

        CarbonImmutable::setTestNow($this->utc('2026-10-09 07:05'));
        $this->artisan('stock:daily')->assertSuccessful();
        Mail::assertSent(DailyStockReportMail::class, 2);
    }

    public function test_report_email_embeds_the_monthly_resume_charts_in_its_body(): void
    {
        CarbonImmutable::setTestNow($this->utc('2026-10-09 07:00'));
        $recorded = app(DailyStockService::class)->record();
        ReportRecipient::factory()->create(['name' => 'Budi', 'email' => 'budi@example.com']);

        app(DailyStockService::class)->sendReport($recorded);

        Mail::assertSent(DailyStockReportMail::class, function (DailyStockReportMail $mail) {
            return array_column($mail->charts, 'title') === ['Grafik Stock FG < 1.5 Day', 'Grafik Stock FG < 1.0 Day']
                && str_starts_with($mail->charts[0]['png'], "\x89PNG");
        });

        // Really send it through the array mailer: the charts travel as inline images referenced from the HTML.
        $mail = new DailyStockReportMail($recorded, [], app(DailyStockService::class)->reportPath('2026-10-09'), 'Budi', app(DailyStockService::class)->reportCharts($recorded));
        $mailer = (new MailManager(app()))->mailer('array');
        $mailer->to('budi@example.com')->send($mail);
        $message = $mailer->getSymfonyTransport()->messages()->last()->getOriginalMessage();

        $inline = collect($message->getAttachments())->filter(fn ($part) => $part->getDisposition() === 'inline');
        $this->assertCount(2, $inline);
        $this->assertSame('image/png', $inline->first()->getMediaType().'/'.$inline->first()->getMediaSubtype());
        $this->assertStringContainsString('src="cid:', $message->getHtmlBody());
        $this->assertStringContainsString('Resume bulanan', $message->getHtmlBody());
    }

    public function test_report_email_is_still_sent_when_the_charts_cannot_be_drawn(): void
    {
        CarbonImmutable::setTestNow($this->utc('2026-10-09 07:00'));
        $recorded = app(DailyStockService::class)->record();
        ReportRecipient::factory()->create(['email' => 'budi@example.com']);
        $this->mock(ResumeChartImage::class)->shouldReceive('png')->andThrow(new RuntimeException('GD rusak'));

        $result = app(DailyStockService::class)->sendReport($recorded);

        $this->assertSame(1, $result['sent']);
        Mail::assertSent(DailyStockReportMail::class, fn (DailyStockReportMail $mail) => $mail->charts === []);
    }

    public function test_scheduled_run_without_recipients_sends_nothing(): void
    {
        CarbonImmutable::setTestNow($this->utc('2026-10-09 07:00'));

        $this->artisan('stock:daily')
            ->expectsOutputToContain('Tidak ada user penerima aktif')
            ->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertNull(DailyStock::first()->emailed_at);
    }

    public function test_page_can_email_a_day_to_active_users(): void
    {
        CarbonImmutable::setTestNow($this->utc('2026-10-09 07:00'));
        app(DailyStockService::class)->record('manual');

        Livewire::test(Index::class)
            ->call('sendEmail', '2026-10-09')
            ->assertSee('Belum ada user penerima aktif');
        Mail::assertNothingSent();

        ReportRecipient::factory()->count(3)->create();

        Livewire::test(Index::class)
            ->assertSee('3 user aktif')
            ->call('sendEmail', '2026-10-09')
            ->assertSee('dikirim ke 3 user')
            ->assertSee('3 user ·');

        Mail::assertSent(DailyStockReportMail::class, 3);
    }

    public function test_command_is_scheduled_every_minute(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('stock:daily')->assertSuccessful();
    }

    public function test_page_records_now_and_lists_the_excel_file(): void
    {
        CarbonImmutable::setTestNow($this->utc('2026-10-09 07:00'));

        $this->get(route('daily-stock.index'))->assertOk()->assertSee('Belum ada file');

        Livewire::test(Index::class)
            ->call('recordNow')
            ->assertSee('09 Oct 2026')
            ->assertSee('STOCK < 1.5 DAY')
            ->assertSee('STOCK < 1.0 DAY')
            ->call('download', '2026-10-09')
            ->assertFileDownloaded('Daily Stock FG 2026-10-09.xlsx');

        $this->assertSame('manual', DailyStock::first()->source);
    }

    public function test_schedule_can_be_changed_from_the_page(): void
    {
        Livewire::test(Index::class)
            ->set('time', '06:15')
            ->set('days', ['1', '3'])
            ->set('workingDays', 21)
            ->call('saveSchedule')
            ->assertHasNoErrors();

        $this->assertSame(['time' => '06:15', 'days' => [1, 3], 'working_days' => 21], app(DailyStockService::class)->schedule());

        Livewire::test(Index::class)
            ->set('time', '25:00')
            ->call('saveSchedule')
            ->assertHasErrors('time');
    }

    protected function reportSheet(string $date): Worksheet
    {
        $path = Storage::disk('local')->path("daily-stock/Daily Stock FG {$date}.xlsx");

        return IOFactory::load($path)->getActiveSheet();
    }

    protected function findRow(Worksheet $sheet, string $column, string $value): int
    {
        for ($row = 1; $row <= $sheet->getHighestRow(); $row++) {
            if ($sheet->getCell("{$column}{$row}")->getValue() === $value) {
                return $row;
            }
        }

        $this->fail("Value {$value} not found in column {$column}.");
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
