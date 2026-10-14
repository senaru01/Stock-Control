<?php

namespace App\Services;

use App\Exports\DailyStockReport;
use App\Mail\DailyStockReportMail;
use App\Models\DailyStock;
use App\Models\DailyStockItem;
use App\Models\Partlist;
use App\Models\ReportRecipient;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class DailyStockService
{
    public const STORE = 'FINISH GOODS';

    public const DIRECTORY = 'daily-stock';

    protected const SCHEDULE_KEY = 'daily_stock.schedule';

    /**
     * ISO day numbers (1 = Monday ... 7 = Sunday) with their Indonesian names.
     *
     * @var array<int, string>
     */
    public const DAYS = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];

    /**
     * Preferred order of last processes in the coverage summaries; any other process follows alphabetically.
     *
     * @var list<string>
     */
    public const PROCESS_ORDER = ['CKD', 'PG', 'PR', 'TD', 'SW', 'SC PRESS', 'SC TREAT', 'PLANT 2'];

    /**
     * Press parts (process PG or PR in the part list) take their last process from the fix machine prefix:
     * the part list records parts made on a PR machine as PG.
     *
     * @var array<string, string>
     */
    public const PRESS_MACHINE_PROCESSES = ['PR' => 'PR', 'PG' => 'PG', 'PB' => 'PG'];

    /**
     * Part list processes that the daily stock resume reports under another last process.
     *
     * @var array<string, string>
     */
    public const PROCESS_ALIASES = ['ASSY' => 'SW', 'RWK' => 'TD'];

    /**
     * Monthly resumes, as in the Excel resume sheets: part count per last process, stock boxes per last
     * process, and part count of last process PG per fix machine.
     */
    public const RESUME_ITEM = 'item';

    public const RESUME_BOX = 'box';

    public const RESUME_PG_ITEM = 'pg_item';

    /**
     * Coverage (days) the monthly resumes count below; cumulative, so below 1.0 is also below 1.5.
     *
     * @var list<float>
     */
    public const RESUME_THRESHOLDS = [1.5, 1.0];

    /**
     * Stacked series in a resume chart before the smallest rows are folded into "Lainnya".
     */
    public const CHART_MAX_SERIES = 8;

    public function __construct(protected StockPartService $stockParts, protected StockHistoryService $history) {}

    /**
     * Unique last processes in the preferred summary order.
     *
     * @param  array<int, mixed>  $processes
     * @return list<string>
     */
    public static function orderProcesses(array $processes): array
    {
        $found = collect($processes)->filter(fn ($process) => filled($process))->map(fn ($process) => (string) $process)->unique();
        $preferred = array_values(array_filter(self::PROCESS_ORDER, fn (string $process) => $found->contains($process)));

        return [...$preferred, ...$found->diff(self::PROCESS_ORDER)->sort()->values()->all()];
    }

    /**
     * Last process a finish goods part is resumed under, from its part list process and fix machine (line).
     */
    public static function lastProcess(?string $process, ?string $line): string
    {
        $process = mb_strtoupper(trim((string) $process));

        if (array_key_exists($process, self::PRESS_MACHINE_PROCESSES) && preg_match('/^[A-Z]+/', mb_strtoupper(trim((string) $line)), $matches) === 1) {
            return self::PRESS_MACHINE_PROCESSES[$matches[0]] ?? $process;
        }

        return self::PROCESS_ALIASES[$process] ?? $process;
    }

    /**
     * Delivery per day in whole boxes: PO/day pcs divided by the qty per box, rounded up (0 without a box size).
     */
    public static function deliveryBoxes(mixed $poDay, int $qtyBox): int
    {
        return $qtyBox > 0 ? (int) ceil(max(0, (int) $poDay) / $qtyBox) : 0;
    }

    public function timezone(): string
    {
        return $this->history->timezone();
    }

    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone());
    }

    /**
     * When the daily stock is recorded: one time of day (local time) on the selected days of the week.
     *
     * @return array{time: string, days: list<int>, working_days: int}
     */
    public function schedule(): array
    {
        $saved = (array) Setting::read(self::SCHEDULE_KEY, []);
        $time = (string) ($saved['time'] ?? '');
        $days = collect($saved['days'] ?? [1, 2, 3, 4, 5])
            ->map(fn ($day) => (int) $day)
            ->filter(fn (int $day) => array_key_exists($day, self::DAYS))
            ->unique()
            ->sort()
            ->values()
            ->all();
        $workingDays = (int) ($saved['working_days'] ?? 0);

        return [
            'time' => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) === 1 ? $time : '07:00',
            'days' => $days,
            'working_days' => $workingDays >= 1 && $workingDays <= 31 ? $workingDays : 22,
        ];
    }

    /**
     * @param  array{time: string, days: list<int>, working_days: int}  $schedule
     */
    public function saveSchedule(array $schedule): void
    {
        Setting::write(self::SCHEDULE_KEY, [
            'time' => $schedule['time'],
            'days' => array_values(array_map('intval', $schedule['days'])),
            'working_days' => (int) $schedule['working_days'],
        ]);
    }

    public function describeSchedule(): string
    {
        $schedule = $this->schedule();

        if ($schedule['days'] === []) {
            return 'Tidak aktif';
        }

        $days = $schedule['days'] === array_keys(self::DAYS)
            ? 'Setiap hari'
            : implode(', ', array_map(fn (int $day) => self::DAYS[$day], $schedule['days']));

        return "{$days} jam {$schedule['time']}";
    }

    /**
     * Whether the scheduled recording should run now: on an active day, at or after the configured time,
     * and not yet recorded by the schedule today (so a missed minute is caught up later that day).
     */
    public function isDue(CarbonInterface $at): bool
    {
        $local = CarbonImmutable::instance($at)->setTimezone($this->timezone());
        $schedule = $this->schedule();

        if (! in_array($local->dayOfWeekIso, $schedule['days'], true) || $local->format('H:i') < $schedule['time']) {
            return false;
        }

        return ! DailyStock::query()
            ->where('stock_date', $local->toDateString())
            ->where('source', 'schedule')
            ->exists();
    }

    public function nextRunAt(CarbonInterface $after): ?CarbonImmutable
    {
        $schedule = $this->schedule();
        $local = CarbonImmutable::instance($after)->setTimezone($this->timezone());

        for ($offset = 0; $offset <= 7; $offset++) {
            [$hour, $minute] = array_map('intval', explode(':', $schedule['time']));
            $candidate = $local->startOfDay()->addDays($offset)->setTime($hour, $minute);

            if ($candidate->greaterThan($local) && in_array($candidate->dayOfWeekIso, $schedule['days'], true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Record today's finish goods stock per part from the SOS API, then build today's Excel report.
     *
     * Recording twice on the same day replaces that day's stock.
     *
     * @throws RuntimeException when the API cannot be reached (stale cached data is never recorded).
     */
    public function record(string $source = 'schedule'): DailyStock
    {
        $result = $this->stockParts->get(forceRefresh: true);

        if ($result['error'] !== null) {
            throw new RuntimeException($result['error']);
        }

        $qtyBoxes = Partlist::query()
            ->where('level', self::STORE)
            ->pluck('qty_kbn', 'part_no')
            ->mapWithKeys(fn ($qty, $partNo) => [mb_strtoupper(trim((string) $partNo)) => (int) $qty]);

        $items = collect($result['rows'])
            ->filter(fn (array $row) => mb_strtoupper((string) $row['store']) === self::STORE && filled($row['part_no']))
            ->groupBy(fn (array $row) => mb_strtoupper(trim($row['part_no'])))
            ->map(fn ($rows, string $partNo) => [
                'part_no' => $partNo,
                'stock_pcs' => (int) $rows->sum('stock'),
                'qty_box' => ($qtyBoxes[$partNo] ?? 0) > 0 ? $qtyBoxes[$partNo] : null,
            ])
            ->values();

        $takenAt = $this->now();

        $dailyStock = DB::transaction(function () use ($takenAt, $items, $source) {
            DailyStock::query()->where('stock_date', $takenAt->toDateString())->delete();

            $dailyStock = DailyStock::create([
                'stock_date' => $takenAt->toDateString(),
                'taken_at' => $takenAt->setTimezone((string) config('app.timezone')),
                'local_time' => $takenAt->format('H:i'),
                'item_count' => $items->count(),
                'source' => $source,
            ]);

            foreach ($items->chunk(500) as $chunk) {
                $dailyStock->items()->createMany($chunk->all());
            }

            return $dailyStock;
        });

        $this->generateReport($takenAt->toDateString());

        return $dailyStock;
    }

    /**
     * Build the Excel report for one day (YYYY-MM-DD) and return its path on the local disk.
     */
    public function generateReport(string $date): string
    {
        $path = $this->reportPath($date);
        $tempFile = (new DailyStockReport($date, $this->schedule()['working_days'], $this->timezone()))->create();

        Storage::disk('local')->put($path, file_get_contents($tempFile));
        unlink($tempFile);

        return $path;
    }

    public function reportPath(string $date): string
    {
        return self::DIRECTORY."/Daily Stock FG {$date}.xlsx";
    }

    /**
     * Generated daily reports, newest day first.
     *
     * @return list<array{date: string, label: string, path: string, size: int, updated_at: CarbonImmutable}>
     */
    public function reports(int $limit = 31): array
    {
        $disk = Storage::disk('local');

        return collect($disk->files(self::DIRECTORY))
            ->filter(fn (string $path) => preg_match('/Daily Stock FG (\d{4}-\d{2}-\d{2})\.xlsx$/', $path) === 1)
            ->map(function (string $path) use ($disk) {
                preg_match('/(\d{4}-\d{2}-\d{2})\.xlsx$/', $path, $matches);

                return [
                    'date' => $matches[1],
                    'label' => CarbonImmutable::createFromFormat('!Y-m-d', $matches[1])->translatedFormat('D, d M Y'),
                    'path' => $path,
                    'size' => $disk->size($path),
                    'updated_at' => CarbonImmutable::createFromTimestamp($disk->lastModified($path), $this->timezone()),
                ];
            })
            ->sortByDesc('date')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * Email a recorded day's Excel report (with the coverage resume in the body), one message per recipient.
     *
     * Without explicit recipients it goes to every active user and the delivery is logged on the day;
     * one failing address does not stop the others.
     *
     * @param  Collection<int, ReportRecipient>|null  $recipients
     * @return array{sent: int, failed: list<string>}
     */
    public function sendReport(DailyStock $dailyStock, ?Collection $recipients = null): array
    {
        $isRegularDelivery = $recipients === null;
        $recipients ??= ReportRecipient::query()->active()->orderBy('name')->get();

        $path = $this->reportPath($dailyStock->stock_date);

        if (! Storage::disk('local')->exists($path)) {
            $this->generateReport($dailyStock->stock_date);
        }

        $summary = $this->coverageSummary($dailyStock);
        $charts = $this->reportCharts($dailyStock);
        $sent = 0;
        $failed = [];

        foreach ($recipients as $recipient) {
            try {
                Mail::to($recipient->email, $recipient->name)
                    ->send(new DailyStockReportMail($dailyStock, $summary, $path, $recipient->name, $charts));
                $sent++;
            } catch (Throwable $exception) {
                report($exception);
                $failed[] = $recipient->email;
            }
        }

        if ($isRegularDelivery && $sent > 0) {
            $dailyStock->update(['emailed_at' => now(), 'emailed_count' => $sent]);
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    public function latest(): ?DailyStock
    {
        return DailyStock::query()->latest('stock_date')->first();
    }

    /**
     * Resume of every recorded day of a month, up to the given date: for each resume (item, box, PG item)
     * its rows (last processes or PG fix machines) and, per coverage threshold, the value per row per day.
     * Days without a recorded stock are absent.
     *
     * @return array{
     *     days_in_month: int,
     *     recorded_days: list<int>,
     *     resumes: array<string, array{rows: list<string>, values: array<string, array<string, array<int, int>>>}>
     * }
     */
    public function monthResume(string $date): array
    {
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, $this->timezone());
        $parts = Partlist::query()->where('level', self::STORE)->get(['part_no', 'process', 'line', 'qty_kbn', 'po_day'])
            ->each(fn (Partlist $part) => $part->setAttribute('last_process', self::lastProcess($part->process, $part->line)));

        $coveragesByDay = $this->monthCoverages($parts, $day);
        $coverages = $coveragesByDay->flatten(1);
        $processes = self::orderProcesses($parts->pluck('last_process')->all());
        $pgMachines = $parts->where('last_process', 'PG')->pluck('line')
            ->map(fn ($line) => trim((string) $line))
            ->filter()
            ->unique()
            ->sort(SORT_NATURAL)
            ->values()
            ->all();

        $resumes = [
            self::RESUME_ITEM => [$processes, 'process', false],
            self::RESUME_BOX => [$processes, 'process', true],
            self::RESUME_PG_ITEM => [$pgMachines, 'machine', false],
        ];

        return [
            'days_in_month' => $day->daysInMonth,
            'recorded_days' => $coveragesByDay->keys()->all(),
            'resumes' => collect($resumes)->map(fn (array $resume) => [
                'rows' => $resume[0],
                'values' => collect(self::RESUME_THRESHOLDS)
                    ->mapWithKeys(fn (float $threshold) => [(string) $threshold => $coverages
                        ->filter(fn (array $coverage) => $coverage['coverage'] < $threshold && ($resume[1] === 'process' || $coverage['process'] === 'PG'))
                        ->groupBy([$resume[1], 'day'])
                        ->map(fn (Collection $days) => $days->map(fn (Collection $items) => $resume[2] ? (int) $items->sum('stock_box') : $items->count())->all())
                        ->all()])
                    ->all(),
            ])->all(),
        ];
    }

    /**
     * One chart per coverage threshold for a resume of monthResume(): stacked series per row and the total
     * per day. Unrecorded days are null so a chart leaves them empty (and its total line bridges them).
     *
     * @param  array{days_in_month: int, recorded_days: list<int>, resumes: array<string, array{rows: list<string>, values: array<string, array<string, array<int, int>>>}>}  $resume
     * @return list<array{threshold: string, title: string, unit: string, labels: list<string>, series: list<array{label: string, slot: int|null, data: list<?int>}>, total: list<?int>, rows: list<array{label: string, data: list<?int>}>}>
     */
    public function resumeCharts(array $resume, string $view = self::RESUME_ITEM): array
    {
        $days = range(1, $resume['days_in_month']);
        $recorded = array_flip($resume['recorded_days']);
        $rows = $resume['resumes'][$view]['rows'];
        $scope = $view === self::RESUME_PG_ITEM ? 'PG ' : '';

        return collect(self::RESUME_THRESHOLDS)->map(function (float $threshold) use ($resume, $view, $days, $recorded, $rows, $scope) {
            $values = $resume['resumes'][$view]['values'][(string) $threshold];
            $tableRows = collect($rows)->map(fn (string $row) => [
                'label' => $row,
                'data' => array_map(fn (int $day) => isset($recorded[$day]) ? ($values[$row][$day] ?? 0) : null, $days),
            ]);

            return [
                'threshold' => (string) $threshold,
                'title' => 'Grafik Stock FG '.$scope.'< '.number_format($threshold, 1).' Day',
                'unit' => $view === self::RESUME_BOX ? 'Box' : 'Item',
                'labels' => array_map('strval', $days),
                'series' => $this->foldSeries($tableRows, $days),
                'total' => array_map(fn (int $day) => isset($recorded[$day]) ? (int) $tableRows->sum(fn (array $row) => $row['data'][$day - 1] ?? 0) : null, $days),
                'rows' => $tableRows->all(),
            ];
        })->all();
    }

    /**
     * The monthly resume charts (item, < 1.5 and < 1.0 day) of a recorded day as PNG images for the report email,
     * drawn once per sending. A failing chart never stops the email: it is then sent without charts.
     *
     * @return list<array{title: string, png: string}>
     */
    public function reportCharts(DailyStock $dailyStock): array
    {
        try {
            $renderer = app(ResumeChartImage::class);

            return array_map(
                fn (array $chart) => ['title' => $chart['title'], 'png' => $renderer->png($chart)],
                $this->resumeCharts($this->monthResume($dailyStock->stock_date)),
            );
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
    }

    /**
     * Coverage status of a recorded day per last process, using the same rules as the Excel report:
     * coverage = stock in whole boxes / delivery per day in whole boxes (PO/day pcs / qty per box, rounded up);
     * below 1.5 days is NOT OK, above 5 days is OVER.
     *
     * @return array<string, array{parts: int, under_one: int, not_ok: int, ok: int, over: int}>
     */
    public function coverageSummary(DailyStock $dailyStock): array
    {
        $stockByPart = $dailyStock->items()->pluck('stock_pcs', 'part_no')->mapWithKeys(fn ($stock, $partNo) => [mb_strtoupper($partNo) => (int) $stock]);

        $parts = Partlist::query()->where('level', self::STORE)->get(['part_no', 'process', 'line', 'qty_kbn', 'po_day'])
            ->each(fn (Partlist $part) => $part->setAttribute('last_process', self::lastProcess($part->process, $part->line)));
        $monitored = $parts
            ->filter(fn (Partlist $part) => filled($part->last_process) && self::deliveryBoxes($part->po_day, (int) $part->qty_kbn) > 0)
            ->groupBy('last_process');

        return collect(self::orderProcesses($parts->pluck('last_process')->all()))
            ->mapWithKeys(fn (string $process) => [$process => $monitored->get($process, collect())])
            ->map(function ($parts) use ($stockByPart) {
                $coverages = $parts->map(function (Partlist $part) use ($stockByPart) {
                    $stockBox = intdiv(max(0, $stockByPart[mb_strtoupper(trim($part->part_no))] ?? 0), $part->qty_kbn);

                    return $stockBox / self::deliveryBoxes($part->po_day, (int) $part->qty_kbn);
                });

                return [
                    'parts' => $coverages->count(),
                    'under_one' => $coverages->filter(fn (float $coverage) => $coverage < 1.0)->count(),
                    'not_ok' => $coverages->filter(fn (float $coverage) => $coverage < 1.5)->count(),
                    'ok' => $coverages->filter(fn (float $coverage) => $coverage >= 1.5 && $coverage <= 5)->count(),
                    'over' => $coverages->filter(fn (float $coverage) => $coverage > 5)->count(),
                ];
            })
            ->all();
    }

    /**
     * Coverage of every part with a delivery per day, for each recorded day of the month up to the given day.
     *
     * @param  Collection<int, Partlist>  $parts  finish goods parts with their last_process set
     * @return Collection<int, Collection<int, array{day: int, process: string, machine: string, stock_box: int, coverage: float}>>
     */
    protected function monthCoverages(Collection $parts, CarbonImmutable $day): Collection
    {
        $monitored = $parts
            ->map(fn (Partlist $part) => [
                'key' => mb_strtoupper(trim($part->part_no)),
                'process' => (string) $part->last_process,
                'machine' => trim((string) $part->line),
                'qty_box' => (int) $part->qty_kbn,
                'delivery_box' => self::deliveryBoxes($part->po_day, (int) $part->qty_kbn),
            ])
            ->filter(fn (array $part) => $part['delivery_box'] > 0);

        return DailyStock::query()
            ->with('items:id,daily_stock_id,part_no,stock_pcs,qty_box')
            ->whereBetween('stock_date', [$day->startOfMonth()->toDateString(), $day->toDateString()])
            ->orderBy('stock_date')
            ->get()
            ->keyBy(fn (DailyStock $dailyStock) => (int) substr($dailyStock->stock_date, 8, 2))
            ->map(function (DailyStock $dailyStock, int $dayOfMonth) use ($monitored) {
                $items = $dailyStock->items->keyBy(fn (DailyStockItem $item) => mb_strtoupper($item->part_no));

                return $monitored
                    ->map(function (array $part) use ($items, $dayOfMonth) {
                        $item = $items->get($part['key']);
                        $stockBox = intdiv(max(0, (int) ($item->stock_pcs ?? 0)), $item?->qty_box ?: $part['qty_box']);

                        return [
                            'day' => $dayOfMonth,
                            'process' => $part['process'],
                            'machine' => $part['machine'],
                            'stock_box' => $stockBox,
                            'coverage' => $stockBox / $part['delivery_box'],
                        ];
                    })
                    ->values();
            });
    }

    /**
     * Keep each row as its own colour slot; past CHART_MAX_SERIES rows the ones with the smallest month
     * total are summed into a grey "Lainnya" series, so colours are never reused within a chart.
     *
     * @param  Collection<int, array{label: string, data: list<?int>}>  $rows
     * @param  list<int>  $days
     * @return list<array{label: string, slot: int|null, data: list<?int>}>
     */
    protected function foldSeries(Collection $rows, array $days): array
    {
        $kept = $rows->count() > self::CHART_MAX_SERIES
            ? $rows->sortByDesc(fn (array $row) => array_sum(array_filter($row['data'])))->take(self::CHART_MAX_SERIES - 1)->keys()->sort()->all()
            : $rows->keys()->all();

        $series = $rows->only($kept)
            ->values()
            ->map(fn (array $row, int $slot) => ['label' => $row['label'], 'slot' => $slot, 'data' => $row['data']]);

        $others = $rows->except($kept);

        if ($others->isNotEmpty()) {
            $series->push([
                'label' => 'Lainnya ('.$others->count().')',
                'slot' => null,
                'data' => array_map(fn (int $day) => $others->every(fn (array $row) => $row['data'][$day - 1] === null)
                    ? null
                    : (int) $others->sum(fn (array $row) => $row['data'][$day - 1] ?? 0), $days),
            ]);
        }

        return $series->all();
    }
}
