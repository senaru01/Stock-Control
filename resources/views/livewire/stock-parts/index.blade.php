<div class="space-y-4">
    @if (session('status'))
        <div
            x-data="{ show: true }"
            x-init="setTimeout(() => show = false, 4000)"
            x-show="show"
            x-transition
            class="flex items-center justify-between rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800"
        >
            <span>{{ session('status') }}</span>
            <button type="button" class="text-emerald-600 hover:text-emerald-800" @click="show = false">&times;</button>
        </div>
    @endif

    @if ($this->stock['error'])
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            {{ $this->stock['error'] }}
            @if ($this->stock['fetched_at'])
                Menampilkan data terakhir dari {{ $this->stock['fetched_at']->timezone(config('app.timezone'))->format('d M Y H:i:s') }}.
            @endif
        </div>
    @endif

    {{-- Summary --}}
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Total Item</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-slate-900">{{ number_format($this->summary['total']) }}</p>
        </div>
        <button type="button" wire:click="$set('status', 'below_min')" class="rounded-xl bg-white p-4 text-left shadow-sm ring-1 ring-slate-200 hover:ring-rose-300">
            <p class="text-xs font-semibold uppercase tracking-wide text-rose-600">Di Bawah Std Min</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-rose-600">{{ number_format($this->summary['below_min']) }}</p>
        </button>
        <button type="button" wire:click="$set('status', 'over')" class="rounded-xl bg-white p-4 text-left shadow-sm ring-1 ring-slate-200 hover:ring-violet-300">
            <p class="text-xs font-semibold uppercase tracking-wide text-violet-600">Over Max</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-violet-600">{{ number_format($this->summary['over']) }}</p>
        </button>
        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Total Stock (pcs)</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-slate-900">{{ number_format($this->summary['stock']) }}</p>
        </div>
    </div>

    {{-- Stock per store --}}
    <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
        <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-500">Total Stock per Store (pcs)</p>
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 md:grid-cols-5 xl:grid-cols-10">
            @foreach ($this->summary['stock_by_store'] as $storeName => $storeStock)
                <button
                    type="button"
                    wire:key="store-total-{{ $storeName }}"
                    wire:click="$set('store', @js($store === $storeName ? '' : $storeName))"
                    @class([
                        'rounded-lg px-3 py-2 text-left ring-1 transition',
                        'bg-indigo-50 ring-indigo-400' => $store === $storeName,
                        'bg-slate-50 ring-slate-200 hover:ring-indigo-300' => $store !== $storeName,
                    ])
                >
                    <p class="truncate text-[11px] font-semibold uppercase text-slate-500">{{ $storeName }}</p>
                    <p class="text-base font-bold tabular-nums text-slate-900">{{ number_format($storeStock) }}</p>
                </button>
            @endforeach
            <div class="rounded-lg bg-indigo-600 px-3 py-2 text-white">
                <p class="text-[11px] font-semibold uppercase text-white/80">Total Stock</p>
                <p class="text-base font-bold tabular-nums">{{ number_format($this->summary['stock']) }}</p>
            </div>
        </div>
    </div>

    {{-- Toolbar --}}
    <div class="flex flex-col gap-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200 xl:flex-row xl:items-center xl:justify-between">
        <div class="flex flex-1 flex-col gap-3 sm:flex-row sm:flex-wrap">
            <div class="relative flex-1 sm:max-w-sm">
                <svg class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                </svg>
                <input
                    type="search"
                    wire:model.live.debounce.400ms="search"
                    placeholder="Cari part no, rack, line, customer..."
                    class="w-full rounded-lg border border-slate-300 py-2 pl-9 pr-3 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20"
                >
            </div>

            <select wire:model.live="store" class="rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20">
                <option value="">Semua Store</option>
                @foreach ($this->stores as $storeOption)
                    <option value="{{ $storeOption }}">{{ $storeOption }}</option>
                @endforeach
            </select>

            <select wire:model.live="status" class="rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20">
                <option value="">Semua Status</option>
                <option value="safe">Aman</option>
                <option value="below_min">Di bawah Std Min</option>
                <option value="over">Over Max</option>
            </select>

            <select wire:model.live="perPage" class="rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20">
                @foreach ([25, 50, 100, 200] as $size)
                    <option value="{{ $size }}">{{ $size }} / halaman</option>
                @endforeach
            </select>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            @if ($this->stock['fetched_at'])
                <span class="text-xs text-slate-500">
                    Update: {{ $this->stock['fetched_at']->timezone(config('app.timezone'))->format('d M Y H:i:s') }}
                </span>
            @endif

            <div @class([
                'flex items-center gap-2 rounded-lg border px-3 py-1.5',
                'border-emerald-300 bg-emerald-50' => $autoRefresh,
                'border-slate-300' => ! $autoRefresh,
            ])>
                <label class="flex cursor-pointer items-center gap-2 text-sm font-medium text-slate-700">
                    <input type="checkbox" wire:model.live="autoRefresh" class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
                    Auto refresh
                </label>
                <select
                    wire:model.live="refreshInterval"
                    aria-label="Interval auto refresh"
                    class="rounded-md border border-slate-300 bg-white py-1 pl-2 pr-7 text-sm focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20"
                >
                    @foreach (\App\Livewire\StockParts\Index::REFRESH_INTERVALS as $interval)
                        <option value="{{ $interval }}">{{ $interval }} detik</option>
                    @endforeach
                </select>

                @if ($autoRefresh)
                    <span
                        wire:key="auto-refresh-{{ $refreshInterval }}-{{ $this->stock['fetched_at']?->getTimestamp() }}"
                        wire:poll.{{ $refreshInterval }}s.keep-alive="autoRefreshTick"
                        x-data="{
                            interval: {{ $refreshInterval }},
                            remaining: {{ $refreshInterval }},
                            timer: null,
                            init() { this.timer = setInterval(() => this.remaining = this.remaining <= 1 ? this.interval : this.remaining - 1, 1000) },
                            destroy() { clearInterval(this.timer) },
                        }"
                        class="w-14 text-right text-xs tabular-nums text-emerald-700"
                        x-text="remaining + ' dtk'"
                    >{{ $refreshInterval }} dtk</span>
                @endif
            </div>
            <button
                type="button"
                wire:click="refresh"
                wire:loading.attr="disabled"
                wire:target="refresh"
                class="inline-flex items-center justify-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-60"
            >
                <svg class="size-4" wire:loading.class="animate-spin" wire:target="refresh" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                </svg>
                Refresh
            </button>
        </div>
    </div>

    {{-- Table --}}
    <div class="relative overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
        <div wire:loading.delay class="absolute inset-x-0 top-0 h-0.5 animate-pulse bg-indigo-500"></div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50">
                    <tr>
                        @foreach ($columns as $name => $column)
                            <th
                                scope="col"
                                wire:key="th-{{ $name }}"
                                @class([
                                    'whitespace-nowrap px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600',
                                    'text-right' => $column['numeric'] ?? false,
                                ])
                            >
                                <button type="button" wire:click="sortBy('{{ $name }}')" class="inline-flex items-center gap-1 uppercase hover:text-indigo-600">
                                    {{ $column['label'] }}
                                    @if ($sortField === $name)
                                        <span class="text-indigo-600 dark:text-indigo-400">{{ $sortDirection === 'asc' ? '▲' : '▼' }}</span>
                                    @endif
                                </button>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($this->stockParts as $row)
                        <tr wire:key="stock-{{ $row['id'] }}" class="hover:bg-indigo-50/50">
                            @foreach ($columns as $name => $column)
                                <td
                                    wire:key="stock-{{ $row['id'] }}-{{ $name }}"
                                    @class([
                                        'whitespace-nowrap px-3 py-2',
                                        'text-right tabular-nums' => $column['numeric'] ?? false,
                                        'font-semibold text-violet-600' => $name === 'stock' && $row['status'] === 'over',
                                        'font-semibold text-rose-600' => $name === 'stock' && $row['status'] === 'below_min',
                                        'font-medium text-slate-900' => $name === 'part_no',
                                    ])
                                >
                                    @if ($name === 'status')
                                        @switch($row['status'])
                                            @case('over')
                                                <span class="rounded-full bg-violet-100 px-2 py-0.5 text-xs font-semibold text-violet-700">Over</span>
                                                @break
                                            @case('below_min')
                                                <span class="rounded-full bg-rose-100 px-2 py-0.5 text-xs font-semibold text-rose-700">&lt; Min</span>
                                                @break
                                            @default
                                                <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-700">Aman</span>
                                        @endswitch
                                    @else
                                        {{ ($column['numeric'] ?? false) && $name !== 'id' && $row[$name] !== null ? number_format($row[$name]) : ($row[$name] ?? '') }}
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ count($columns) }}" class="px-6 py-12 text-center text-sm text-slate-500">
                                Tidak ada data stock part.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="border-t border-slate-200 px-4 py-3">
            @if ($this->stockParts->hasPages())
                {{ $this->stockParts->links() }}
            @else
                <span class="text-xs text-slate-500">Total {{ number_format($this->stockParts->total()) }} data</span>
            @endif
        </div>
    </div>
</div>
