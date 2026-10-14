<?php

namespace App\Livewire\StockHistory;

use App\Models\StockSnapshot;
use App\Services\StockHistoryService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

#[Title('History Stock Part All')]
class Index extends Component
{
    /** @var array<string, string> */
    public const GRANULARITIES = [
        StockHistoryService::GRANULARITY_SNAPSHOT => 'Per Pengambilan',
        StockHistoryService::GRANULARITY_WEEK => 'Per Minggu',
        StockHistoryService::GRANULARITY_DAY => 'Per Tanggal',
        StockHistoryService::GRANULARITY_MONTH => 'Per Bulan',
        StockHistoryService::GRANULARITY_YEAR => 'Per Tahun',
    ];

    #[Url(as: 'view', except: StockHistoryService::GRANULARITY_WEEK)]
    public string $granularity = StockHistoryService::GRANULARITY_WEEK;

    #[Url(as: 'date', except: '')]
    public string $date = '';

    /** The Monday (Y-m-d) of the selected Monday–Sunday week. */
    #[Url(as: 'week', except: '')]
    public string $week = '';

    #[Url(as: 'month', except: '')]
    public string $month = '';

    #[Url(as: 'year', except: '')]
    public string $year = '';

    #[Url(as: 'value', except: StockHistoryService::AGGREGATE_LAST)]
    public string $aggregate = StockHistoryService::AGGREGATE_LAST;

    public bool $showSettings = false;

    public string $scheduleMode = StockHistoryService::MODE_INTERVAL;

    public int $intervalMinutes = 15;

    public string $times = '07:00, 16:00';

    public function mount(StockHistoryService $history): void
    {
        if (! array_key_exists($this->granularity, self::GRANULARITIES)) {
            $this->granularity = StockHistoryService::GRANULARITY_WEEK;
        }

        $this->date = $this->date ?: (string) $history->defaultAnchor(StockHistoryService::GRANULARITY_SNAPSHOT);
        $this->week = $this->normalizeWeek($this->week) ?? (string) $history->defaultAnchor(StockHistoryService::GRANULARITY_WEEK);
        $this->month = $this->month ?: (string) $history->defaultAnchor(StockHistoryService::GRANULARITY_DAY);
        $this->year = $this->year ?: (string) $history->defaultAnchor(StockHistoryService::GRANULARITY_MONTH);

        $schedule = $history->schedule();
        $this->scheduleMode = $schedule['mode'];
        $this->intervalMinutes = $schedule['interval_minutes'];
        $this->times = implode(', ', $schedule['times']);
    }

    /**
     * Snap any date picked in the week input to the Monday of its week.
     */
    public function updatedWeek(): void
    {
        $this->week = $this->normalizeWeek($this->week) ?? (string) app(StockHistoryService::class)->defaultAnchor(StockHistoryService::GRANULARITY_WEEK);
    }

    public function previousWeek(): void
    {
        $this->week = CarbonImmutable::parse($this->week)->subWeek()->toDateString();
    }

    public function nextWeek(): void
    {
        $this->week = CarbonImmutable::parse($this->week)->addWeek()->toDateString();
    }

    public function saveSchedule(StockHistoryService $history): void
    {
        $this->authorize('stock_history.edit');
        $this->validate([
            'scheduleMode' => ['required', Rule::in([StockHistoryService::MODE_INTERVAL, StockHistoryService::MODE_TIMES])],
            'intervalMinutes' => ['required', 'integer', Rule::in(StockHistoryService::INTERVAL_OPTIONS)],
            'times' => [
                Rule::requiredIf($this->scheduleMode === StockHistoryService::MODE_TIMES),
                'nullable',
                'regex:/^\s*([01]\d|2[0-3]):[0-5]\d(\s*,\s*([01]\d|2[0-3]):[0-5]\d)*\s*$/',
            ],
        ], [
            'times.regex' => 'Format jam harus HH:MM dan dipisah koma, contoh: 07:00, 16:00.',
            'times.required' => 'Isi minimal satu jam pengambilan.',
        ]);

        $history->saveSchedule([
            'mode' => $this->scheduleMode,
            'interval_minutes' => $this->intervalMinutes,
            'times' => explode(',', $this->times),
        ]);

        $this->times = implode(', ', $history->schedule()['times']);
        $this->showSettings = false;
        session()->flash('status', 'Jadwal history disimpan: '.$history->describeSchedule().'.');
    }

    public function takeSnapshotNow(StockHistoryService $history): void
    {
        $this->authorize('stock_history.edit');
        try {
            $snapshot = $history->takeSnapshot('manual');
        } catch (RuntimeException $exception) {
            session()->flash('error', 'Gagal mengambil data: '.$exception->getMessage());

            return;
        }

        $this->date = $snapshot->local_date;
        $this->week = $history->weekStart($snapshot->local_date);
        $this->month = substr($snapshot->local_date, 0, 7);
        $this->year = substr($snapshot->local_date, 0, 4);
        unset($this->history, $this->latest, $this->years);

        session()->flash('status', "Data stock {$snapshot->local_date} {$snapshot->local_time} berhasil disimpan.");
    }

    /**
     * @return array{labels: list<string>, total: list<int>, stores: array<string, list<?int>>, counts: list<int>}
     */
    #[Computed]
    public function history(): array
    {
        $anchor = match ($this->granularity) {
            StockHistoryService::GRANULARITY_SNAPSHOT => $this->date,
            StockHistoryService::GRANULARITY_WEEK => $this->week,
            StockHistoryService::GRANULARITY_DAY => $this->month,
            StockHistoryService::GRANULARITY_MONTH => $this->year,
            default => null,
        };

        $aggregate = $this->aggregate === StockHistoryService::AGGREGATE_AVERAGE
            ? StockHistoryService::AGGREGATE_AVERAGE
            : StockHistoryService::AGGREGATE_LAST;

        return app(StockHistoryService::class)->history($this->granularity, $anchor, $aggregate);
    }

    #[Computed]
    public function weekRange(): string
    {
        return app(StockHistoryService::class)->weekRangeLabel($this->week);
    }

    #[Computed]
    public function latest(): ?StockSnapshot
    {
        return app(StockHistoryService::class)->latestSnapshot();
    }

    #[Computed]
    public function nextRun(): CarbonImmutable
    {
        $history = app(StockHistoryService::class);

        return $history->nextRunAt($history->now());
    }

    /**
     * Warn when no snapshot arrived for a while, which usually means the scheduler is not running.
     */
    #[Computed]
    public function schedulerLooksIdle(): bool
    {
        $history = app(StockHistoryService::class);
        $schedule = $history->schedule();

        if ($schedule['mode'] !== StockHistoryService::MODE_INTERVAL || $this->latest === null) {
            return false;
        }

        return $this->latest->taken_at->diffInMinutes(now()) > $schedule['interval_minutes'] * 2 + 1;
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function years(): array
    {
        return app(StockHistoryService::class)->availableYears();
    }

    protected function normalizeWeek(string $date): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || strtotime($date) === false) {
            return null;
        }

        return app(StockHistoryService::class)->weekStart($date);
    }

    public function render(): View
    {
        $history = app(StockHistoryService::class);

        return view('livewire.stock-history.index', [
            'granularities' => self::GRANULARITIES,
            'scheduleLabel' => $history->describeSchedule(),
            'intervalOptions' => StockHistoryService::INTERVAL_OPTIONS,
            'timezone' => $history->timezone(),
        ]);
    }
}
