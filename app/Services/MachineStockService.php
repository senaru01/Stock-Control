<?php

namespace App\Services;

use App\Models\MachineSnapshot;
use App\Models\MachineSnapshotItem;
use App\Models\MachineStockControl;
use App\Models\MonitoredMachine;
use App\Models\Partlist;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class MachineStockService
{
    /**
     * Stock is recorded once per slot of this many hours.
     */
    public const SLOT_HOURS = 2;

    /**
     * A production day runs from 07:00 to 07:00 the next day; slots are 07:00, 09:00, ... 05:00.
     */
    public const DAY_START_HOUR = 7;

    public const SLOTS_PER_DAY = 24 / self::SLOT_HOURS;

    public const DEFAULT_MIN_DAYS = 1;

    public const DEFAULT_MAX_DAYS = 2;

    public const PERIOD_DAY = 'day';

    public const PERIOD_WEEK = 'week';

    public const PERIOD_MONTH = 'month';

    public const STATUS_BELOW_MIN = 'below_min';

    public const STATUS_SAFE = 'safe';

    public const STATUS_OVER = 'over';

    /**
     * The part has no min / max setting yet, or no PO/day in the partlist, so its limits in pcs are unknown.
     */
    public const STATUS_NO_PARAM = 'no_param';

    /**
     * Quantities recorded and charted per part, against its min and max.
     *
     * @var list<string>
     */
    public const MEASURES = ['stock', 'stock_prod'];

    /** @var list<string> */
    protected const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    /** @var list<string> */
    protected const DAYS = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];

    public function __construct(
        protected StockPartService $stockParts,
        protected StockHistoryService $history,
    ) {}

    public function now(): CarbonImmutable
    {
        return $this->history->now();
    }

    /**
     * The start (HH:00) of the 2-hour slot containing the given moment, counted from 07:00 local time.
     */
    public function slotFor(CarbonInterface $at): string
    {
        $sinceDayStart = ($this->local($at)->hour - self::DAY_START_HOUR + 24) % 24;

        return sprintf('%02d:00', (self::DAY_START_HOUR + $sinceDayStart - $sinceDayStart % self::SLOT_HOURS) % 24);
    }

    /**
     * The production day (Y-m-d) a moment belongs to: before 07:00 still counts as the previous day.
     */
    public function productionDate(CarbonInterface $at): string
    {
        return $this->local($at)->subHours(self::DAY_START_HOUR)->toDateString();
    }

    /**
     * Active machines that have no snapshot yet in the slot containing the given moment.
     *
     * @return Collection<int, MonitoredMachine>
     */
    public function dueMachines(CarbonInterface $at): Collection
    {
        return MonitoredMachine::query()
            ->active()
            ->whereDoesntHave('snapshots', fn ($query) => $query
                ->where('production_date', $this->productionDate($at))
                ->where('slot_time', $this->slotFor($at)))
            ->orderBy('line')
            ->get();
    }

    /**
     * Fetch the latest stock from the SOS API once and store the parts of every given machine, with their PO/day.
     *
     * @param  Collection<int, MonitoredMachine>  $machines
     * @return Collection<int, MachineSnapshot>
     *
     * @throws RuntimeException when the API cannot be reached (stale cached data is never recorded).
     */
    public function takeSnapshots(Collection $machines, string $source = 'schedule'): Collection
    {
        if ($machines->isEmpty()) {
            return collect();
        }

        $result = $this->stockParts->get(forceRefresh: true);

        if ($result['error'] !== null) {
            throw new RuntimeException($result['error']);
        }

        $rowsByLine = collect($result['rows'])->groupBy(fn (array $row) => $this->lineKey($row['line']));
        $poDays = $this->poDays();
        $takenAt = $this->now();

        return DB::transaction(fn () => $machines->map(function (MonitoredMachine $machine) use ($rowsByLine, $poDays, $takenAt, $source) {
            $rows = $rowsByLine->get($this->lineKey($machine->line), collect());

            $snapshot = $machine->snapshots()->create([
                'taken_at' => $takenAt->setTimezone((string) config('app.timezone')),
                'local_date' => $takenAt->toDateString(),
                'local_time' => $takenAt->format('H:i'),
                'production_date' => $this->productionDate($takenAt),
                'slot_time' => $this->slotFor($takenAt),
                'item_count' => $rows->count(),
                'source' => $source,
            ]);

            $snapshot->items()->createMany($rows->map(fn (array $row) => [
                'store' => $row['store'],
                'part_no' => $row['part_no'],
                'rack_no' => $row['rack_no'],
                'stock' => (int) $row['stock'],
                'stock_prod' => (int) $row['stock_prod'],
                'po_day' => $poDays[$this->stockParts->partKey($row['part_no'], $row['store'])] ?? null,
            ])->all());

            return $snapshot;
        })->values());
    }

    /**
     * Save the min / max (in days) of several parts of a machine, valid from now until changed again.
     * Parts whose values did not change keep their current setting (and its date).
     *
     * @param  iterable<array{store: ?string, part_no: ?string, min_days: float|int|string, max_days: float|int|string}>  $settings
     * @return int The number of parts whose setting changed.
     */
    public function saveControls(MonitoredMachine $machine, iterable $settings): int
    {
        $current = $this->currentControls($machine);
        $effectiveFrom = now();
        $changed = 0;

        DB::transaction(function () use ($machine, $settings, $current, $effectiveFrom, &$changed) {
            foreach ($settings as $setting) {
                $minDays = round((float) $setting['min_days'], 3);
                $maxDays = round((float) $setting['max_days'], 3);
                $existing = $current->get($this->stockParts->partKey($setting['part_no'], $setting['store']));

                if ($existing !== null && $existing->min_days === $minDays && $existing->max_days === $maxDays) {
                    continue;
                }

                $machine->controls()->create([
                    'store' => $setting['store'],
                    'part_no' => $setting['part_no'],
                    'min_days' => $minDays,
                    'max_days' => $maxDays,
                    'effective_from' => $effectiveFrom,
                ]);
                $changed++;
            }
        });

        return $changed;
    }

    /**
     * The setting in force for every part of a machine that has one, keyed by part (part number + store).
     *
     * @return Collection<string, MachineStockControl>
     */
    public function currentControls(MonitoredMachine $machine): Collection
    {
        return $machine->controls()
            ->orderBy('effective_from')
            ->orderBy('id')
            ->get()
            ->keyBy(fn (MachineStockControl $control) => $this->stockParts->partKey($control->part_no, $control->store));
    }

    /**
     * The parts of a line in Stock Part All right now, with PO/day, to set their min / max.
     *
     * @return list<array{store: ?string, part_no: ?string, rack_no: ?string, po_day: ?int}>
     */
    public function lineParts(string $line): array
    {
        $poDays = $this->poDays();

        return collect($this->stockParts->get()['rows'])
            ->filter(fn (array $row) => $this->lineKey($row['line']) === $this->lineKey($line))
            ->map(fn (array $row) => [
                'store' => $row['store'],
                'part_no' => $row['part_no'],
                'rack_no' => $row['rack_no'],
                'po_day' => $poDays[$this->stockParts->partKey($row['part_no'], $row['store'])] ?? null,
            ])
            ->unique(fn (array $part) => $this->stockParts->partKey($part['part_no'], $part['store']))
            ->sortBy([
                fn (array $a, array $b) => strnatcasecmp((string) $a['store'], (string) $b['store']),
                fn (array $a, array $b) => strnatcasecmp((string) $a['part_no'], (string) $b['part_no']),
            ])
            ->values()
            ->all();
    }

    /**
     * Lines found in Stock Part All, offered when adding a machine.
     *
     * @return list<string>
     */
    public function availableLines(): array
    {
        return collect($this->stockParts->get()['rows'])
            ->pluck('line')
            ->filter()
            ->unique(fn (string $line) => $this->lineKey($line))
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    public function lineKey(?string $line): string
    {
        return mb_strtoupper(trim((string) $line));
    }

    /**
     * Min or max in pcs: days x PO/day, rounded up to whole pieces.
     */
    public function limitPcs(?float $days, ?int $poDay): ?int
    {
        return $days === null || $poDay === null ? null : (int) ceil($days * $poDay);
    }

    public function statusOf(int $value, ?int $min, ?int $max): string
    {
        return match (true) {
            $min === null => self::STATUS_NO_PARAM,
            $value < $min => self::STATUS_BELOW_MIN,
            $max !== null && $value > $max => self::STATUS_OVER,
            default => self::STATUS_SAFE,
        };
    }

    /**
     * The anchor for a period: the production date (day), its Monday (week) or Y-m (month), falling back to today.
     */
    public function normalizeAnchor(string $period, ?string $anchor): string
    {
        $date = match (true) {
            $period === self::PERIOD_MONTH && preg_match('/^\d{4}-\d{2}$/', (string) $anchor) === 1 => $anchor.'-01',
            preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $anchor) === 1 && strtotime((string) $anchor) !== false => (string) $anchor,
            default => $this->productionDate($this->now()),
        };

        return match ($period) {
            self::PERIOD_WEEK => $this->history->weekStart($date),
            self::PERIOD_MONTH => substr($date, 0, 7),
            default => $date,
        };
    }

    /**
     * Move a period anchor back (-1) or forward (+1) by one day / week / month.
     */
    public function shiftAnchor(string $period, string $anchor, int $direction): string
    {
        $anchor = $this->normalizeAnchor($period, $anchor);

        return match ($period) {
            self::PERIOD_WEEK => CarbonImmutable::parse($anchor)->addWeeks($direction)->toDateString(),
            self::PERIOD_MONTH => CarbonImmutable::parse($anchor.'-01')->addMonthsNoOverflow($direction)->format('Y-m'),
            default => CarbonImmutable::parse($anchor)->addDays($direction)->toDateString(),
        };
    }

    /**
     * A readable name for the period, e.g. "Sen, 12 Okt 2026", "12 – 18 Okt 2026" or "Okt 2026".
     */
    public function periodLabel(string $period, string $anchor): string
    {
        $anchor = $this->normalizeAnchor($period, $anchor);

        return match ($period) {
            self::PERIOD_WEEK => $this->history->weekRangeLabel($anchor),
            self::PERIOD_MONTH => self::MONTHS[(int) substr($anchor, 5, 2) - 1].' '.substr($anchor, 0, 4),
            default => $this->dayLabel(CarbonImmutable::parse($anchor)).' '.substr($anchor, 0, 4),
        };
    }

    /**
     * Chart data for one machine over a production day (07:00 – 07:00), a week (from Monday 07:00)
     * or a month (from the 1st, 07:00).
     *
     * The x axis always covers every 2-hour slot of the period; slots without a snapshot are null.
     * Min / max per slot are the part setting (days) valid when the stock was taken x the part PO/day.
     *
     * @return array{
     *     labels: list<string>,
     *     ticks: list<string>,
     *     total: array{stock: list<?int>, stock_prod: list<?int>, min: list<?int>, max: list<?int>},
     *     parts: list<array{key: string, store: ?string, part_no: ?string, rack_no: ?string, po_day: ?int, min_days: ?float, max_days: ?float, alerts: int, stock: list<?int>, stock_prod: list<?int>, min: list<?int>, max: list<?int>}>,
     *     snapshot_count: int
     * }
     */
    public function chart(MonitoredMachine $machine, string $period, string $anchor): array
    {
        [$start, $days] = $this->periodRange($period, $anchor);
        $slots = $this->slots($start, $days);
        $controlsByPart = $machine->controls()
            ->orderBy('effective_from')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (MachineStockControl $control) => $this->stockParts->partKey($control->part_no, $control->store));

        $snapshots = $machine->snapshots()
            ->with('items')
            ->whereBetween('production_date', [$start->toDateString(), $start->addDays($days - 1)->toDateString()])
            ->orderBy('taken_at')
            ->orderBy('id')
            ->get()
            ->keyBy(fn (MachineSnapshot $snapshot) => "{$snapshot->production_date} {$snapshot->slot_time}");

        // Settings are looked up at the moment the stock was taken; for empty slots, at the start of the slot.
        $slotMoments = collect($slots)->map(fn (array $slot) => $snapshots->get($slot['key'])?->taken_at ?? $slot['start']);
        $itemsBySlot = collect($slots)->map(fn (array $slot) => $snapshots->get($slot['key'])?->items->keyBy(fn (MachineSnapshotItem $item) => $this->partKey($item)));

        $parts = $snapshots
            ->flatMap(fn (MachineSnapshot $snapshot) => $snapshot->items)
            ->unique(fn (MachineSnapshotItem $item) => $this->partKey($item))
            ->sortBy([
                fn (MachineSnapshotItem $a, MachineSnapshotItem $b) => strnatcasecmp((string) $a->store, (string) $b->store),
                fn (MachineSnapshotItem $a, MachineSnapshotItem $b) => strnatcasecmp((string) $a->part_no, (string) $b->part_no),
            ])
            ->map(function (MachineSnapshotItem $item) use ($itemsBySlot, $slotMoments, $controlsByPart) {
                $key = $this->partKey($item);
                $partControls = $controlsByPart->get($key, collect());
                $slotControls = $slotMoments->map(fn (CarbonInterface $moment) => $this->controlAt($partControls, $moment));
                $items = $itemsBySlot->map(fn (?Collection $slotItems) => $slotItems?->get($key));
                $poDays = $this->carryAcross($items->map(fn (?MachineSnapshotItem $slotItem) => $slotItem?->po_day)->all());
                $min = $slotControls->map(fn (?MachineStockControl $control, int $index) => $this->limitPcs($control?->min_days, $poDays[$index]))->all();
                $max = $slotControls->map(fn (?MachineStockControl $control, int $index) => $this->limitPcs($control?->max_days, $poDays[$index]))->all();

                return [
                    'key' => $key,
                    'store' => $item->store,
                    'part_no' => $item->part_no,
                    'rack_no' => $items->filter()->last()?->rack_no,
                    'po_day' => collect($poDays)->filter()->last(),
                    'min_days' => $partControls->last()?->min_days,
                    'max_days' => $partControls->last()?->max_days,
                    'alerts' => $items->map(fn (?MachineSnapshotItem $slotItem, int $index) => $slotItem === null ? 0 : collect(self::MEASURES)
                        ->filter(fn (string $measure) => in_array($this->statusOf($slotItem->{$measure}, $min[$index], $max[$index]), [self::STATUS_BELOW_MIN, self::STATUS_OVER], true))
                        ->count())->sum(),
                    'stock' => $items->map(fn (?MachineSnapshotItem $slotItem) => $slotItem?->stock)->all(),
                    'stock_prod' => $items->map(fn (?MachineSnapshotItem $slotItem) => $slotItem?->stock_prod)->all(),
                    'min' => $min,
                    'max' => $max,
                ];
            })
            ->values();

        $sumItems = fn (string $column) => $itemsBySlot->map(fn (?Collection $slotItems) => $slotItems?->sum($column))->all();
        $sumLimits = fn (string $limit) => collect($slots)->keys()->map(function (int $index) use ($parts, $limit) {
            $values = $parts->pluck("{$limit}.{$index}")->filter(fn (?int $value) => $value !== null);

            return $values->isEmpty() ? null : (int) $values->sum();
        })->all();

        return [
            'labels' => array_column($slots, 'label'),
            'ticks' => array_column($slots, 'tick'),
            'total' => [
                'stock' => $sumItems('stock'),
                'stock_prod' => $sumItems('stock_prod'),
                'min' => $sumLimits('min'),
                'max' => $sumLimits('max'),
            ],
            'parts' => $parts->all(),
            'snapshot_count' => $snapshots->count(),
        ];
    }

    /**
     * The newest snapshot of a machine with every part checked against its current setting.
     *
     * @return array{snapshot: MachineSnapshot, items: list<array{store: ?string, part_no: ?string, rack_no: ?string, po_day: ?int, min_days: ?float, max_days: ?float, stock: int, stock_prod: int, min: ?int, max: ?int, stock_days: ?float, stock_status: string, stock_prod_status: string}>}|null
     */
    public function latest(MonitoredMachine $machine): ?array
    {
        $snapshot = $machine->snapshots()->with('items')->latest('taken_at')->latest('id')->first();

        if ($snapshot === null) {
            return null;
        }

        $controls = $this->currentControls($machine);

        return [
            'snapshot' => $snapshot,
            'items' => $snapshot->items
                ->sortBy([
                    fn (MachineSnapshotItem $a, MachineSnapshotItem $b) => strnatcasecmp((string) $a->store, (string) $b->store),
                    fn (MachineSnapshotItem $a, MachineSnapshotItem $b) => strnatcasecmp((string) $a->part_no, (string) $b->part_no),
                ])
                ->map(function (MachineSnapshotItem $item) use ($controls) {
                    $control = $controls->get($this->partKey($item));
                    $min = $this->limitPcs($control?->min_days, $item->po_day);
                    $max = $this->limitPcs($control?->max_days, $item->po_day);

                    return [
                        'store' => $item->store,
                        'part_no' => $item->part_no,
                        'rack_no' => $item->rack_no,
                        'po_day' => $item->po_day,
                        'min_days' => $control?->min_days,
                        'max_days' => $control?->max_days,
                        'stock' => $item->stock,
                        'stock_prod' => $item->stock_prod,
                        'min' => $min,
                        'max' => $max,
                        'stock_days' => $item->po_day ? round($item->stock / $item->po_day, 1) : null,
                        'stock_status' => $this->statusOf($item->stock, $min, $max),
                        'stock_prod_status' => $this->statusOf($item->stock_prod, $min, $max),
                    ];
                })
                ->values()
                ->all(),
        ];
    }

    public function partKey(MachineSnapshotItem $item): string
    {
        return $this->stockParts->partKey($item->part_no, $item->store);
    }

    /**
     * PO/day (pcs) per part, keyed like Stock Part All rows: part number + store (= partlist level).
     *
     * @return array<string, int>
     */
    protected function poDays(): array
    {
        return Partlist::query()
            ->select(['part_no', 'level', 'po_day'])
            ->get()
            ->filter(fn (Partlist $partlist) => is_numeric($partlist->po_day) && (float) $partlist->po_day > 0)
            ->mapWithKeys(fn (Partlist $partlist) => [$this->stockParts->partKey($partlist->part_no, $partlist->level) => (int) round((float) $partlist->po_day)])
            ->all();
    }

    /**
     * The parameter valid at a moment; before the first setting, the first setting applies.
     *
     * @param  Collection<int, MachineStockControl>  $controls  oldest first
     */
    protected function controlAt(Collection $controls, CarbonInterface $at): ?MachineStockControl
    {
        return $controls->last(fn (MachineStockControl $control) => $control->effective_from->lessThanOrEqualTo($at)) ?? $controls->first();
    }

    /**
     * @return array{CarbonImmutable, int} The first production day of the period and its number of days.
     */
    protected function periodRange(string $period, string $anchor): array
    {
        $anchor = $this->normalizeAnchor($period, $anchor);

        return match ($period) {
            self::PERIOD_WEEK => [CarbonImmutable::parse($anchor), 7],
            self::PERIOD_MONTH => [CarbonImmutable::parse($anchor.'-01'), CarbonImmutable::parse($anchor.'-01')->daysInMonth],
            default => [CarbonImmutable::parse($anchor), 1],
        };
    }

    /**
     * Every 2-hour slot of the period (07:00 ... 05:00 of each production day) with its tooltip label,
     * its (often blank) axis tick and the moment it starts.
     *
     * @return list<array{key: string, label: string, tick: string, start: CarbonImmutable}>
     */
    protected function slots(CarbonImmutable $start, int $days): array
    {
        $slots = [];
        $timezone = $this->history->timezone();

        for ($day = 0; $day < $days; $day++) {
            $productionDate = $start->addDays($day);
            $dayStart = CarbonImmutable::parse($productionDate->toDateString(), $timezone)->addHours(self::DAY_START_HOUR);

            for ($slot = 0; $slot < self::SLOTS_PER_DAY; $slot++) {
                $slotStart = $dayStart->addHours($slot * self::SLOT_HOURS);
                $time = $slotStart->format('H:i');

                $slots[] = [
                    'key' => "{$productionDate->toDateString()} {$time}",
                    'label' => $days === 1 ? $time : "{$this->dayLabel($slotStart)} {$time}",
                    'tick' => match (true) {
                        $days === 1 => $time,
                        $slot !== 0 => '',
                        $days === 7 => $this->dayLabel($productionDate),
                        default => (string) $productionDate->day,
                    },
                    'start' => $slotStart,
                ];
            }
        }

        return $slots;
    }

    /**
     * e.g. "Sen, 12 Okt".
     */
    protected function dayLabel(CarbonImmutable $date): string
    {
        return self::DAYS[$date->dayOfWeekIso - 1].', '.$date->day.' '.self::MONTHS[$date->month - 1];
    }

    protected function local(CarbonInterface $at): CarbonImmutable
    {
        return CarbonImmutable::instance($at)->setTimezone($this->history->timezone());
    }

    /**
     * Fill empty slots with the nearest earlier value (or, before the first value, the first one).
     *
     * @param  list<?int>  $values
     * @return list<?int>
     */
    protected function carryAcross(array $values): array
    {
        $current = collect($values)->first(fn (?int $value) => $value !== null);

        return array_map(function (?int $value) use (&$current) {
            return $current = $value ?? $current;
        }, $values);
    }
}
