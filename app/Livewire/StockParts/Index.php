<?php

namespace App\Livewire\StockParts;

use App\Services\StockPartService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Session;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Stock Part All')]
class Index extends Component
{
    use WithPagination;

    /**
     * Columns in display order, with their label and whether they hold numbers.
     *
     * @var array<string, array{label: string, numeric?: bool}>
     */
    public const COLUMNS = [
        'id' => ['label' => 'ID', 'numeric' => true],
        'store' => ['label' => 'Store'],
        'rack_no' => ['label' => 'Rack No'],
        'process' => ['label' => 'Process'],
        'line' => ['label' => 'Line'],
        'part_no' => ['label' => 'Part No'],
        'part_no_fg' => ['label' => 'Part No FG'],
        'customer' => ['label' => 'Customer'],
        'qty_kbn' => ['label' => 'Qty/KBN', 'numeric' => true],
        'std_min' => ['label' => 'Std Min', 'numeric' => true],
        'max_kbn' => ['label' => 'Max (KBN)', 'numeric' => true],
        'max_pcs' => ['label' => 'Max (pcs)', 'numeric' => true],
        'stock' => ['label' => 'Stock', 'numeric' => true],
        'stock_prod' => ['label' => 'Stock Prod', 'numeric' => true],
        'status' => ['label' => 'Status'],
        'initial_stock' => ['label' => 'Initial Stock', 'numeric' => true],
        'initial_by' => ['label' => 'Initial By'],
        'initial_date' => ['label' => 'Initial Date'],
        'last_update_in' => ['label' => 'Last Update In'],
        'pic_update_in' => ['label' => 'PIC Update In'],
        'last_update_out' => ['label' => 'Last Update Out'],
        'pic_update_out' => ['label' => 'PIC Update Out'],
        'last_print' => ['label' => 'Last Print'],
    ];

    /** @var list<string> */
    protected const SEARCHABLE = ['part_no', 'part_no_fg', 'rack_no', 'line', 'customer', 'process'];

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $store = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: 'id')]
    public string $sortField = 'id';

    #[Url(except: 'asc')]
    public string $sortDirection = 'asc';

    public int $perPage = 25;

    /** @var list<int> */
    public const REFRESH_INTERVALS = [60, 120, 180, 240, 600];

    #[Session(key: 'stock-parts.auto-refresh')]
    public bool $autoRefresh = false;

    #[Session(key: 'stock-parts.refresh-interval')]
    public int $refreshInterval = 60;

    /**
     * Allow a bookmarked link such as ?auto=1&interval=120 to switch auto refresh on; it is then remembered in the session.
     */
    public function mount(): void
    {
        if (request()->has('auto')) {
            $this->autoRefresh = request()->boolean('auto');
        }

        if (request()->has('interval')) {
            $this->refreshInterval = (int) request()->query('interval');
        }

        $this->updatedRefreshInterval();
    }

    public function updatedRefreshInterval(): void
    {
        if (! in_array($this->refreshInterval, self::REFRESH_INTERVALS, true)) {
            $this->refreshInterval = self::REFRESH_INTERVALS[0];
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'store', 'status', 'perPage'], true)) {
            $this->resetPage();
        }
    }

    public function sortBy(string $field): void
    {
        if (! array_key_exists($field, self::COLUMNS)) {
            return;
        }

        $this->sortDirection = $this->sortField === $field && $this->sortDirection === 'asc' ? 'desc' : 'asc';
        $this->sortField = $field;
    }

    public function refresh(StockPartService $service): void
    {
        $result = $this->fetchLatest($service);

        if ($result['error'] === null) {
            session()->flash('status', 'Data stock berhasil diperbarui dari API SOS.');
        }
    }

    /**
     * Called by wire:poll while auto refresh is enabled.
     */
    public function autoRefreshTick(StockPartService $service): void
    {
        if (! $this->autoRefresh) {
            return;
        }

        $this->fetchLatest($service);
    }

    /**
     * @return array{rows: list<array<string, mixed>>, fetched_at: ?CarbonImmutable, stale: bool, error: ?string}
     */
    protected function fetchLatest(StockPartService $service): array
    {
        $result = $service->get(forceRefresh: true);
        unset($this->stock, $this->filteredRows, $this->stockParts, $this->summary, $this->stores);

        return $result;
    }

    /**
     * @return array{rows: list<array<string, mixed>>, fetched_at: ?CarbonImmutable, stale: bool, error: ?string}
     */
    #[Computed]
    public function stock(): array
    {
        return app(StockPartService::class)->get();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function filteredRows(): Collection
    {
        $search = mb_strtolower(trim($this->search));
        $sortField = array_key_exists($this->sortField, self::COLUMNS) ? $this->sortField : 'id';

        return collect($this->stock['rows'])
            ->when($this->store !== '', fn (Collection $rows) => $rows->where('store', $this->store))
            ->when($this->status !== '', fn (Collection $rows) => $rows->where('status', $this->status))
            ->when($search !== '', fn (Collection $rows) => $rows->filter(function (array $row) use ($search) {
                foreach (self::SEARCHABLE as $column) {
                    if (str_contains(mb_strtolower((string) $row[$column]), $search)) {
                        return true;
                    }
                }

                return false;
            }))
            ->sortBy($sortField, SORT_NATURAL | SORT_FLAG_CASE, $this->sortDirection === 'desc')
            ->values();
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    #[Computed]
    public function stockParts(): LengthAwarePaginator
    {
        $perPage = in_array($this->perPage, [25, 50, 100, 200], true) ? $this->perPage : 25;
        $page = $this->getPage();

        return new LengthAwarePaginator(
            $this->filteredRows->forPage($page, $perPage)->values(),
            $this->filteredRows->count(),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath(), 'pageName' => 'page'],
        );
    }

    /**
     * @return array{total: int, below_min: int, over: int, stock: int, stock_by_store: array<string, int>}
     */
    #[Computed]
    public function summary(): array
    {
        return [
            'total' => $this->filteredRows->count(),
            'below_min' => $this->filteredRows->where('status', StockPartService::STATUS_BELOW_MIN)->count(),
            'over' => $this->filteredRows->where('status', StockPartService::STATUS_OVER)->count(),
            'stock' => (int) $this->filteredRows->sum('stock'),
            'stock_by_store' => $this->filteredRows
                ->groupBy(fn (array $row) => $row['store'] ?: '(Tanpa Store)')
                ->map(fn (Collection $rows) => (int) $rows->sum('stock'))
                ->sortKeys()
                ->all(),
        ];
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function stores(): array
    {
        return collect($this->stock['rows'])->pluck('store')->filter()->unique()->sort()->values()->all();
    }

    public function render(): View
    {
        return view('livewire.stock-parts.index', [
            'columns' => self::COLUMNS,
        ]);
    }
}
