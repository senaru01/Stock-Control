@php
    $history = $this->history;
    $hasData = count($history['labels']) > 0;
    $lastIndex = count($history['total']) - 1;
    $periodName = match ($granularity) {
        'snapshot' => 'pengambilan',
        'week' => 'hari',
        'day' => 'tanggal',
        'month' => 'bulan',
        default => 'tahun',
    };
@endphp

<div class="space-y-4">
    @if (session('status'))
        <div x-data="{ show: true }" x-init="setTimeout(() => show = false, 4000)" x-show="show" x-transition class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('status') }}
        </div>
    @endif
    @if (session('error'))
        <div class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ session('error') }}</div>
    @endif

    {{-- Schedule status --}}
    <div class="flex flex-col gap-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200 lg:flex-row lg:items-center lg:justify-between">
        <div class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-3 sm:gap-6">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Jadwal pengambilan</p>
                <p class="font-semibold text-slate-900">{{ $scheduleLabel }}</p>
            </div>
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Pengambilan terakhir</p>
                <p class="font-semibold text-slate-900">
                    @if ($this->latest)
                        {{ \Carbon\CarbonImmutable::parse($this->latest->local_date)->format('d M Y') }} · {{ $this->latest->local_time }} WIB
                    @else
                        Belum ada data
                    @endif
                </p>
            </div>
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Berikutnya</p>
                <p class="font-semibold text-slate-900">{{ $this->nextRun->format('d M Y · H:i') }} WIB</p>
            </div>
        </div>

        <div class="flex flex-wrap gap-2">
            @can('stock_history.edit')
            <button type="button" wire:click="$toggle('showSettings')" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                Atur Jadwal
            </button>
            @endcan
            @can('stock_history.edit')
            <button type="button" wire:click="takeSnapshotNow" wire:loading.attr="disabled" wire:target="takeSnapshotNow" class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-60">
                <svg class="size-4" wire:loading.class="animate-spin" wire:target="takeSnapshotNow" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                </svg>
                Ambil Data Sekarang
            </button>
            @endcan
        </div>
    </div>

    @if ($this->schedulerLooksIdle)
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            Belum ada pengambilan otomatis sejak {{ $this->latest->local_time }} WIB. Pastikan scheduler berjalan:
            <code class="rounded bg-amber-100 px-1">composer run dev</code> atau <code class="rounded bg-amber-100 px-1">php artisan schedule:work</code>.
        </div>
    @endif

    {{-- Schedule settings --}}
    @if ($showSettings)
        <form wire:submit="saveSchedule" class="space-y-4 rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
            <p class="text-sm font-semibold text-slate-900">Parameter jadwal history</p>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <label @class(['flex cursor-pointer gap-3 rounded-lg p-3 ring-1', 'bg-indigo-50 ring-indigo-400' => $scheduleMode === 'interval', 'ring-slate-200' => $scheduleMode !== 'interval'])>
                    <input type="radio" wire:model.live="scheduleMode" value="interval" class="mt-1 text-indigo-600 focus:ring-indigo-500">
                    <span class="flex-1 space-y-2">
                        <span class="block text-sm font-semibold text-slate-900">Interval</span>
                        <span class="block text-xs text-slate-500">Ambil data berulang, cocok untuk uji coba / demo.</span>
                        <select wire:model="intervalMinutes" class="rounded-md border border-slate-300 py-1.5 pl-2 pr-8 text-sm" @disabled($scheduleMode !== 'interval')>
                            @foreach ($intervalOptions as $minutes)
                                <option value="{{ $minutes }}">Setiap {{ $minutes }} menit</option>
                            @endforeach
                        </select>
                    </span>
                </label>

                <label @class(['flex cursor-pointer gap-3 rounded-lg p-3 ring-1', 'bg-indigo-50 ring-indigo-400' => $scheduleMode === 'times', 'ring-slate-200' => $scheduleMode !== 'times'])>
                    <input type="radio" wire:model.live="scheduleMode" value="times" class="mt-1 text-indigo-600 focus:ring-indigo-500">
                    <span class="flex-1 space-y-2">
                        <span class="block text-sm font-semibold text-slate-900">Jam tetap</span>
                        <span class="block text-xs text-slate-500">Ambil data di jam tertentu setiap hari (WIB), pisahkan dengan koma.</span>
                        <input type="text" wire:model="times" placeholder="07:00, 16:00" class="w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm" @disabled($scheduleMode !== 'times')>
                    </span>
                </label>
            </div>
            @error('times') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
            @error('intervalMinutes') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror

            <div class="flex justify-end gap-2">
                <button type="button" wire:click="$set('showSettings', false)" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">Batal</button>
                <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">Simpan Jadwal</button>
            </div>
        </form>
    @endif

    {{-- Filters --}}
    <div class="flex flex-col gap-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200 md:flex-row md:items-center">
        <div class="inline-flex rounded-lg bg-slate-100 p-1 text-sm font-medium" role="group" aria-label="Tampilan history">
            @foreach ($granularities as $value => $label)
                <button
                    type="button"
                    wire:click="$set('granularity', '{{ $value }}')"
                    @class([
                        'rounded-md px-3 py-1.5 transition',
                        'bg-white text-slate-900 shadow-sm' => $granularity === $value,
                        'text-slate-500 hover:text-slate-700' => $granularity !== $value,
                    ])
                >{{ $label }}</button>
            @endforeach
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($granularity === 'snapshot')
                <input type="date" wire:model.live="date" aria-label="Tanggal" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
            @elseif ($granularity === 'week')
                <button type="button" wire:click="previousWeek" aria-label="Minggu sebelumnya" class="rounded-lg border border-slate-300 p-2 text-slate-600 hover:bg-slate-100">
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
                    </svg>
                </button>
                <input type="date" wire:model.live="week" aria-label="Pilih tanggal dalam minggu" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <button type="button" wire:click="nextWeek" aria-label="Minggu berikutnya" class="rounded-lg border border-slate-300 p-2 text-slate-600 hover:bg-slate-100">
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                    </svg>
                </button>
                <span class="text-sm font-medium text-slate-700">Senin–Minggu · {{ $this->weekRange }}</span>
            @elseif ($granularity === 'day')
                <input type="month" wire:model.live="month" aria-label="Bulan" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
            @elseif ($granularity === 'month')
                <select wire:model.live="year" aria-label="Tahun" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    @foreach ($this->years ?: [$year] as $yearOption)
                        <option value="{{ $yearOption }}">{{ $yearOption }}</option>
                    @endforeach
                </select>
            @endif

            @if ($granularity !== 'snapshot')
                <select wire:model.live="aggregate" aria-label="Nilai per periode" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <option value="last">Nilai: pengambilan terakhir</option>
                    <option value="avg">Nilai: rata-rata</option>
                </select>
            @endif
        </div>
    </div>

    @if (! $hasData)
        <div class="rounded-xl bg-white px-6 py-16 text-center shadow-sm ring-1 ring-slate-200">
            <p class="text-sm font-semibold text-slate-900">Belum ada data history untuk periode ini.</p>
            <p class="mt-1 text-sm text-slate-500">Data akan terisi otomatis sesuai jadwal, atau klik <span class="font-semibold">Ambil Data Sekarang</span>.</p>
        </div>
    @else
        <div
            class="space-y-4"
            wire:key="charts-{{ md5(json_encode($history)) }}"
            x-data="stockHistoryCharts(@js($history))"
        >
            {{-- Total stock line --}}
            <section class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                <div class="mb-3 flex flex-wrap items-end justify-between gap-2">
                    <div>
                        <h2 class="text-sm font-semibold text-slate-900">Total stock keseluruhan (pcs)</h2>
                        <p class="text-xs text-slate-500">{{ count($history['labels']) }} {{ $periodName }} · {{ $history['labels'][0] }} – {{ $history['labels'][$lastIndex] }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-xs text-slate-500">Terakhir ({{ $history['labels'][$lastIndex] }})</p>
                        <p class="text-2xl font-semibold text-slate-900">{{ number_format($history['total'][$lastIndex]) }}</p>
                    </div>
                </div>
                <div class="relative h-72">
                    <canvas x-ref="total" role="img" aria-label="Grafik garis total stock keseluruhan"></canvas>
                </div>
            </section>

            {{-- Line chart per store --}}
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($history['stores'] as $store => $values)
                    @php($latestValue = collect($values)->filter(fn ($value) => $value !== null)->last())
                    <section wire:key="store-chart-{{ $store }}" class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                        <div class="mb-2 flex items-end justify-between gap-2">
                            <h3 class="truncate text-sm font-semibold text-slate-900">{{ $store }}</h3>
                            <p class="text-sm font-semibold tabular-nums text-slate-700">{{ $latestValue !== null ? number_format($latestValue) : '-' }} <span class="text-xs font-normal text-slate-500">pcs</span></p>
                        </div>
                        <div class="relative h-44">
                            <canvas data-store="{{ $store }}" role="img" aria-label="Grafik garis stock {{ $store }}"></canvas>
                        </div>
                    </section>
                @endforeach
            </div>
        </div>

        {{-- Table view --}}
        <section class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200" x-data="{ open: false }">
            <button type="button" @click="open = ! open" class="flex w-full items-center justify-between px-4 py-3 text-sm font-semibold text-slate-900 hover:bg-slate-50">
                Tabel data
                <svg class="size-4 transition-transform" :class="open && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                </svg>
            </button>
            <div x-show="open" x-cloak class="overflow-x-auto border-t border-slate-200">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="whitespace-nowrap px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">{{ ucfirst($periodName) }}</th>
                            @foreach (array_keys($history['stores']) as $store)
                                <th class="whitespace-nowrap px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide text-slate-600">{{ $store }}</th>
                            @endforeach
                            <th class="whitespace-nowrap px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide text-slate-900">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($history['labels'] as $index => $label)
                            <tr wire:key="history-row-{{ $index }}">
                                <td class="whitespace-nowrap px-3 py-2 font-medium text-slate-900">
                                    {{ $label }}
                                    @if ($granularity !== 'snapshot')
                                        <span class="text-xs text-slate-500">({{ $history['counts'][$index] }}×)</span>
                                    @endif
                                </td>
                                @foreach ($history['stores'] as $values)
                                    <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-slate-700">{{ $values[$index] !== null ? number_format($values[$index]) : '-' }}</td>
                                @endforeach
                                <td class="whitespace-nowrap px-3 py-2 text-right font-semibold tabular-nums text-slate-900">{{ number_format($history['total'][$index]) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</div>
