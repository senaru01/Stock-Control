<?php

namespace App\Livewire\MachineStock;

use App\Models\MachineStockControl;
use App\Models\MonitoredMachine;
use App\Services\MachineStockService;
use App\Services\StockPartService;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use RuntimeException;

#[Title('Mesin Monitoring Stok')]
class Machines extends Component
{
    public bool $showForm = false;

    public ?int $editingId = null;

    public ?int $deletingId = null;

    public string $line = '';

    public string $name = '';

    public bool $isActive = true;

    /**
     * The parts of the line with their min / max in days, edited in the form.
     *
     * @var list<array{store: ?string, part_no: ?string, rack_no: ?string, po_day: ?int, min_days: string, max_days: string, since: ?string, in_stock_data: bool}>
     */
    public array $parts = [];

    public string $bulkMinDays = '1';

    public string $bulkMaxDays = '2';

    /**
     * Open the form of a machine straight away from a link such as ?edit=1 (used by the stock control page).
     */
    public function mount(): void
    {
        $editId = request()->integer('edit');

        if ($editId > 0 && auth()->user()?->can('machines.edit') && MonitoredMachine::query()->whereKey($editId)->exists()) {
            $this->edit($editId);
        }
    }

    public function create(): void
    {
        $this->authorize('machines.edit');
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorize('machines.edit');
        $machine = MonitoredMachine::findOrFail($id);

        $this->resetForm();
        $this->editingId = $machine->id;
        $this->line = $machine->line;
        $this->name = (string) $machine->name;
        $this->isActive = $machine->is_active;
        $this->loadParts();
        $this->showForm = true;
    }

    /**
     * Show the parts of the chosen line as soon as it is picked.
     */
    public function updatedLine(): void
    {
        $this->loadParts();
    }

    /**
     * Fill the min / max of every part with the bulk values.
     */
    public function applyToAll(): void
    {
        $this->authorize('machines.edit');
        $this->bulkMinDays = self::normalizeDays($this->bulkMinDays);
        $this->bulkMaxDays = self::normalizeDays($this->bulkMaxDays);

        $this->validate([
            'bulkMinDays' => ['required', 'numeric', 'min:0', 'max:999'],
            'bulkMaxDays' => ['required', 'numeric', 'gt:bulkMinDays', 'max:999'],
        ], [], [
            'bulkMinDays' => 'min (hari)',
            'bulkMaxDays' => 'max (hari)',
        ]);

        foreach (array_keys($this->parts) as $index) {
            $this->parts[$index]['min_days'] = $this->bulkMinDays;
            $this->parts[$index]['max_days'] = $this->bulkMaxDays;
        }
    }

    public function save(MachineStockService $machineStock): void
    {
        $this->authorize('machines.edit');
        $this->line = $this->canonicalLine($machineStock, $this->line);
        $this->name = trim($this->name);

        foreach (array_keys($this->parts) as $index) {
            $this->parts[$index]['min_days'] = self::normalizeDays($this->parts[$index]['min_days']);
            $this->parts[$index]['max_days'] = self::normalizeDays($this->parts[$index]['max_days']);
        }

        $validated = $this->validate([
            'line' => [
                'required',
                'string',
                'max:50',
                Rule::unique('monitored_machines', 'line')->ignore($this->editingId),
                function (string $attribute, mixed $value, Closure $fail) {
                    if ($this->availableLines !== [] && ! in_array($value, $this->availableLines, true)) {
                        $fail('Line ini tidak ditemukan di data Stock Part All.');
                    }
                },
            ],
            'name' => ['nullable', 'string', 'max:100'],
            'isActive' => ['boolean'],
            'parts' => ['array'],
            'parts.*.min_days' => ['required', 'numeric', 'min:0', 'max:999'],
            'parts.*.max_days' => ['required', 'numeric', 'gt:parts.*.min_days', 'max:999'],
        ], [
            'parts.*.max_days.gt' => 'Max harus lebih besar dari Min.',
        ], [
            'line' => 'line / mesin',
            'name' => 'nama',
            'parts.*.min_days' => 'min (hari)',
            'parts.*.max_days' => 'max (hari)',
        ]);

        $attributes = ['line' => $validated['line'], 'name' => $validated['name'] ?: null, 'is_active' => $validated['isActive']];
        $isNew = $this->editingId === null;

        $machine = $isNew ? MonitoredMachine::create($attributes) : tap(MonitoredMachine::findOrFail($this->editingId))->update($attributes);
        $changed = $machineStock->saveControls($machine, $this->parts);
        $message = $isNew
            ? "Mesin {$machine->line} ditambahkan ke kontrol stok, Min / Max {$changed} part disimpan."
            : "Mesin {$machine->line} diperbarui, Min / Max {$changed} part berubah (berlaku mulai sekarang).";

        if ($isNew && $machine->is_active) {
            try {
                $snapshot = $machineStock->takeSnapshots(collect([$machine]), 'manual')->first();
                $message .= " Data awal {$snapshot->item_count} part tersimpan.";
            } catch (RuntimeException $exception) {
                $message .= ' Data awal belum bisa diambil ('.$exception->getMessage().'), akan diambil otomatis oleh jadwal.';
            }
        }

        session()->flash('status', $message);
        $this->closeForm();
    }

