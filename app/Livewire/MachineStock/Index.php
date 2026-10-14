<?php

namespace App\Livewire\MachineStock;

use App\Models\MachineSnapshot;
use App\Models\MonitoredMachine;
use App\Services\MachineStockService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

#[Title('Kontrol Stok Mesin')]
class Index extends Component
{
    /** @var array<string, string> */
    public const PERIODS = [
        MachineStockService::PERIOD_DAY => 'Hari',
        MachineStockService::PERIOD_WEEK => 'Minggu',
        MachineStockService::PERIOD_MONTH => 'Bulan',
    ];

    #[Url(except: null)]
    public ?int $machine = null;

    #[Url(as: 'view', except: MachineStockService::PERIOD_DAY)]
    public string $period = MachineStockService::PERIOD_DAY;

    /** The date (day), the Monday of the week (week) or Y-m (month) being shown. */
    #[Url(as: 'date', except: '')]
    public string $anchor = '';

    #[Url(except: '')]
    public string $store = '';

    #[Url(as: 'alert', except: false)]
    public bool $onlyAlerts = false;

    public function mount(): void
    {
        if (! array_key_exists($this->period, self::PERIODS)) {
            $this->period = MachineStockService::PERIOD_DAY;
        }

        $this->anchor = app(MachineStockService::class)->normalizeAnchor($this->period, $this->anchor);

        if ($this->selectedMachine === null) {
            $this->machine = $this->machines->first()?->id;
        }
    }

    /**
     * Keep the shown period around the same date when switching between day, week and month.
     */
    public function updatedPeriod(): void
    {
        if (! array_key_exists($this->period, self::PERIODS)) {
            $this->period = MachineStockService::PERIOD_DAY;
        }

        $date = strlen($this->anchor) === 7 ? $this->anchor.'-01' : $this->anchor;
        $this->anchor = app(MachineStockService::class)->normalizeAnchor($this->period, $date);
    }

    public function updatedAnchor(): void
    {
        $this->anchor = app(MachineStockService::class)->normalizeAnchor($this->period, $this->anchor);
    }

    public function updatedMachine(): void
    {
        $this->store = '';
    }

    public function previous(): void
    {
        $this->anchor = app(MachineStockService::class)->shiftAnchor($this->period, $this->anchor, -1);
    }

    public function next(): void
    {
        $this->anchor = app(MachineStockService::class)->shiftAnchor($this->period, $this->anchor, 1);
    }

    public function today(): void
    {
        $this->anchor = app(MachineStockService::class)->normalizeAnchor($this->period, null);
    }

    public function takeSnapshotNow(MachineStockService $machineStock): void
    {
        $this->authorize('machine_stock.edit');
        if ($this->selectedMachine === null) {
            return;
        }

        try {
            $snapshot = $machineStock->takeSnapshots(collect([$this->selectedMachine]), 'manual')->first();
        } catch (RuntimeException $exception) {
            session()->flash('error', 'Gagal mengambil data: '.$exception->getMessage());

            return;
        }

        $this->anchor = $machineStock->normalizeAnchor($this->period, $snapshot->local_date);
        unset($this->chart, $this->latest);

        session()->flash('status', "Data {$this->selectedMachine->line} {$snapshot->local_date} {$snapshot->local_time} tersimpan (slot {$snapshot->slot_time}).");
    }

    /**
     * @return Collection<int, MonitoredMachine>
     */
    #[Computed]
    public function machines(): Collection
    {
        return MonitoredMachine::query()->orderByDesc('is_active')->orderBy('line')->get();
    }

    #[Computed]
    public function selectedMachine(): ?MonitoredMachine
    {
        return $this->machines->firstWhere('id', $this->machine);
    }

    /**
     * @return array{labels: list<string>, ticks: list<string>, total: array<string, list<?int>>, parts: list<array<string, mixed>>, snapshot_count: int}|null
     */
    #[Computed]
    public function chart(): ?array
    {
        if ($this->selectedMachine === null) {
            return null;
        }

        return app(MachineStockService::class)->chart($this->selectedMachine, $this->period, $this->anchor);
    }

    /**
     * Part charts left after the store and "only out of min / max" filters.
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function visibleParts(): array
    {
        return collect($this->chart['parts'] ?? [])
            ->when($this->store !== '', fn (Collection $parts) => $parts->where('store', $this->store))
            ->when($this->onlyAlerts, fn (Collection $parts) => $parts->where('alerts', '>', 0))
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function stores(): array
    {
        return collect($this->chart['parts'] ?? [])->pluck('store')->filter()->unique()->sort()->values()->all();
    }

    /**
     * @return array{snapshot: MachineSnapshot, items: list<array<string, mixed>>}|null
     */
    #[Computed]
    public function latest(): ?array
    {
        return $this->selectedMachine ? app(MachineStockService::class)->latest($this->selectedMachine) : null;
    }

    public function render(): View
    {
        $machineStock = app(MachineStockService::class);

        return view('livewire.machine-stock.index', [
            'periods' => self::PERIODS,
            'periodLabel' => $machineStock->periodLabel($this->period, $this->anchor),
            'anchorInput' => $this->period === MachineStockService::PERIOD_MONTH ? 'month' : 'date',
            'slotHours' => MachineStockService::SLOT_HOURS,
            'dayStart' => sprintf('%02d:00', MachineStockService::DAY_START_HOUR),
        ]);
    }
}
