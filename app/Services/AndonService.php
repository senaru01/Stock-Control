<?php

namespace App\Services;

use App\Models\Partlist;
use Carbon\CarbonImmutable;

class AndonService
{
    /**
     * Rows added above the stock max row so overstock shows above the stock max line.
     */
    public const HEADROOM_ROWS = 1;

    public const STATUS_PRODUCTION_POINT = 'production_point';

    public const STATUS_ORDER_POINT = 'order_point';

    public const STATUS_NORMAL = 'normal';

    public const STATUS_OVERSTOCK = 'overstock';

    public const STATUS_NOT_SET = 'not_set';

    public const UNGROUPED_PROCESS = 'Lainnya';

    /**
     * Process whose LOT is the shortfall to stock max instead of the partlist Qty Lot.
     */
    public const SHORTFALL_LOT_PROCESS = 'SW';

    /**
     * Processes listed first on the andon menu, in this order; the rest follow alphabetically.
     *
     * @var list<string>
     */
    public const PRIORITY_PROCESSES = ['PG', 'PR', 'TD', 'SW'];

    public function __construct(
        protected StockPartService $stockParts,
        protected PartlistLoadingService $loading,
    ) {}

    /**
     * Monitored lines grouped by process, each line placed under its dominant process
     * (the process most of its parts have; ties broken alphabetically).
     *
     * @return array<string, list<array{line: string, total: int}>>
     */
    public function lineGroups(): array
    {
        return Partlist::query()
            ->monitoredOnAndon()
            ->whereNotNull('line')
            ->where('line', '!=', '')
            ->selectRaw('line, process, count(*) as total')
            ->groupBy('line', 'process')
            ->get()
            ->groupBy('line')
            ->map(function ($processes, $line) {
                $dominant = $processes
                    ->sortBy([['total', 'desc'], fn ($a, $b) => strcmp((string) $a->process, (string) $b->process)])
                    ->first();

                return [
                    'process' => filled($dominant->process) ? $dominant->process : self::UNGROUPED_PROCESS,
                    'line' => (string) $line,
                    'total' => (int) $processes->sum('total'),
                ];
            })
            ->sortBy('line', SORT_NATURAL)
            ->groupBy('process')
            ->sortKeysUsing(fn ($a, $b) => $this->processSortKey((string) $a) <=> $this->processSortKey((string) $b))
            ->map(fn ($lines) => $lines->map(fn (array $line) => ['line' => $line['line'], 'total' => $line['total']])->values()->all())
            ->all();
    }

    /**
     * Sort key placing priority processes first, then the rest alphabetically, and ungrouped lines last.
     *
     * @return array{int, int, string}
     */
    protected function processSortKey(string $process): array
    {
        $priority = array_search(mb_strtoupper($process), self::PRIORITY_PROCESSES, true);

        return match (true) {
            $priority !== false => [0, $priority, ''],
            $process === self::UNGROUPED_PROCESS => [2, 0, ''],
            default => [1, 0, mb_strtoupper($process)],
        };
    }

    /**
     * The process a line is grouped under on the andon menu.
     */
    public function processForLine(string $line): ?string
    {
        foreach ($this->lineGroups() as $process => $lines) {
            if (in_array($line, array_column($lines, 'line'), true)) {
                return $process;
            }
        }

        return null;
    }

    /**
     * Display order of card groups: lowest stock first, parts missing from Stock Part All last.
     *
     * @var array<string, int>
     */
    protected const STATUS_ORDER = [
        self::STATUS_PRODUCTION_POINT => 0,
        self::STATUS_ORDER_POINT => 1,
        self::STATUS_NORMAL => 2,
        self::STATUS_OVERSTOCK => 3,
        self::STATUS_NOT_SET => 4,
    ];

