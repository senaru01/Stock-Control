<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\StockSnapshot;
use App\Models\StockSnapshotStore;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StockHistoryService
{
    public const MODE_INTERVAL = 'interval';

    public const MODE_TIMES = 'times';

    /** @var list<int> */
    public const INTERVAL_OPTIONS = [5, 10, 15, 30, 60];

    public const GRANULARITY_SNAPSHOT = 'snapshot';

    public const GRANULARITY_DAY = 'day';

    public const GRANULARITY_WEEK = 'week';

    public const GRANULARITY_MONTH = 'month';

    public const GRANULARITY_YEAR = 'year';

    public const AGGREGATE_LAST = 'last';

    public const AGGREGATE_AVERAGE = 'avg';

    protected const SCHEDULE_KEY = 'stock_history.schedule';

    /** @var list<string> */
    protected const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    /** @var list<string> */
    protected const DAYS = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];

    public function __construct(protected StockPartService $stockParts) {}

    public function timezone(): string
    {
        return (string) config('services.sos.history_timezone');
    }

    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone());
    }

    /**
     * The snapshot schedule: every N minutes, or at fixed times of day (local time).
     *
     * @return array{mode: string, interval_minutes: int, times: list<string>}
     */
    public function schedule(): array
    {
        $saved = (array) Setting::read(self::SCHEDULE_KEY, []);

        return [
            'mode' => in_array($saved['mode'] ?? null, [self::MODE_INTERVAL, self::MODE_TIMES], true) ? $saved['mode'] : self::MODE_INTERVAL,
            'interval_minutes' => in_array((int) ($saved['interval_minutes'] ?? 0), self::INTERVAL_OPTIONS, true) ? (int) $saved['interval_minutes'] : 15,
            'times' => $this->normalizeTimes($saved['times'] ?? ['07:00', '16:00']),
        ];
    }

    /**
     * @param  array{mode: string, interval_minutes: int, times: list<string>}  $schedule
     */
    public function saveSchedule(array $schedule): void
    {
        Setting::write(self::SCHEDULE_KEY, [
            'mode' => $schedule['mode'],
            'interval_minutes' => (int) $schedule['interval_minutes'],
            'times' => $this->normalizeTimes($schedule['times']),
        ]);
    }

    public function describeSchedule(): string
    {
        $schedule = $this->schedule();

        return $schedule['mode'] === self::MODE_INTERVAL
            ? "Setiap {$schedule['interval_minutes']} menit"
            : 'Setiap hari jam '.implode(', ', $schedule['times']);
    }

    /**
     * Whether a scheduled snapshot should be taken at the given minute (local time).
     */
    public function isDue(CarbonInterface $at): bool
    {
        $local = CarbonImmutable::instance($at)->setTimezone($this->timezone());
        $schedule = $this->schedule();

        return $schedule['mode'] === self::MODE_INTERVAL
            ? ($local->hour * 60 + $local->minute) % $schedule['interval_minutes'] === 0
            : in_array($local->format('H:i'), $schedule['times'], true);
    }

    public function nextRunAt(CarbonInterface $after): CarbonImmutable
    {
        $candidate = CarbonImmutable::instance($after)->setTimezone($this->timezone())->startOfMinute()->addMinute();

        for ($minutes = 0; $minutes < 60 * 24 * 2; $minutes++) {
            if ($this->isDue($candidate)) {
                return $candidate;
            }

            $candidate = $candidate->addMinute();
        }

        return $candidate;
    }

    /**
     * Whether a scheduled snapshot was already stored for this local minute.
     */
    public function alreadyTaken(CarbonInterface $at): bool
    {
        $local = CarbonImmutable::instance($at)->setTimezone($this->timezone());

        return StockSnapshot::query()
            ->where('local_date', $local->toDateString())
            ->where('local_time', $local->format('H:i'))
            ->exists();
    }

    /**
     * Fetch the latest stock from the SOS API and store the total per store.
     *
     * @throws RuntimeException when the API cannot be reached (stale cached data is never recorded).
     */
    public function takeSnapshot(string $source = 'schedule'): StockSnapshot
    {
        $result = $this->stockParts->get(forceRefresh: true);

        if ($result['error'] !== null) {
            throw new RuntimeException($result['error']);
        }

        $rows = collect($result['rows']);
        $stores = $rows
            ->filter(fn (array $row) => filled($row['store']))
            ->groupBy('store')
            ->map(fn (Collection $storeRows, string $store) => [
                'store' => $store,
                'total_stock' => (int) $storeRows->sum('stock'),
                'item_count' => $storeRows->count(),
            ])
            ->sortKeys()
            ->values();

        $takenAt = $this->now();

        return DB::transaction(function () use ($takenAt, $rows, $stores, $source) {
            $snapshot = StockSnapshot::create([
                'taken_at' => $takenAt->setTimezone((string) config('app.timezone')),
                'local_date' => $takenAt->toDateString(),
                'local_time' => $takenAt->format('H:i'),
                'total_stock' => (int) $rows->sum('stock'),
                'item_count' => $rows->count(),
                'source' => $source,
            ]);

            $snapshot->stores()->createMany($stores->all());

            return $snapshot;
        });
    }

    /**
     * Stock history for charts: one point per snapshot (within a day) or per day (within a week / month) / month / year.
     *
     * @return array{labels: list<string>, total: list<int>, stores: array<string, list<?int>>, counts: list<int>}
     */
    public function history(string $granularity, ?string $anchor, string $aggregate = self::AGGREGATE_LAST): array
    {
        return $granularity === self::GRANULARITY_SNAPSHOT
            ? $this->snapshotHistory((string) $anchor)
            : $this->periodHistory($granularity, $anchor, $aggregate);
    }

    /**
     * The most recent date / week (its Monday) / month / year with data, used as the default chart period.
     */
    public function defaultAnchor(string $granularity): ?string
    {
        $latest = StockSnapshot::query()->max('local_date') ?? $this->now()->toDateString();

        return match ($granularity) {
            self::GRANULARITY_SNAPSHOT => $latest,
            self::GRANULARITY_WEEK => $this->weekStart($latest),
            self::GRANULARITY_DAY => substr($latest, 0, 7),
            self::GRANULARITY_MONTH => substr($latest, 0, 4),
            default => null,
        };
    }

    /**
     * The Monday (Y-m-d) of the Monday–Sunday week containing the given date.
     */
    public function weekStart(string $date): string
    {
        return CarbonImmutable::parse($date)->startOfWeek(CarbonInterface::MONDAY)->toDateString();
    }

    /**
     * A readable Monday–Sunday range, e.g. "5 – 11 Okt 2026" or "29 Sep – 5 Okt 2026".
     */
    public function weekRangeLabel(string $monday): string
    {
        $start = CarbonImmutable::parse($monday);
        $end = $start->addDays(6);
        $endLabel = $end->day.' '.self::MONTHS[$end->month - 1].' '.$end->year;

        if ($start->month === $end->month) {
            return "{$start->day} – {$endLabel}";
        }

        $startLabel = $start->day.' '.self::MONTHS[$start->month - 1].($start->year !== $end->year ? ' '.$start->year : '');

        return "{$startLabel} – {$endLabel}";
    }

    /**
     * @return list<string>
     */
    public function availableYears(): array
    {
        return StockSnapshot::query()
            ->selectRaw('substr(local_date, 1, 4) as year')
            ->distinct()
            ->orderByDesc('year')
            ->pluck('year')
            ->map(fn ($year) => (string) $year)
            ->all();
    }

    public function latestSnapshot(): ?StockSnapshot
    {
        return StockSnapshot::query()->latest('taken_at')->latest('id')->first();
    }

    /**
     * @return array{labels: list<string>, total: list<int>, stores: array<string, list<?int>>, counts: list<int>}
     */
    protected function snapshotHistory(string $date): array
    {
        $snapshots = StockSnapshot::query()
            ->with('stores')
            ->where('local_date', $date)
            ->orderBy('taken_at')
            ->orderBy('id')
            ->get();

        $storeNames = $snapshots->flatMap(fn (StockSnapshot $snapshot) => $snapshot->stores->pluck('store'))->unique()->sort()->values();

        return [
            'labels' => $snapshots->pluck('local_time')->all(),
            'total' => $snapshots->pluck('total_stock')->all(),
            'stores' => $storeNames
                ->mapWithKeys(fn (string $store) => [$store => $snapshots
                    ->map(fn (StockSnapshot $snapshot) => $snapshot->stores->firstWhere('store', $store)?->total_stock)
                    ->all()])
                ->all(),
            'counts' => $snapshots->map(fn () => 1)->all(),
        ];
    }

    /**
     * @return array{labels: list<string>, total: list<int>, stores: array<string, list<?int>>, counts: list<int>}
     */
    protected function periodHistory(string $granularity, ?string $anchor, string $aggregate): array
    {
        $period = match ($granularity) {
            self::GRANULARITY_DAY, self::GRANULARITY_WEEK => 'local_date',
            self::GRANULARITY_MONTH => 'substr(local_date, 1, 7)',
            default => 'substr(local_date, 1, 4)',
        };

        $scope = function ($query) use ($granularity, $anchor) {
            if ($granularity === self::GRANULARITY_WEEK && $anchor) {
                $monday = CarbonImmutable::parse($anchor);
                $query->whereBetween('local_date', [$monday->toDateString(), $monday->addDays(6)->toDateString()]);
            } elseif ($granularity === self::GRANULARITY_DAY && $anchor) {
                $query->where('local_date', 'like', $anchor.'-%');
            } elseif ($granularity === self::GRANULARITY_MONTH && $anchor) {
                $query->where('local_date', 'like', $anchor.'-%');
            }
        };

        $periods = StockSnapshot::query()
            ->tap($scope)
            ->selectRaw("{$period} as period, max(id) as last_id, avg(total_stock) as avg_total, count(*) as snapshots")
            ->groupBy(DB::raw($period))
            ->orderBy('period')
            ->get()
            ->map(fn ($row) => [
                'period' => (string) $row->period,
                'last_id' => (int) $row->last_id,
                'avg_total' => (int) round((float) $row->avg_total),
                'snapshots' => (int) $row->snapshots,
            ]);

        if ($aggregate === self::AGGREGATE_AVERAGE) {
            $totals = $periods->pluck('avg_total');
            $storeValues = StockSnapshotStore::query()
                ->join('stock_snapshots', 'stock_snapshots.id', '=', 'stock_snapshot_stores.stock_snapshot_id')
                ->tap($scope)
                ->selectRaw("{$period} as period, store, avg(stock_snapshot_stores.total_stock) as value")
                ->groupBy(DB::raw($period), 'store')
                ->get()
                ->map(fn ($row) => ['period' => (string) $row->period, 'store' => $row->store, 'value' => (int) round((float) $row->value)]);
        } else {
            $lastSnapshots = StockSnapshot::query()->whereIn('id', $periods->pluck('last_id'))->pluck('total_stock', 'id');
            $totals = $periods->map(fn (array $row) => (int) $lastSnapshots[$row['last_id']]);
            $periodByLastId = $periods->pluck('period', 'last_id');
            $storeValues = StockSnapshotStore::query()
                ->whereIn('stock_snapshot_id', $periods->pluck('last_id'))
                ->get()
                ->map(fn (StockSnapshotStore $store) => [
                    'period' => $periodByLastId[$store->stock_snapshot_id],
                    'store' => $store->store,
                    'value' => $store->total_stock,
                ]);
        }

        $periodKeys = $periods->pluck('period');

        return [
            'labels' => $periodKeys->map(fn (string $key) => $this->periodLabel($granularity, $key))->all(),
            'total' => $totals->values()->all(),
            'stores' => $storeValues
                ->groupBy('store')
                ->sortKeys()
                ->map(function (Collection $values) use ($periodKeys) {
                    $byPeriod = $values->pluck('value', 'period');

                    return $periodKeys->map(fn (string $key) => $byPeriod[$key] ?? null)->all();
                })
                ->all(),
            'counts' => $periods->pluck('snapshots')->all(),
        ];
    }

    protected function periodLabel(string $granularity, string $key): string
    {
        return match ($granularity) {
            self::GRANULARITY_WEEK => self::DAYS[CarbonImmutable::parse($key)->dayOfWeekIso - 1].', '.(int) substr($key, 8, 2).' '.self::MONTHS[(int) substr($key, 5, 2) - 1],
            self::GRANULARITY_DAY => (int) substr($key, 8, 2).' '.self::MONTHS[(int) substr($key, 5, 2) - 1],
            self::GRANULARITY_MONTH => self::MONTHS[(int) substr($key, 5, 2) - 1].' '.substr($key, 0, 4),
            default => $key,
        };
    }

    /**
     * @return list<string>
     */
    protected function normalizeTimes(mixed $times): array
    {
        $times = is_string($times) ? explode(',', $times) : (array) $times;

        return collect($times)
            ->map(fn ($time) => trim((string) $time))
            ->filter(fn (string $time) => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) === 1)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
