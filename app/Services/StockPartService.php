<?php

namespace App\Services;

use App\Models\Partlist;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use UnexpectedValueException;

class StockPartService
{
    public const CACHE_KEY = 'sos.stock_part';

    public const STATUS_BELOW_MIN = 'below_min';

    public const STATUS_SAFE = 'safe';

    public const STATUS_OVER = 'over';

    /**
     * Stock quantity columns that are never allowed to go below zero.
     *
     * @var list<string>
     */
    public const QUANTITY_COLUMNS = ['std_min', 'stock', 'stock_prod', 'initial_stock'];

    /**
     * Get stock part rows from the SOS API, served from cache while it is still fresh.
     *
     * When the API fails, the last cached rows are returned and flagged as stale.
     * Rows are enriched with the partlist max and a stock status every time they are read.
     *
     * @return array{rows: list<array<string, mixed>>, fetched_at: ?CarbonImmutable, stale: bool, error: ?string}
     */
    public function get(bool $forceRefresh = false): array
    {
        /** @var array{rows: list<array<string, mixed>>, fetched_at: int}|null $cached */
        $cached = Cache::get(self::CACHE_KEY);
        $fetchedAt = $cached !== null ? CarbonImmutable::createFromTimestamp($cached['fetched_at']) : null;
        $isFresh = $fetchedAt?->greaterThan(now()->subMinutes((int) config('services.sos.cache_minutes'))) ?? false;

        if ($isFresh && ! $forceRefresh) {
            return ['rows' => $this->withPartlistLimits($cached['rows']), 'fetched_at' => $fetchedAt, 'stale' => false, 'error' => null];
        }

        try {
            $rows = $this->fetch();
            $fetchedAt = CarbonImmutable::now();
            Cache::forever(self::CACHE_KEY, ['rows' => $rows, 'fetched_at' => $fetchedAt->getTimestamp()]);

            return ['rows' => $this->withPartlistLimits($rows), 'fetched_at' => $fetchedAt, 'stale' => false, 'error' => null];
        } catch (ConnectionException|RequestException|UnexpectedValueException $exception) {
            report($exception);

            return [
                'rows' => $this->withPartlistLimits($cached['rows'] ?? []),
                'fetched_at' => $fetchedAt,
                'stale' => true,
                'error' => match (true) {
                    $exception instanceof ConnectionException => 'Tidak dapat terhubung ke API SOS (timeout / jaringan).',
                    $exception instanceof RequestException => "API SOS merespons dengan status {$exception->response->status()}.",
                    default => 'Format respons API SOS tidak dikenali.',
                },
            ];
        }
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws ConnectionException|RequestException|UnexpectedValueException
     */
    protected function fetch(): array
    {
        $rows = Http::timeout((int) config('services.sos.timeout'))
            ->retry(2, 500, throw: false)
            ->acceptJson()
            ->get(config('services.sos.url'), [
                'table' => 'tbl_stock_part',
                'api' => config('services.sos.key'),
                'menuid' => 'stockstockpart',
            ])
            ->throw()
            ->json('data');

        if (! is_array($rows)) {
            throw new UnexpectedValueException('Format respons API tidak dikenali.');
        }

        return array_map($this->normalize(...), array_values($rows));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function normalize(array $row): array
    {
        $text = fn (string $key): ?string => filled($row[$key] ?? null) ? trim((string) $row[$key]) : null;
        $number = fn (string $key): ?int => is_numeric($row[$key] ?? null) ? (int) $row[$key] : null;

        return [
            'id' => $number('id'),
            'store' => $text('store'),
            'rack_no' => $text('rack_no'),
            'process' => $text('process'),
            'line' => $text('line'),
            'part_no' => $text('part_no'),
            'part_no_fg' => $text('part_no_fg'),
            'customer' => $text('customer'),
            'std_min' => $number('std_min') ?? 0,
            'stock' => $number('stock') ?? 0,
            'stock_prod' => $number('stock_prod') ?? 0,
            'initial_stock' => $number('initial_stock'),
            'initial_by' => $text('initial_by'),
            'initial_date' => $text('initial_date'),
            'last_update_in' => $text('last_update_in'),
            'pic_update_in' => $text('pic_update_in'),
            'last_update_out' => $text('last_update_out'),
            'pic_update_out' => $text('pic_update_out'),
            'last_print' => $text('last_print'),
        ];
    }

    /**
     * Clamp negative quantities to zero and attach the partlist max (kanban converted to pcs) and stock status.
     *
     * Rows are matched to the partlist by part number and store = partlist level.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    protected function withPartlistLimits(array $rows): array
    {
        $limits = Partlist::query()
            ->select(['part_no', 'level', 'qty_kbn', 'stock_max'])
            ->get()
            ->mapWithKeys(fn (Partlist $partlist) => [$this->partKey($partlist->part_no, $partlist->level) => $partlist])
            ->all();

        return array_map(function (array $row) use ($limits) {
            foreach (self::QUANTITY_COLUMNS as $column) {
                if ($row[$column] !== null) {
                    $row[$column] = max(0, $row[$column]);
                }
            }

            $partlist = $limits[$this->partKey($row['part_no'], $row['store'])] ?? null;
            $hasMax = $partlist !== null && $partlist->stock_max > 0 && $partlist->qty_kbn > 0;

            $row['qty_kbn'] = $partlist?->qty_kbn;
            $row['max_kbn'] = $partlist?->stock_max;
            $row['max_pcs'] = $hasMax ? $partlist->stock_max * $partlist->qty_kbn : null;
            $row['status'] = match (true) {
                $row['stock'] < $row['std_min'] => self::STATUS_BELOW_MIN,
                $row['max_pcs'] !== null && $row['stock'] > $row['max_pcs'] => self::STATUS_OVER,
                default => self::STATUS_SAFE,
            };

            return $row;
        }, $rows);
    }

    public function partKey(?string $partNo, ?string $store): string
    {
        return mb_strtoupper(trim((string) $partNo)).'|'.mb_strtoupper(trim((string) $store));
    }
}
