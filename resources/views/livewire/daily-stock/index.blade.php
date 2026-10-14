@php
    $latest = $this->latest;
    $summary = $this->summary;
    $totals = [
        'parts' => array_sum(array_column($summary, 'parts')),
        'under_one' => array_sum(array_column($summary, 'under_one')),
        'not_ok' => array_sum(array_column($summary, 'not_ok')),
        'ok' => array_sum(array_column($summary, 'ok')),
        'over' => array_sum(array_column($summary, 'over')),
    ];
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
        <div class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4 sm:gap-6">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Jadwal pembuatan</p>
                <p class="font-semibold text-slate-900">{{ $scheduleLabel }} WIB</p>
            </div>
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Data terakhir</p>
                <p class="font-semibold text-slate-900">
                    @if ($latest)
                        {{ \Carbon\CarbonImmutable::parse($latest->stock_date)->format('d M Y') }} · {{ $latest->local_time }} WIB
                    @else
                        Belum ada data
                    @endif
                </p>
            </div>
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Berikutnya</p>
                <p class="font-semibold text-slate-900">{{ $this->nextRun ? $this->nextRun->format('d M Y · H:i').' WIB' : '-' }}</p>
            </div>
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Dikirim ke</p>
                <a href="{{ route('recipients.index') }}" wire:navigate class="font-semibold text-indigo-600 hover:underline dark:text-indigo-400">{{ $this->activeRecipients }} user aktif</a>
            </div>
        </div>

        <div class="flex flex-wrap gap-2">
            @can('daily_stock.edit')
            <button type="button" wire:click="$toggle('showSettings')" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                Atur Jadwal
            </button>
            @endcan
            @can('daily_stock.edit')
            <button type="button" wire:click="recordNow" wire:loading.attr="disabled" wire:target="recordNow" class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-60">
                <svg class="size-4" wire:loading.class="animate-spin" wire:target="recordNow" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                </svg>
                Buat Sekarang
            </button>
            @endcan
        </div>
    </div>

    {{-- Schedule settings --}}
    @if ($showSettings)
        <form wire:submit="saveSchedule" class="space-y-4 rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
            <p class="text-sm font-semibold text-slate-900">Parameter jadwal daily stock</p>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <label class="space-y-1">
                    <span class="block text-xs font-semibold uppercase tracking-wide text-slate-500">Jam (WIB)</span>
                    <input type="time" wire:model="time" class="w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm">
                    @error('time') <span class="block text-xs text-rose-600">{{ $message }}</span> @enderror
                </label>

                <div class="space-y-1 md:col-span-2">
                    <span class="block text-xs font-semibold uppercase tracking-wide text-slate-500">Hari aktif</span>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($dayOptions as $value => $label)
                            <label wire:key="day-{{ $value }}" @class(['flex cursor-pointer items-center gap-2 rounded-lg px-3 py-1.5 text-sm ring-1', 'bg-indigo-50 ring-indigo-400' => in_array($value, $days), 'ring-slate-200' => ! in_array($value, $days)])>
                                <input type="checkbox" wire:model.live="days" value="{{ $value }}" class="rounded text-indigo-600 focus:ring-indigo-500">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                    @error('days.*') <span class="block text-xs text-rose-600">{{ $message }}</span> @enderror
                </div>

                <label class="space-y-1">
                    <span class="block text-xs font-semibold uppercase tracking-wide text-slate-500">Hari kerja / bulan</span>
                    <input type="number" min="1" max="31" wire:model="workingDays" class="w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm">
                    <span class="block text-xs text-slate-500">Dipakai untuk kolom ORDER (DEL/DAY × hari kerja).</span>
                    @error('workingDays') <span class="block text-xs text-rose-600">{{ $message }}</span> @enderror
                </label>
            </div>

            <p class="text-xs text-slate-500">Setiap hari aktif pada jam tersebut, stock FINISH GOODS diambil dari SOS lalu file Excel hari itu dibuat dan dikirim lewat email ke semua user aktif (menu User). Jika scheduler sempat mati, data diambil saat scheduler kembali jalan di hari yang sama.</p>

            <div class="flex justify-end gap-2">
                <button type="button" wire:click="$set('showSettings', false)" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">Batal</button>
                <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">Simpan Jadwal</button>
            </div>
        </form>
    @endif

    {{-- Coverage summary of the latest day --}}
    @if ($latest)
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            @foreach ([
                ['Part dimonitor', $totals['parts'], 'text-slate-900'],
                ['Stock < 1.0 hari', $totals['under_one'], 'text-rose-600'],
                ['Stock < 1.5 hari', $totals['not_ok'], 'text-amber-600'],
                ['OVER (> 5 hari)', $totals['over'], 'text-sky-600'],
            ] as [$label, $value, $color])
                <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $label }}</p>
                    <p @class(['mt-1 text-2xl font-semibold tabular-nums', $color])>{{ number_format($value) }}</p>
                </div>
            @endforeach
        </div>

        {{-- Resume like the Excel report: cumulative, a part below 1.0 day is also counted below 1.5 days --}}
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            @foreach ([['STOCK < 1.5 DAY', 'not_ok'], ['STOCK < 1.0 DAY', 'under_one']] as [$resumeTitle, $resumeKey])
                <section wire:key="resume-{{ $resumeKey }}" class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
                    <h2 class="px-4 pb-2 pt-3 text-base font-bold text-slate-900">{{ $resumeTitle }}</h2>
                    <table class="w-full border-collapse text-sm">
                        <thead>
                            <tr>
                                <th colspan="2" class="border border-slate-300 bg-sky-100 py-1.5"></th>
                                <th class="border border-slate-300 bg-amber-50 py-1.5 text-center font-bold text-slate-900">{{ \Carbon\CarbonImmutable::parse($latest->stock_date)->format('d-M') }}</th>
                            </tr>
                            <tr class="text-center font-bold text-slate-900">
                                <th class="w-16 border border-slate-300 bg-sky-100 py-1.5">NO</th>
                                <th class="border border-slate-300 bg-sky-100 py-1.5">LAST PROCESS</th>
                                <th class="w-1/3 border border-slate-300 bg-amber-50 py-1.5">PART</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($summary as $process => $row)
                                <tr wire:key="resume-{{ $resumeKey }}-{{ $process }}" class="text-center text-slate-800">
                                    <td class="border border-slate-300 py-1.5 tabular-nums">{{ $loop->iteration }}</td>
                                    <td class="border border-slate-300 py-1.5">{{ $process }}</td>
                                    <td class="border border-slate-300 bg-amber-50 py-1.5 tabular-nums">{{ $row[$resumeKey] > 0 ? number_format($row[$resumeKey]) : '-' }}</td>
                                </tr>
                            @endforeach
                            <tr class="text-center font-bold text-slate-900">
                                <td class="border border-slate-300 py-1.5"></td>
                                <td class="border border-slate-300 py-1.5">TOTAL:</td>
                                <td class="border border-slate-300 bg-amber-50 py-1.5 tabular-nums">{{ $totals[$resumeKey] > 0 ? number_format($totals[$resumeKey]) : '-' }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="px-4 py-2 text-xs text-slate-500">Hanya part FINISH GOODS dengan PO/DAY dan QTY/BOX terisi.</p>
                </section>
            @endforeach
        </div>
    @endif

    {{-- Monthly resume charts, the same data as the resume sheets of the Excel report --}}
    @php
        $resumeCharts = $this->resumeCharts;
        $recordedCount = count($this->resume['recorded_days']);
        $resumeMonthLabel = \Carbon\CarbonImmutable::parse($resumeMonth.'-01')->translatedFormat('F Y');
    @endphp
    <section class="space-y-4 rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <h2 class="text-sm font-semibold text-slate-900">Resume bulanan Stock FG</h2>
                <p class="text-xs text-slate-500">{{ $resumeMonthLabel }} · {{ $recordedCount }} hari tercatat · sama dengan sheet Resume di file Excel</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <div class="inline-flex rounded-lg bg-slate-100 p-1 text-sm font-medium" role="group" aria-label="Jenis resume">
                    @foreach ($resumeOptions as $value => $label)
                        <button
                            type="button"
                            wire:click="$set('resumeView', '{{ $value }}')"
                            @class([
                                'rounded-md px-3 py-1.5 transition',
                                'bg-white text-slate-900 shadow-sm' => $resumeView === $value,
                                'text-slate-500 hover:text-slate-700' => $resumeView !== $value,
                            ])
                        >{{ $label }}</button>
                    @endforeach
                </div>
                <button type="button" wire:click="shiftResumeMonth(-1)" aria-label="Bulan sebelumnya" class="rounded-lg border border-slate-300 p-2 text-slate-600 hover:bg-slate-100">
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
                    </svg>
                </button>
                <input type="month" wire:model.live="resumeMonth" aria-label="Bulan resume" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm">
                <button type="button" wire:click="shiftResumeMonth(1)" aria-label="Bulan berikutnya" class="rounded-lg border border-slate-300 p-2 text-slate-600 hover:bg-slate-100">
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                    </svg>
                </button>
            </div>
        </div>

        @if ($recordedCount === 0)
            <p class="rounded-lg bg-slate-50 px-4 py-10 text-center text-sm text-slate-500">Belum ada data Daily Stock di {{ $resumeMonthLabel }}.</p>
        @else
            <div class="space-y-4" wire:key="resume-charts-{{ md5(json_encode($resumeCharts)) }}" x-data="dailyStockResumeCharts(@js($resumeCharts))">
                @foreach ($resumeCharts as $chart)
                    @php
                        $lastRecorded = collect($chart['total'])->filter(fn ($value) => $value !== null)->keys()->last();
                    @endphp
                    <div wire:key="resume-chart-{{ $chart['threshold'] }}" class="rounded-lg ring-1 ring-slate-200">
                        <div class="flex flex-wrap items-end justify-between gap-2 px-4 pt-3">
                            <h3 class="text-sm font-semibold text-slate-900">{{ $chart['title'] }}</h3>
                            @if ($lastRecorded !== null)
                                <p class="text-xs text-slate-500">Tgl {{ $lastRecorded + 1 }}: <span class="text-base font-semibold tabular-nums text-slate-900">{{ number_format($chart['total'][$lastRecorded]) }}</span> {{ strtolower($chart['unit']) }}</p>
                            @endif
                        </div>

                        <div class="flex flex-wrap gap-x-4 gap-y-1 px-4 pt-2 text-xs text-slate-600">
                            @foreach ($chart['series'] as $series)
                                <span wire:key="legend-{{ $chart['threshold'] }}-{{ $loop->index }}" class="flex items-center gap-1.5"><span data-swatch="{{ $series['slot'] ?? 'other' }}" class="size-2.5 rounded-sm"></span>{{ $series['label'] }}</span>
                            @endforeach
                            <span class="flex items-center gap-1.5"><span data-swatch="total" class="h-0.5 w-4 rounded"></span>TOTAL</span>
                        </div>

                        <div class="relative h-80 px-2 pb-2">
                            <canvas data-threshold="{{ $chart['threshold'] }}" role="img" aria-label="{{ $chart['title'] }}: batang bertumpuk per {{ $resumeView === 'pg_item' ? 'mesin' : 'last process' }} dan garis total per tanggal"></canvas>
                        </div>

                        <div x-data="{ open: false }" class="border-t border-slate-200">
                            <button type="button" @click="open = ! open" class="flex w-full items-center justify-between px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                Tabel data
                                <svg class="size-4 transition-transform" :class="open && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                                </svg>
                            </button>
                            <div x-show="open" x-cloak class="overflow-x-auto">
                                <table class="min-w-full text-xs">
                                    <thead class="bg-slate-50">
                                        <tr>
                                            <th class="sticky left-0 bg-slate-50 px-3 py-1.5 text-left font-semibold text-slate-600">{{ $resumeView === 'pg_item' ? 'FIX MACHINE' : 'LAST PROCESS' }}</th>
                                            @foreach ($chart['labels'] as $day)
                                                <th class="px-2 py-1.5 text-right font-semibold text-slate-600">{{ $day }}</th>
                                            @endforeach
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        @foreach ([...$chart['rows'], ['label' => 'TOTAL', 'data' => $chart['total']]] as $row)
                                            <tr wire:key="resume-row-{{ $chart['threshold'] }}-{{ $loop->index }}" @class(['font-semibold text-slate-900' => $loop->last, 'text-slate-700' => ! $loop->last])>
                                                <td class="sticky left-0 whitespace-nowrap bg-white px-3 py-1.5">{{ $row['label'] }}</td>
                                                @foreach ($row['data'] as $value)
                                                    <td class="px-2 py-1.5 text-right tabular-nums">{{ $value === null ? '' : ($value === 0 ? '-' : number_format($value)) }}</td>
                                                @endforeach
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
        {{-- Generated Excel files --}}
        <section class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
            <div class="border-b border-slate-200 px-4 py-3">
                <h2 class="text-sm font-semibold text-slate-900">File Excel per hari</h2>
                <p class="text-xs text-slate-500">Satu file per tanggal, hanya berisi stock &amp; coverage hari itu.</p>
            </div>
            @forelse ($this->reports as $report)
                <div wire:key="report-{{ $report['date'] }}" class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-4 py-3 last:border-b-0">
                    <div>
                        <p class="text-sm font-semibold text-slate-900">{{ $report['label'] }}</p>
                        <p class="text-xs text-slate-500">Diperbarui {{ $report['updated_at']->format('d M Y · H:i') }} WIB · {{ number_format($report['size'] / 1024) }} KB</p>
                    </div>
                    <div class="flex gap-2">
                        @can('daily_stock.edit')
                        <button type="button" wire:click="regenerate('{{ $report['date'] }}')" wire:loading.attr="disabled" wire:target="regenerate('{{ $report['date'] }}')" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-100 disabled:opacity-60" title="Buat ulang dengan partlist & hari kerja terbaru">
                            Buat Ulang
                        </button>
                        @endcan
                        @can('daily_stock.edit')
                        <button type="button" wire:click="sendEmail('{{ $report['date'] }}')" wire:loading.attr="disabled" wire:target="sendEmail('{{ $report['date'] }}')" class="rounded-lg border border-indigo-300 px-3 py-1.5 text-sm font-medium text-indigo-700 hover:bg-indigo-50 disabled:opacity-60" title="Kirim file ini ke semua user aktif">
                            <span wire:loading.remove wire:target="sendEmail('{{ $report['date'] }}')">Kirim Email</span>
                            <span wire:loading wire:target="sendEmail('{{ $report['date'] }}')">Mengirim...</span>
                        </button>
                        @endcan
                        <button type="button" wire:click="download('{{ $report['date'] }}')" class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-emerald-500">
                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                            </svg>
                            Download
                        </button>
                    </div>
                </div>
            @empty
                <p class="px-4 py-10 text-center text-sm text-slate-500">Belum ada file. Klik <span class="font-semibold">Buat Sekarang</span> untuk membuat file hari ini.</p>
            @endforelse
        </section>

        {{-- Recorded days --}}
        <section class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
            <div class="border-b border-slate-200 px-4 py-3">
                <h2 class="text-sm font-semibold text-slate-900">Riwayat pengambilan</h2>
                <p class="text-xs text-slate-500">10 hari terakhir. Mengambil ulang di hari yang sama akan menimpa data hari itu.</p>
            </div>
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <tbody class="divide-y divide-slate-100">
                    @forelse ($this->recent as $recorded)
                        <tr wire:key="recent-{{ $recorded->id }}">
                            <td class="whitespace-nowrap px-4 py-2 font-medium text-slate-900">{{ \Carbon\CarbonImmutable::parse($recorded->stock_date)->translatedFormat('D, d M Y') }}</td>
                            <td class="whitespace-nowrap px-4 py-2 tabular-nums text-slate-700">{{ $recorded->local_time }} WIB</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums text-slate-700">{{ number_format($recorded->item_count) }} part</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right text-xs">
                                @if ($recorded->emailed_at)
                                    <span class="text-emerald-700" title="Terakhir dikirim {{ $recorded->emailed_at->timezone(config('services.sos.history_timezone'))->format('d M Y H:i') }} WIB">✉ {{ $recorded->emailed_count }} user · {{ $recorded->emailed_at->timezone(config('services.sos.history_timezone'))->format('H:i') }}</span>
                                @else
                                    <span class="text-slate-400">Belum dikirim</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-2 text-right">
                                <span @class(['rounded-full px-2 py-0.5 text-xs font-medium', 'bg-indigo-50 text-indigo-700' => $recorded->source === 'schedule', 'bg-slate-100 text-slate-600' => $recorded->source !== 'schedule'])>
                                    {{ $recorded->source === 'schedule' ? 'Jadwal' : 'Manual' }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-4 py-10 text-center text-sm text-slate-500">Belum ada data.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </section>
    </div>
</div>
