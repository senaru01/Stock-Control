<div
    class="space-y-4"
    wire:poll.60s
    x-data="{
        fullscreen: false,
        {{-- Browsers only allow full screen after a user gesture, so the first tap or key press on the page enters it. --}}
        enterFullscreenOnFirstInteraction() {
            const events = ['pointerdown', 'keydown'];
            const enter = (event) => {
                events.forEach(name => window.removeEventListener(name, enter));
                if (! document.fullscreenElement && ! event.target.closest?.('[data-fullscreen-toggle]')) {
                    this.$refs.andon.requestFullscreen().catch(() => {});
                }
            };
            events.forEach(name => window.addEventListener(name, enter));
        },
    }"
    x-init="enterFullscreenOnFirstInteraction()"
    x-on:fullscreenchange.window="fullscreen = !! document.fullscreenElement"
    x-ref="andon"
    x-bind:class="fullscreen && 'overflow-y-auto bg-slate-100 p-4'"
>
    @if ($this->andon['error'])
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            {{ $this->andon['error'] }}
            @if ($this->andon['fetched_at'])
                Menampilkan stock terakhir dari {{ $this->andon['fetched_at']->timezone(config('app.timezone'))->format('d M Y H:i:s') }}.
            @endif
        </div>
    @endif

    {{-- Header & filters --}}
    <div class="flex flex-col gap-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200 xl:flex-row xl:items-center xl:justify-between">
        <div class="flex items-center gap-4">
            <a href="{{ route('andon.index') }}" wire:navigate class="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" title="Kembali ke daftar line">
                <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
                </svg>
            </a>
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                    @if ($this->process)
                        <a href="{{ route('andon.index', ['search' => $this->process]) }}" wire:navigate class="text-indigo-600 dark:text-indigo-400 hover:underline">Process {{ $this->process }}</a> ·
                    @endif
                    Andon Line
                </p>
                <p class="text-2xl font-bold text-slate-900">{{ $line }}</p>
            </div>
            <div class="hidden flex-wrap gap-2 text-xs font-semibold sm:flex">
                <button type="button" wire:click="$set('status', 'production_point')" class="rounded-full bg-rose-100 px-3 py-1 text-rose-700 hover:ring-2 hover:ring-rose-300">
                    Prod Point: {{ $this->statusCounts['production_point'] ?? 0 }}
                </button>
                @if (($this->statusCounts['order_point'] ?? 0) > 0)
                    <button type="button" wire:click="$set('status', 'order_point')" class="rounded-full bg-blue-100 px-3 py-1 text-blue-700 hover:ring-2 hover:ring-blue-300">
                        Mat Order Point: {{ $this->statusCounts['order_point'] }}
                    </button>
                @endif
                <button type="button" wire:click="$set('status', 'normal')" class="rounded-full bg-emerald-100 px-3 py-1 text-emerald-700 hover:ring-2 hover:ring-emerald-300">
                    Normal: {{ $this->statusCounts['normal'] ?? 0 }}
                </button>
                <button type="button" wire:click="$set('status', 'overstock')" class="rounded-full bg-violet-100 px-3 py-1 text-violet-700 hover:ring-2 hover:ring-violet-300">
                    Overstock: {{ $this->statusCounts['overstock'] ?? 0 }}
                </button>
                @if (($this->statusCounts['not_set'] ?? 0) > 0)
                    <button type="button" wire:click="$set('status', 'not_set')" class="rounded-full bg-amber-100 px-3 py-1 text-amber-700 hover:ring-2 hover:ring-amber-300">
                        Belum diset: {{ $this->statusCounts['not_set'] }}
                    </button>
                @endif
                <span class="inline-flex items-center gap-1.5 px-1 text-slate-500"><span class="h-1 w-5 rounded bg-violet-600"></span>Stock Max</span>
                <span class="inline-flex items-center gap-1.5 px-1 text-slate-500"><span class="h-1 w-5 rounded bg-red-600"></span>Production Point</span>
                <span class="inline-flex items-center gap-1.5 px-1 text-slate-500"><span class="h-1 w-5 rounded bg-blue-600"></span>Mat Order Point</span>
            </div>
        </div>

        <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
            <input
                type="search"
                wire:model.live.debounce.300ms="search"
                placeholder="Cari part no..."
                class="rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20"
            >
            <select wire:model.live="level" class="rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20">
                <option value="">Semua Level</option>
                @foreach ($this->levels as $levelOption)
                    <option value="{{ $levelOption }}">{{ $levelOption }}</option>
                @endforeach
            </select>
            <select wire:model.live="status" class="rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20">
                <option value="">Semua Status</option>
                <option value="production_point">&le; Prod Point</option>
                <option value="order_point">&le; Mat Order Point</option>
                <option value="normal">Normal</option>
                <option value="overstock">Overstock</option>
                <option value="not_set">Belum diset</option>
            </select>
            <button
                type="button"
                data-fullscreen-toggle
                x-on:click="fullscreen ? document.exitFullscreen() : $refs.andon.requestFullscreen()"
                class="inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100"
            >
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3.75v4.5m0-4.5h4.5m-4.5 0L9 9M3.75 20.25v-4.5m0 4.5h4.5m-4.5 0L9 15M20.25 3.75h-4.5m4.5 0v4.5m0-4.5L15 9m5.25 11.25h-4.5m4.5 0v-4.5m0 4.5L15 15" />
                </svg>
                <span x-text="fullscreen ? 'Keluar Layar Penuh' : 'Layar Penuh'">Layar Penuh</span>
            </button>
        </div>
    </div>

    {{-- Andon cards --}}
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 2xl:grid-cols-8">
        @forelse ($this->cards as $card)
            <div
                wire:key="andon-{{ $card['code'] }}"
                @class([
                    'flex flex-col rounded-xl p-3 shadow-sm ring-1',
                    'bg-rose-500/15 dark:bg-rose-500/35' => $card['has_stock_data'] && $card['stock_kbn'] === 0,
                    'bg-white' => ! ($card['has_stock_data'] && $card['stock_kbn'] === 0),
                    'ring-rose-500 ring-2' => $card['status'] === 'production_point',
                    'ring-blue-500 ring-2' => $card['status'] === 'order_point',
                    'ring-violet-400 ring-2' => $card['status'] === 'overstock',
                    'ring-emerald-500 ring-2' => $card['status'] === 'normal',
                    'ring-slate-200' => $card['status'] === 'not_set',
                ])
            >
                <div class="mb-2">
                    <p class="truncate text-lg font-extrabold leading-tight text-slate-900" title="{{ $card['part_no'] }}">{{ $card['part_no'] }}</p>
                    <p class="truncate text-[11px] font-semibold uppercase text-slate-500">{{ $card['level'] }} · {{ $card['line'] }}</p>
                    <p class="mt-1 flex justify-between text-[11px] text-slate-600">
                        <span>Loading Time</span>
                        <span class="font-semibold tabular-nums text-slate-900">{{ $card['loading_time'] !== null ? number_format($card['loading_time']).' Menit' : '-' }}</span>
                    </p>
                </div>

                @if ($card['status'] === 'production_point')
                    <div class="mb-1 animate-pulse rounded-md bg-rose-600 py-1 text-center text-xs font-bold uppercase tracking-widest text-white">
                        Production Point
                    </div>
                @endif

                @if ($card['status'] === 'order_point')
                    <div class="mb-1 animate-pulse rounded-md bg-blue-600 py-1 text-center text-xs font-bold uppercase tracking-widest text-white">
                        Mat Order Point
                    </div>
                @endif

                @if ($card['status'] === 'normal')
                    <div class="mb-1 rounded-md bg-emerald-600 py-1 text-center text-xs font-bold uppercase tracking-widest text-white">
                        Normal
                    </div>
                @endif

                @if ($card['status'] === 'overstock')
                    <div class="mb-1 animate-pulse rounded-md bg-violet-600 py-1 text-center text-xs font-bold uppercase tracking-widest text-white">
                        Overstock
                    </div>
                @endif

                @if ($card['status'] === 'not_set')
                    <div class="mt-1 flex flex-1 items-center justify-center rounded-md border border-dashed border-amber-300 bg-amber-50 p-3 text-center text-xs text-amber-700">
                        {{ $card['qty_kbn'] <= 0 ? 'QTY_KBN' : 'STOCK_MAX' }} belum diset di partlist
                    </div>
                @else
                    <div class="relative mt-1 h-52">
                        <div class="flex h-full flex-col divide-y divide-slate-800 border border-slate-800">
                            @for ($row = $card['rows']; $row >= 1; $row--)
                                <div @class([
                                    'flex-1',
                                    'bg-rose-500' => $row <= $card['filled_rows'] && $card['status'] === 'production_point',
                                    'bg-blue-400' => $row <= $card['filled_rows'] && $card['status'] === 'order_point',
                                    'bg-emerald-500' => $row <= $card['filled_rows'] && $card['status'] === 'normal',
                                    'bg-violet-400' => $row <= $card['filled_rows'] && $card['status'] === 'overstock',
                                    'bg-white' => $row > $card['filled_rows'],
                                ])></div>
                            @endfor
                        </div>
                        @foreach ([
                            ['row' => $card['max_row'], 'color' => 'bg-violet-600', 'title' => 'Stock Max '.$card['max'].' KBN'],
                            ['row' => $card['order_point_row'], 'color' => 'bg-blue-600', 'title' => 'Mat Order Point '.$card['order_point'].' KBN'],
                            ['row' => $card['production_point_row'], 'color' => 'bg-red-600', 'title' => 'Production Point '.$card['production_point'].' KBN'],
                        ] as $marker)
                            @if ($marker['row'])
                                <div class="pointer-events-none absolute inset-x-0 h-1 translate-y-1/2 {{ $marker['color'] }}" style="bottom: {{ $marker['row'] * 100 / $card['rows'] }}%" title="{{ $marker['title'] }}"></div>
                            @endif
                        @endforeach
                    </div>
                @endif

                <div class="mt-2 space-y-0.5 text-[11px] text-slate-600">
                    <p class="flex justify-between">
                        <span>Actual Stok</span>
                        <span @class([
                            'font-bold tabular-nums',
                            'text-rose-600' => $card['status'] === 'production_point',
                            'text-blue-700' => $card['status'] === 'order_point',
                            'text-violet-700' => $card['status'] === 'overstock',
                            'text-slate-900' => in_array($card['status'], ['normal', 'not_set']),
                        ])>
                            {{ $card['stock_kbn'] !== null ? number_format($card['stock_kbn']).' KBN' : '-' }}
                        </span>
                    </p>
                    <p class="flex justify-between">
                        <span>Cov. Stock</span>
                        <span class="font-semibold tabular-nums text-slate-900">{{ $card['coverage_days'] !== null ? number_format($card['coverage_days'], 1, ',', '.').' Hari' : '-' }}</span>
                    </p>
                    @unless ($card['has_stock_data'])
                        <p class="pt-1 text-center text-amber-600">Tidak ada data di Stock Part All</p>
                    @endunless
                </div>
            </div>
        @empty
            <p class="col-span-full rounded-xl bg-white px-6 py-12 text-center text-sm text-slate-500 ring-1 ring-slate-200">
                Tidak ada part yang cocok dengan filter.
            </p>
        @endforelse
    </div>

    @if ($this->andon['fetched_at'])
        <p class="text-right text-xs text-slate-400">
            Stock update: {{ $this->andon['fetched_at']->timezone(config('app.timezone'))->format('d M Y H:i:s') }} · refresh otomatis tiap 60 detik
        </p>
    @endif
</div>