    /**
     * Build andon cards for every partlist on the given line, sorted for display.
     *
     * @return array{cards: list<array<string, mixed>>, fetched_at: ?CarbonImmutable, error: ?string}
     */
    public function forLine(string $line): array
    {
        $stock = $this->stockParts->get();
        $stockByPart = $this->stockByPartAndStore($stock['rows']);
        $loadingPercents = $this->loading->percents();
        $orderPointDays = Partlist::orderPointDays();

        $cards = Partlist::query()
            ->monitoredOnAndon()
            ->where('line', $line)
            ->get()
            ->map(function (Partlist $partlist) use ($stockByPart, $loadingPercents, $orderPointDays) {
                $key = $this->stockParts->partKey($partlist->part_no, $partlist->level);
                $gauge = $this->gauge(
                    $stockByPart[$key] ?? 0,
                    $partlist->qty_kbn,
                    $partlist->stock_max,
                    (int) $partlist->prod_point,
                    $partlist->materialOrderPoint($orderPointDays),
                );

                return [
                    'code' => $partlist->code,
                    'part_no' => $partlist->part_no,
                    'level' => $partlist->level,
                    'line' => $partlist->line,
                    'rack_no' => $partlist->rack_no,
                    'has_stock_data' => array_key_exists($key, $stockByPart),
                    ...$gauge,
                    ...$this->planning($partlist, $gauge, $this->loading->percentFor($partlist->process, $loadingPercents)),
                ];
            })
            ->sort($this->compareCards(...))
            ->values()
            ->all();

        return ['cards' => $cards, 'fetched_at' => $stock['fetched_at'], 'error' => $stock['error']];
    }

    /**
     * Convert a stock quantity in pcs into an andon gauge whose row count follows the stock max (see kbnPerRow),
     * plus one headroom row above the stock max line for overstock.
     *
     * Stock is counted in whole kanban (rounded down), so a partial kanban does not count.
     * Status: at or below the production point is red; above it but at or below the material order point
     * (PG parts only, others have none) is the order point status; above that is normal, above max is overstock.
     *
     * @return array{stock_pcs: int, qty_kbn: int, stock_kbn: ?int, max: int, production_point: int, order_point: ?int, rows: int, kbn_per_row: int, max_row: ?int, production_point_row: ?int, order_point_row: ?int, filled_rows: int, status: string}
     */
    public function gauge(int $stockPcs, int $qtyKbn, int $maxKbn, int $productionPoint = 0, ?int $orderPoint = null): array
    {
        $base = [
            'stock_pcs' => $stockPcs,
            'qty_kbn' => $qtyKbn,
            'stock_kbn' => null,
            'max' => $maxKbn,
            'production_point' => $productionPoint,
            'order_point' => $orderPoint,
            'rows' => 0,
            'kbn_per_row' => 0,
            'max_row' => null,
            'production_point_row' => null,
            'order_point_row' => null,
            'filled_rows' => 0,
            'status' => self::STATUS_NOT_SET,
        ];

        if ($qtyKbn <= 0 || $maxKbn <= 0) {
            return $base;
        }

        $stockKbn = (int) floor(max(0, $stockPcs) / $qtyKbn);
        $kbnPerRow = self::kbnPerRow($maxKbn);
        $maxRow = (int) ceil($maxKbn / $kbnPerRow);
        $rows = $maxRow + self::HEADROOM_ROWS;
        $filledRows = (int) min($maxRow, round($stockKbn / $kbnPerRow));
        $productionRow = $this->pointRow($productionPoint, $kbnPerRow, $maxRow);
        $orderRow = $this->pointRow($orderPoint, $kbnPerRow, $maxRow);
        $hasOrderBand = $orderRow !== null && $orderPoint > $productionPoint;

        if ($stockKbn <= $productionPoint) {
            $status = self::STATUS_PRODUCTION_POINT;
            $filledRows = $stockKbn === $productionPoint
                ? ($stockKbn > 0 ? $productionRow : 0)
                : min($filledRows, $productionRow - 1);
        } elseif ($hasOrderBand && $stockKbn <= $orderPoint) {
            $status = self::STATUS_ORDER_POINT;
            $lowestRow = min($maxRow, ($productionRow ?? 0) + 1);
            $filledRows = $stockKbn === $orderPoint
                ? max($lowestRow, $orderRow)
                : min(max($filledRows, $lowestRow), max($lowestRow, $orderRow - 1));
        } elseif ($stockKbn > $maxKbn) {
            $status = self::STATUS_OVERSTOCK;
            $filledRows = $rows;
        } else {
            $status = self::STATUS_NORMAL;
            $highestPointRow = $hasOrderBand ? $orderRow : ($productionRow ?? 0);
            $filledRows = max($filledRows, min($maxRow, $highestPointRow + 1));
        }

        if ($stockKbn > 0) {
            $filledRows = max($filledRows, 1);
        }

        return [
            ...$base,
            'stock_kbn' => $stockKbn,
            'rows' => $rows,
            'kbn_per_row' => $kbnPerRow,
            'max_row' => $maxRow,
            'production_point_row' => $productionRow,
            'order_point_row' => $orderRow,
            'filled_rows' => $filledRows,
            'status' => $status,
        ];
    }