    public function toggleActive(int $id): void
    {
        $this->authorize('machines.edit');
        $machine = MonitoredMachine::findOrFail($id);
        $machine->update(['is_active' => ! $machine->is_active]);
    }

    public function confirmDelete(int $id): void
    {
        $this->authorize('machines.delete');
        $this->deletingId = $id;
    }

    public function delete(): void
    {
        $this->authorize('machines.delete');
        if ($this->deletingId) {
            $machine = MonitoredMachine::find($this->deletingId);
            $machine?->delete();

            if ($machine) {
                session()->flash('status', "Mesin {$machine->line} beserta history-nya dihapus.");
            }
        }

        $this->deletingId = null;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    /**
     * @return Collection<int, MonitoredMachine>
     */
    #[Computed]
    public function machines(): Collection
    {
        return MonitoredMachine::query()
            ->with('latestSnapshot')
            ->withCount('snapshots')
            ->orderByDesc('is_active')
            ->orderBy('line')
            ->get();
    }

    /**
     * Number of parts with a min / max setting, per machine id.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function controlledParts(): array
    {
        return MachineStockControl::query()
            ->get(['monitored_machine_id', 'store', 'part_no'])
            ->groupBy('monitored_machine_id')
            ->map(fn (Collection $controls) => $controls->unique(fn (MachineStockControl $control) => "{$control->part_no}|{$control->store}")->count())
            ->all();
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function availableLines(): array
    {
        return app(MachineStockService::class)->availableLines();
    }

    /**
     * Show whole days without decimals (1 instead of 1.000) and fractions as short as possible (0.5, 0.125).
     */
    public static function formatDays(float $days): string
    {
        return rtrim(rtrim(number_format($days, 3, '.', ''), '0'), '.');
    }

    /**
     * Accept a decimal comma as well as a point ("0,1" = "0.1").
     */
    public static function normalizeDays(mixed $days): string
    {
        return str_replace(',', '.', trim((string) $days));
    }

    public function render(): View
    {
        return view('livewire.machine-stock.machines', [
            'slotHours' => MachineStockService::SLOT_HOURS,
        ]);
    }

    /**
     * List the parts of the line in Stock Part All with their current setting; parts never set start
     * with the bulk values. Set parts that are no longer in Stock Part All stay listed so they can be changed.
     */
    protected function loadParts(): void
    {
        $machineStock = app(MachineStockService::class);
        $stockParts = app(StockPartService::class);
        $line = $this->canonicalLine($machineStock, $this->line);
        $machine = $this->editingId ? MonitoredMachine::find($this->editingId) : null;
        $controls = $machine ? $machineStock->currentControls($machine) : collect();

        $rows = collect($line === '' ? [] : $machineStock->lineParts($line))->map(fn (array $part) => [...$part, 'in_stock_data' => true]);
        $listed = $rows->map(fn (array $part) => $stockParts->partKey($part['part_no'], $part['store']))->all();
        $missing = $controls
            ->reject(fn (MachineStockControl $control, string $key) => in_array($key, $listed, true))
            ->map(fn (MachineStockControl $control) => ['store' => $control->store, 'part_no' => $control->part_no, 'rack_no' => null, 'po_day' => null, 'in_stock_data' => false]);

        $this->parts = $rows->concat($missing->values())
            ->map(function (array $part) use ($controls, $stockParts) {
                $control = $controls->get($stockParts->partKey($part['part_no'], $part['store']));

                return [
                    ...$part,
                    'min_days' => $control ? self::formatDays($control->min_days) : $this->bulkMinDays,
                    'max_days' => $control ? self::formatDays($control->max_days) : $this->bulkMaxDays,
                    'since' => $control?->effective_from->timezone('Asia/Jakarta')->format('d M Y H:i'),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Match typed text to the line as written in Stock Part All, ignoring case and extra spaces.
     */
    protected function canonicalLine(MachineStockService $machineStock, string $line): string
    {
        $key = $machineStock->lineKey(preg_replace('/\s+/', ' ', $line));

        foreach ($this->availableLines as $availableLine) {
            if ($machineStock->lineKey($availableLine) === $key) {
                return $availableLine;
            }
        }

        return trim($line);
    }

    protected function resetForm(): void
    {
        $this->resetValidation();
        $this->editingId = null;
        $this->line = '';
        $this->name = '';
        $this->isActive = true;
        $this->parts = [];
        $this->bulkMinDays = self::formatDays(MachineStockService::DEFAULT_MIN_DAYS);
        $this->bulkMaxDays = self::formatDays(MachineStockService::DEFAULT_MAX_DAYS);
    }
}
