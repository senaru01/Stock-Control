@php
    $machine = $this->selectedMachine;
    $chart = $this->chart;
    $latest = $this->latest;
    $parts = $this->visibleParts;
    $statusLabels = ['below_min' => '< Min', 'safe' => 'Aman', 'over' => 'Over', 'no_param' => 'Belum diatur'];
    $statusClasses = [
        'below_min' => 'bg-rose-100 text-rose-700',
        'safe' => 'bg-emerald-100 text-emerald-700',
        'over' => 'bg-violet-100 text-violet-700',
        'no_param' => 'bg-slate-200 text-slate-600',
    ];
    $statusLabel = fn (string $status, ?float $minDays) => $status === 'no_param' && $minDays !== null ? 'Tanpa PO/day' : $statusLabels[$status];
    $lastValue = fn (array $values) => collect($values)->filter(fn ($value) => $value !== null)->last();
@endphp

<div class="space-y-4">
    @if (session('status'))
        <div x-data="{ show: true }" x-init="setTimeout(() => show = false, 5000)" x-show="show" x-transition class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('status') }}
        </div>
    @endif
    @if (session('error'))
        <div class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ session('error') }}</div>
    @endif

    @if ($this->machines->isEmpty())
        <div class="rounded-xl bg-white px-6 py-16 text-center shadow-sm ring-1 ring-slate-200">
            <p class="text-sm font-semibold text-slate-900">Belum ada mesin yang dimonitor.</p>
            <p class="mt-1 text-sm text-slate-500">Tambahkan mesin (line) yang stoknya ingin dikontrol.</p>
            @can('machines.edit')
            <a href="{{ route('machine-stock.machines') }}" wire:navigate class="mt-4 inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">Tambah Mesin</a>
            @endcan
        </div>
    @else
        {{-- Machine picker --}}
        <div class="flex flex-col gap-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Mesin</span>
                @foreach ($this->machines as $machineOption)
                    <button
                        type="button"
                        wire:key="machine-tab-{{ $machineOption->id }}"
                        wire:click="$set('machine', {{ $machineOption->id }})"
                        title="{{ $machineOption->label() }}{{ $machineOption->is_active ? '' : ' (nonaktif)' }}"
                        @class([
                            'rounded-lg px-3 py-1.5 text-sm font-semibold transition',
                            'bg-indigo-600 text-white' => $machine?->id === $machineOption->id,
                            'bg-slate-100 text-slate-700 hover:bg-slate-200' => $machine?->id !== $machineOption->id,
                            'opacity-60' => ! $machineOption->is_active,
                        ])
                    >{{ $machineOption->line }}</button>
                @endforeach
            </div>

            <div class="flex flex-wrap gap-2">
                @can('machines.view')
                <a href="{{ route('machine-stock.machines') }}" wire:navigate class="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Tambah / Kelola Mesin
                </a>
                @endcan
                @can('machine_stock.edit')
                <button type="button" wire:click="takeSnapshotNow" wire:loading.attr="disabled" wire:target="takeSnapshotNow" class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-60">
                    <svg class="size-4" wire:loading.class="animate-spin" wire:target="takeSnapshotNow" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                    </svg>
                    Ambil Data Sekarang
                </button>
                @endcan
            </div>
        </div>

        {{-- Min / max per part --}}
        @php
            $latestItems = collect($latest['items'] ?? []);
            $controlledCount = $latestItems->whereNotNull('min_days')->count();
            $missingPoDay = $latestItems->whereNotNull('min_days')->whereNull('po_day')->count();
        @endphp
        <div class="flex flex-col gap-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200 md:flex-row md:items-center md:justify-between">
            <div class="text-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Min / Max per part {{ $machine?->line }}</p>
                <p class="text-slate-700">
                    <span @class(['text-lg font-bold', 'text-slate-900' => $controlledCount >= $latestItems->count(), 'text-amber-600' => $controlledCount < $latestItems->count()])>{{ $controlledCount }} dari {{ $latestItems->count() }} part diatur</span>
                    <span class="text-xs text-slate-500">· dalam hari, pcs = hari × PO/day{{ $missingPoDay ? ' · '.$missingPoDay.' part tanpa PO/day' : '' }}</span>
                </p>
            </div>
            @can('machines.edit')
            <a href="{{ route('machine-stock.machines', ['edit' => $machine?->id]) }}" wire:navigate class="rounded-lg border border-indigo-300 px-4 py-2 text-center text-sm font-semibold text-indigo-600 hover:bg-indigo-50 dark:text-indigo-400">Atur Min / Max per Part</a>
            @endcan
        </div>

        {{-- Current condition --}}
        @php
            $items = collect($latest['items'] ?? []);
            $tiles = [
                ['label' => 'Pengambilan terakhir', 'value' => $latest ? \Carbon\CarbonImmutable::parse($latest['snapshot']->local_date)->format('d M Y').' · '.$latest['snapshot']->local_time : 'Belum ada data', 'hint' => $latest ? $items->count().' part · otomatis tiap '.$slotHours.' jam' : 'Otomatis tiap '.$slotHours.' jam'],
                ['label' => 'Stock < Min', 'value' => $items->where('stock_status', 'below_min')->count(), 'tone' => 'text-rose-600', 'hint' => 'Over Max: '.$items->where('stock_status', 'over')->count()],
                ['label' => 'Stock Prod < Min', 'value' => $items->where('stock_prod_status', 'below_min')->count(), 'tone' => 'text-rose-600', 'hint' => 'Over Max: '.$items->where('stock_prod_status', 'over')->count()],
                ['label' => 'Total Stock / Stock Prod', 'value' => $latest ? number_format($items->sum('stock')).' / '.number_format($items->sum('stock_prod')) : '-', 'hint' => $latest ? 'Min '.number_format($items->whereNotNull('min')->sum('min')).' · Max '.number_format($items->whereNotNull('max')->sum('max')).' pcs'.($items->whereNull('po_day')->count() ? ' · '.$items->whereNull('po_day')->count().' part tanpa PO/day' : '') : ''],
            ];
        @endphp
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($tiles as $tile)
                <div wire:key="tile-{{ $loop->index }}" class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $tile['label'] }}</p>
                    <p @class(['mt-1 text-2xl font-bold tabular-nums', $tile['tone'] ?? 'text-slate-900'])>{{ $tile['value'] }}</p>
                    <p class="mt-0.5 text-xs text-slate-500">{{ $tile['hint'] }}</p>
                </div>
            @endforeach
        </div>

        {{-- Period filters --}}
        <div class="flex flex-col gap-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200 md:flex-row md:items-center">
            <div class="inline-flex self-start rounded-lg bg-slate-100 p-1 text-sm font-medium" role="group" aria-label="Periode grafik">
                @foreach ($periods as $value => $label)
                    <button
                        type="button"
                        wire:click="$set('period', '{{ $value }}')"
                        @class([
                            'rounded-md px-4 py-1.5 transition',
                            'bg-white text-slate-900 shadow-sm' => $period === $value,
                            'text-slate-500 hover:text-slate-700' => $period !== $value,
                        ])
                    >{{ $label }}</button>
                @endforeach
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <button type="button" wire:click="previous" aria-label="Periode sebelumnya" class="rounded-lg border border-slate-300 p-2 text-slate-600 hover:bg-slate-100">
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
                    </svg>
                </button>
                <input type="{{ $anchorInput }}" wire:model.live="anchor" wire:key="anchor-{{ $anchorInput }}" aria-label="Pilih periode" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <button type="button" wire:click="next" aria-label="Periode berikutnya" class="rounded-lg border border-slate-300 p-2 text-slate-600 hover:bg-slate-100">
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                    </svg>
                </button>
                <button type="button" wire:click="today" class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">Hari ini</button>
                <span class="text-sm font-medium text-slate-700">
                    {{ $periodLabel }} ·
                    @if ($period === 'day') {{ $dayStart }} – {{ $dayStart }} besok @elseif ($period === 'week') Senin {{ $dayStart }} – Senin {{ $dayStart }} @else Tgl 1 {{ $dayStart }} – tgl 1 bulan berikutnya @endif
                </span>
            </div>
        </div>

        @if ($chart === null || $chart['snapshot_count'] === 0)
            <div class="rounded-xl bg-white px-6 py-16 text-center shadow-sm ring-1 ring-slate-200">
                <p class="text-sm font-semibold text-slate-900">Belum ada data {{ $machine?->line }} untuk periode ini.</p>
                <p class="mt-1 text-sm text-slate-500">Data terisi otomatis setiap {{ $slotHours }} jam mulai {{ $dayStart }}, atau klik <span class="font-semibold">Ambil Data Sekarang</span>.</p>
            </div>
        @else
            @php $chartPayload = ['labels' => $chart['labels'], 'ticks' => $chart['ticks'], 'total' => $chart['total'], 'parts' => $parts]; @endphp
            <div class="space-y-4" wire:key="machine-charts-{{ md5(json_encode($chartPayload)) }}" x-data="machineStockCharts(@js($chartPayload))">
                {{-- Legend (shared by every chart) --}}
                <div class="flex flex-wrap items-center gap-x-5 gap-y-2 rounded-xl bg-white px-4 py-3 text-xs text-slate-600 shadow-sm ring-1 ring-slate-200">
                    <span class="flex items-center gap-2"><span class="h-0.5 w-5 rounded bg-[#2a78d6] dark:bg-[#3987e5]"></span>Stock</span>
                    <span class="flex items-center gap-2"><span class="h-0.5 w-5 rounded bg-[#eb6834] dark:bg-[#d95926]"></span>Stock Prod</span>
                    <span class="flex items-center gap-2"><span class="w-5 border-t-2 border-dashed border-slate-500"></span>Max</span>
                    <span class="flex items-center gap-2"><span class="w-5 border-t-2 border-dotted border-slate-500"></span>Min</span>
                    <span class="flex items-center gap-2"><span class="h-3 w-5 rounded-sm bg-emerald-500/15"></span>Zona aman (Min – Max)</span>
                    <span class="text-slate-400">· {{ $chart['snapshot_count'] }} pengambilan · titik tiap {{ $slotHours }} jam</span>
                </div>

                {{-- Machine total --}}
                <section class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                    <div class="mb-3 flex flex-wrap items-end justify-between gap-2">
                        <div>
                            <h2 class="text-sm font-semibold text-slate-900">Total {{ $machine->label() }} (pcs)</h2>
                            <p class="text-xs text-slate-500">Jumlah seluruh part · Min / Max total dari part yang sudah diatur dan punya PO/day</p>
                        </div>
                        <div class="flex gap-6 text-right">
                            <div>
                                <p class="text-xs text-slate-500">Stock</p>
                                <p class="text-xl font-semibold tabular-nums text-slate-900">{{ number_format((int) $lastValue($chart['total']['stock'])) }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-slate-500">Stock Prod</p>
                                <p class="text-xl font-semibold tabular-nums text-slate-900">{{ number_format((int) $lastValue($chart['total']['stock_prod'])) }}</p>
                            </div>
                        </div>
                    </div>
                    <div class="relative h-80">
                        <canvas x-ref="total" role="img" aria-label="Grafik garis total stock dan stock prod {{ $machine->line }} terhadap min dan max"></canvas>
                    </div>
                </section>

                {{-- Part filters --}}
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <h2 class="text-sm font-semibold text-slate-900">Per part <span class="font-normal text-slate-500">({{ count($parts) }} dari {{ count($chart['parts']) }})</span></h2>
                    <div class="flex flex-wrap items-center gap-3">
                        <select wire:model.live="store" aria-label="Filter store" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            <option value="">Semua store</option>
                            @foreach ($this->stores as $storeOption)
                                <option value="{{ $storeOption }}">{{ $storeOption }}</option>
                            @endforeach
                        </select>
                        <label class="flex items-center gap-2 text-sm text-slate-700">
                            <input type="checkbox" wire:model.live="onlyAlerts" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                            Hanya yang keluar Min / Max
                        </label>
                    </div>
                </div>

                {{-- Small multiples per part --}}
                <div class="grid grid-cols-1 gap-4 lg:grid-cols-2 2xl:grid-cols-3">
                    @forelse ($parts as $index => $part)
                        @php
                            $stockNow = $lastValue($part['stock']);
                            $prodNow = $lastValue($part['stock_prod']);
                            $minNow = $lastValue($part['min']);
                            $maxNow = $lastValue($part['max']);
                            $stockStatus = $stockNow !== null ? app(\App\Services\MachineStockService::class)->statusOf($stockNow, $minNow, $maxNow) : null;
                        @endphp
                        <section wire:key="part-chart-{{ $part['key'] }}" class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                            <div class="mb-2 flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <h3 class="truncate text-sm font-semibold text-slate-900">{{ $part['part_no'] }}</h3>
                                    <p class="truncate text-xs text-slate-500">{{ $part['store'] }}{{ $part['rack_no'] ? ' · '.$part['rack_no'] : '' }} · PO/day {{ $part['po_day'] !== null ? number_format($part['po_day']) : '-' }} · @if ($part['min_days'] !== null) Min {{ \App\Livewire\MachineStock\Machines::formatDays($part['min_days']) }} hr ({{ $minNow !== null ? number_format($minNow) : '-' }}) · Max {{ \App\Livewire\MachineStock\Machines::formatDays($part['max_days']) }} hr ({{ $maxNow !== null ? number_format($maxNow) : '-' }}) @else <span class="text-amber-600">Min / Max belum diatur</span> @endif</p>
                                </div>
                                <div class="shrink-0 text-right">
                                    @if ($stockStatus)
                                        <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $statusClasses[$stockStatus] }}">{{ $statusLabel($stockStatus, $part['min_days']) }}</span>
                                    @endif
                                    <p class="mt-1 text-xs tabular-nums text-slate-600">
                                        <span class="font-semibold text-slate-900">{{ $stockNow !== null ? number_format($stockNow) : '-' }}</span> /
                                        <span class="font-semibold text-slate-900">{{ $prodNow !== null ? number_format($prodNow) : '-' }}</span>
                                        @if ($stockNow !== null && $part['po_day'])
                                            <span class="block text-slate-500">≈ {{ number_format($stockNow / $part['po_day'], 1) }} hari</span>
                                        @endif
                                    </p>
                                </div>
                            </div>
                            <div class="relative h-48">
                                <canvas data-part="{{ $index }}" role="img" aria-label="Grafik stock dan stock prod {{ $part['part_no'] }} terhadap min dan max"></canvas>
                            </div>
                        </section>
                    @empty
                        <div class="col-span-full rounded-xl bg-white px-6 py-10 text-center text-sm text-slate-500 shadow-sm ring-1 ring-slate-200">
                            Tidak ada part yang cocok dengan filter.
                        </div>
                    @endforelse
                </div>
            </div>
        @endif

        {{-- Latest condition table --}}
        @if ($latest)
            <section class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200" x-data="{ open: false }">
                <button type="button" @click="open = ! open" class="flex w-full items-center justify-between px-4 py-3 text-sm font-semibold text-slate-900 hover:bg-slate-50">
                    Tabel kondisi terakhir ({{ $latest['snapshot']->local_time }} WIB)
                    <svg class="size-4 transition-transform" :class="open && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                    </svg>
                </button>
                <div x-show="open" x-cloak class="overflow-x-auto border-t border-slate-200">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50">
                            <tr>
                                @foreach (['Store', 'Rack', 'Part No', 'PO/day', 'Min–Max (hari)', 'Min', 'Max', 'Stock', 'Hari', 'Status', 'Stock Prod', 'Status'] as $header)
                                    <th @class(['whitespace-nowrap px-3 py-2 text-xs font-semibold uppercase tracking-wide text-slate-600', 'text-right' => in_array($header, ['PO/day', 'Min', 'Max', 'Stock', 'Hari', 'Stock Prod'], true), 'text-left' => ! in_array($header, ['PO/day', 'Min', 'Max', 'Stock', 'Hari', 'Stock Prod'], true)])>{{ $header }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($latest['items'] as $item)
                                <tr wire:key="latest-{{ $item['store'] }}-{{ $item['part_no'] }}-{{ $loop->index }}">
                                    <td class="whitespace-nowrap px-3 py-2 text-slate-700">{{ $item['store'] }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-slate-700">{{ $item['rack_no'] }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 font-medium text-slate-900">{{ $item['part_no'] }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-slate-700">{{ $item['po_day'] !== null ? number_format($item['po_day']) : '-' }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-slate-700">{{ $item['min_days'] !== null ? \App\Livewire\MachineStock\Machines::formatDays($item['min_days']).' – '.\App\Livewire\MachineStock\Machines::formatDays($item['max_days']) : 'belum diatur' }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-slate-700">{{ $item['min'] !== null ? number_format($item['min']) : '-' }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-slate-700">{{ $item['max'] !== null ? number_format($item['max']) : '-' }}</td>
                                    @foreach (['stock', 'stock_prod'] as $measure)
                                        <td class="whitespace-nowrap px-3 py-2 text-right font-semibold tabular-nums text-slate-900">{{ number_format($item[$measure]) }}</td>
                                        @if ($measure === 'stock')
                                            <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-slate-700">{{ $item['stock_days'] !== null ? number_format($item['stock_days'], 1) : '-' }}</td>
                                        @endif
                                        <td class="whitespace-nowrap px-3 py-2">
                                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $statusClasses[$item[$measure.'_status']] }}">{{ $statusLabel($item[$measure.'_status'], $item['min_days']) }}</span>
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    @endif
</div>