    /**
     * KBN represented by one gauge row: 10 above 100 max, 5 for 50-100, 2 for 10-49, otherwise 1.
     */
    public static function kbnPerRow(int $maxKbn): int
    {
        return match (true) {
            $maxKbn > 100 => 10,
            $maxKbn >= 50 => 5,
            $maxKbn >= 10 => 2,
            default => 1,
        };
    }

    /**
     * Gauge row whose top edge marks the given point (never above the stock max row), or null when there is no point to draw.
     */
    protected function pointRow(?int $pointKbn, int $kbnPerRow, int $maxRow): ?int
    {
        if ($pointKbn === null || $pointKbn <= 0) {
            return null;
        }

        return (int) max(1, min($maxRow, round($pointKbn / $kbnPerRow)));
    }

    /**
     * LOT in KBN, loading time in minutes (LOT x Qty KBN / loading pcs per minute) and stock coverage in days.
     *
     * For SW parts LOT is the shortfall to stock max (never below 0); other processes use the partlist Qty Lot.
     *
     * @param  array{stock_kbn: ?int, max: int}  $gauge
     * @return array{lot: int, loading_time: ?float, coverage_days: ?float}
     */
    public function planning(Partlist $partlist, array $gauge, int $loadingPercent): array
    {
        $lot = mb_strtoupper(trim((string) $partlist->process)) === self::SHORTFALL_LOT_PROCESS
            ? max(0, $gauge['max'] - (int) $gauge['stock_kbn'])
            : (int) $partlist->qty_lot;

        $loading = $partlist->loading($loadingPercent);
        $poDay = is_numeric($partlist->po_day) ? (float) $partlist->po_day : 0.0;

        return [
            'lot' => $lot,
            'loading_time' => $loading > 0 ? $lot * $partlist->qty_kbn / $loading : null,
            'coverage_days' => $gauge['stock_kbn'] !== null && $poDay > 0
                ? $gauge['stock_kbn'] / ($poDay / $partlist->qty_kbn)
                : null,
        ];
    }

    /**
     * Order cards by status group, then by lowest stock coverage (parts without coverage after those with one),
     * then by actual stock from lowest, keeping parts without stock data last.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    protected function compareCards(array $a, array $b): int
    {
        return [
            $a['has_stock_data'] ? 0 : 1,
            self::STATUS_ORDER[$a['status']],
            $a['coverage_days'] === null ? 1 : 0,
            $a['coverage_days'] ?? 0,
            $a['stock_kbn'] ?? $a['stock_pcs'],
            $a['stock_pcs'],
            $a['part_no'],
        ] <=> [
            $b['has_stock_data'] ? 0 : 1,
            self::STATUS_ORDER[$b['status']],
            $b['coverage_days'] === null ? 1 : 0,
            $b['coverage_days'] ?? 0,
            $b['stock_kbn'] ?? $b['stock_pcs'],
            $b['stock_pcs'],
            $b['part_no'],
        ];
    }

    /**
     * Total stock pcs keyed by part number and store.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    protected function stockByPartAndStore(array $rows): array
    {
        $totals = [];

        foreach ($rows as $row) {
            if (blank($row['part_no']) || blank($row['store'])) {
                continue;
            }

            $key = $this->stockParts->partKey($row['part_no'], $row['store']);
            $totals[$key] = ($totals[$key] ?? 0) + $row['stock'];
        }

        return $totals;
    }
}
