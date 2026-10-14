<div class="space-y-4">
    @if (session('status'))
        <div x-data="{ show: true }" x-init="setTimeout(() => show = false, 6000)" x-show="show" x-transition class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('status') }}
        </div>
    @endif

    {{-- Info & toolbar --}}
    <div class="flex flex-col gap-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200 md:flex-row md:items-center md:justify-between">
        <div class="space-y-1 text-sm">
            <p class="text-slate-700">
                Stock &amp; stock prod setiap part pada mesin aktif dicatat otomatis
                <span class="font-semibold text-slate-900">setiap {{ $slotHours }} jam</span> (07:00, 09:00, … 05:00 WIB; hari produksi mulai 07:00).
            </p>
            <a href="{{ route('machine-stock.index') }}" wire:navigate class="text-xs font-semibold text-indigo-600 hover:underline dark:text-indigo-400">Lihat grafik di menu Kontrol Stok Mesin →</a>
        </div>

        @can('machines.edit')
        <button type="button" wire:click="create" class="inline-flex items-center justify-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">
            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
            </svg>
            Tambah Mesin
        </button>
        @endcan
    </div>

    {{-- Table --}}
    <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50">
                    <tr>
                        @foreach (['Line / Mesin', 'Nama', 'Min / Max Part', 'Part', 'Pengambilan Terakhir', 'Jumlah Data', 'Status', 'Aksi'] as $header)
                            <th @class(['whitespace-nowrap px-4 py-3 text-xs font-semibold uppercase tracking-wide text-slate-600', 'text-right' => in_array($header, ['Part', 'Jumlah Data', 'Aksi'], true), 'text-left' => ! in_array($header, ['Part', 'Jumlah Data', 'Aksi'], true)])>{{ $header }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($this->machines as $machine)
                        <tr wire:key="machine-{{ $machine->id }}" @class(['hover:bg-indigo-50/50', 'opacity-60' => ! $machine->is_active])>
                            <td class="whitespace-nowrap px-4 py-3 font-semibold text-slate-900">
                                <a href="{{ route('machine-stock.index', ['machine' => $machine->id]) }}" wire:navigate class="hover:text-indigo-600 hover:underline">{{ $machine->line }}</a>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-slate-700">{{ $machine->name ?? '-' }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-slate-700">
                                @php
                                    $controlled = $this->controlledParts[$machine->id] ?? 0;
                                    $partCount = $machine->latestSnapshot?->item_count;
                                @endphp
                                <button type="button" wire:click="edit({{ $machine->id }})" @disabled(! auth()->user()->can('machines.edit')) @class(['font-semibold hover:underline disabled:no-underline', 'text-amber-600' => $partCount !== null && $controlled < $partCount, 'text-slate-900' => $partCount === null || $controlled >= $partCount])>
                                    {{ $controlled }}{{ $partCount !== null ? ' / '.$partCount : '' }} part diatur
                                </button>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-slate-700">{{ $machine->latestSnapshot?->item_count ?? '-' }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-slate-700">
                                {{ $machine->latestSnapshot ? \Carbon\CarbonImmutable::parse($machine->latestSnapshot->local_date)->format('d M Y').' · '.$machine->latestSnapshot->local_time.' WIB' : 'Belum ada data' }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-slate-700">{{ number_format($machine->snapshots_count) }}</td>
                            <td class="whitespace-nowrap px-4 py-3">
                                <button
                                    type="button"
                                    wire:click="toggleActive({{ $machine->id }})" @disabled(! auth()->user()->can('machines.edit'))
                                    title="Klik untuk {{ $machine->is_active ? 'menghentikan' : 'memulai' }} pencatatan"
                                    @class([
                                        'rounded-full px-2.5 py-0.5 text-xs font-semibold',
                                        'bg-emerald-100 text-emerald-700' => $machine->is_active,
                                        'bg-slate-200 text-slate-600' => ! $machine->is_active,
                                    ])
                                >{{ $machine->is_active ? 'Aktif' : 'Nonaktif' }}</button>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">
                                @can('machines.edit')
                                <button type="button" wire:click="edit({{ $machine->id }})" class="rounded-md px-2 py-1 text-xs font-semibold text-indigo-600 hover:bg-indigo-100 dark:text-indigo-400">Edit / Min Max</button>
                                @endcan
                                @can('machines.delete')
                                <button type="button" wire:click="confirmDelete({{ $machine->id }})" class="rounded-md px-2 py-1 text-xs font-semibold text-rose-600 hover:bg-rose-100">Hapus</button>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-6 py-12 text-center text-sm text-slate-500">Belum ada mesin yang dimonitor. Klik Tambah Mesin.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Create / edit --}}
    @if ($showForm)
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/60 p-4 sm:p-8" wire:keydown.escape.window="closeForm">
            <form wire:submit="save" class="w-full max-w-4xl rounded-xl bg-white shadow-xl">
                <div class="border-b border-slate-200 px-6 py-4">
                    <h2 class="text-lg font-semibold text-slate-900">{{ $editingId ? 'Edit Mesin' : 'Tambah Mesin' }}</h2>
                </div>
                <div class="space-y-5 px-6 py-5">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label for="machine-line" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-600">Line / Mesin</label>
                            <input id="machine-line" type="text" list="machine-line-options" wire:model.live.blur="line" maxlength="50" placeholder="contoh: SW 5.3" autocomplete="off" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20">
                            <datalist id="machine-line-options">
                                @foreach ($this->availableLines as $availableLine)
                                    <option value="{{ $availableLine }}"></option>
                                @endforeach
                            </datalist>
                            <p class="mt-1 text-xs text-slate-500">Sesuai kolom Line di Stock Part All. Part-nya muncul di bawah setelah line dipilih.</p>
                            @error('line') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="machine-name" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-600">Nama (opsional)</label>
                            <input id="machine-name" type="text" wire:model="name" maxlength="100" placeholder="contoh: Spot Welding 5.3" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20">
                            @error('name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            <label class="mt-3 flex items-center gap-2 text-sm text-slate-700">
                                <input type="checkbox" wire:model="isActive" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                Aktif (dicatat setiap {{ $slotHours }} jam)
                            </label>
                        </div>
                    </div>

                    {{-- Min / max per part --}}
                    <div x-data="{ filter: '' }" class="rounded-lg ring-1 ring-slate-200">
                        <div class="flex flex-col gap-3 border-b border-slate-200 bg-slate-50 px-4 py-3 lg:flex-row lg:items-end lg:justify-between">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-slate-600">Min / Max per part (hari)</p>
                                <p class="text-xs text-slate-500">Batas pcs = hari × PO/day. Perubahan berlaku mulai disimpan sampai diubah lagi.</p>
                            </div>
                            @if ($parts !== [])
                                <div class="flex flex-wrap items-end gap-2">
                                    <input type="search" x-model="filter" placeholder="Cari part..." aria-label="Cari part" class="w-36 rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                                    <label class="text-xs text-slate-500">Min
                                        <input type="number" step="any" min="0" inputmode="decimal" wire:model="bulkMinDays" class="block w-20 rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                                    </label>
                                    <label class="text-xs text-slate-500">Max
                                        <input type="number" step="any" min="0" inputmode="decimal" wire:model="bulkMaxDays" class="block w-20 rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                                    </label>
                                    <button type="button" wire:click="applyToAll" class="rounded-lg border border-indigo-300 px-3 py-1.5 text-sm font-semibold text-indigo-600 hover:bg-indigo-50 dark:text-indigo-400">Isi semua</button>
                                </div>
                            @endif
                        </div>
                        @error('bulkMaxDays') <p class="px-4 pt-2 text-xs text-rose-600">{{ $message }}</p> @enderror

                        @if ($parts === [])
                            <p class="px-4 py-8 text-center text-sm text-slate-500">
                                {{ $line === '' ? 'Pilih line dulu untuk menampilkan part-nya.' : 'Tidak ada part untuk line ini di Stock Part All.' }}
                            </p>
                        @else
                            <div class="max-h-[50vh] overflow-y-auto">
                                <table class="min-w-full divide-y divide-slate-200 text-sm">
                                    <thead class="sticky top-0 bg-white">
                                        <tr>
                                            @foreach (['Store', 'Part No', 'Rack', 'PO/day', 'Min (hari)', 'Max (hari)', 'Min / Max (pcs)'] as $header)
                                                <th class="whitespace-nowrap px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">{{ $header }}</th>
                                            @endforeach
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        @foreach ($parts as $index => $part)
                                            <tr
                                                wire:key="part-setting-{{ $part['store'] }}-{{ $part['part_no'] }}"
                                                x-show="filter === '' || @js(mb_strtolower($part['part_no'].' '.$part['store'].' '.$part['rack_no'])).includes(filter.toLowerCase())"
                                            >
                                                <td class="whitespace-nowrap px-3 py-1.5 text-slate-700">{{ $part['store'] }}</td>
                                                <td class="whitespace-nowrap px-3 py-1.5 font-medium text-slate-900">
                                                    {{ $part['part_no'] }}
                                                    @if (! $part['in_stock_data'])
                                                        <span class="ml-1 rounded bg-amber-100 px-1.5 text-[10px] font-semibold text-amber-700" title="Part ini sudah tidak ada di Stock Part All untuk line ini">tidak ada di data</span>
                                                    @endif
                                                    @if ($part['since'])
                                                        <span class="block text-[11px] font-normal text-slate-400">sejak {{ $part['since'] }}</span>
                                                    @else
                                                        <span class="block text-[11px] font-normal text-amber-600">belum diatur</span>
                                                    @endif
                                                </td>
                                                <td class="whitespace-nowrap px-3 py-1.5 text-slate-700">{{ $part['rack_no'] }}</td>
                                                <td class="whitespace-nowrap px-3 py-1.5 text-right tabular-nums text-slate-700">{{ $part['po_day'] !== null ? number_format($part['po_day']) : '-' }}</td>
                                                <td class="px-3 py-1.5">
                                                    <input type="number" step="any" min="0" inputmode="decimal" wire:model.live.debounce.400ms="parts.{{ $index }}.min_days" aria-label="Min hari {{ $part['part_no'] }}" class="w-20 rounded-md border border-slate-300 px-2 py-1 text-sm">
                                                    @error("parts.$index.min_days") <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                                                </td>
                                                <td class="px-3 py-1.5">
                                                    <input type="number" step="any" min="0" inputmode="decimal" wire:model.live.debounce.400ms="parts.{{ $index }}.max_days" aria-label="Max hari {{ $part['part_no'] }}" class="w-20 rounded-md border border-slate-300 px-2 py-1 text-sm">
                                                    @error("parts.$index.max_days") <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                                                </td>
                                                <td class="whitespace-nowrap px-3 py-1.5 tabular-nums text-slate-500">
                                                    @if ($part['po_day'] !== null && is_numeric($part['min_days']) && is_numeric($part['max_days']))
                                                        {{ number_format(ceil((float) $part['min_days'] * $part['po_day'])) }} / {{ number_format(ceil((float) $part['max_days'] * $part['po_day'])) }}
                                                    @else
                                                        <span title="PO/day kosong di Partlist">-</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>
                <div class="flex justify-end gap-3 rounded-b-xl border-t border-slate-200 bg-slate-50 px-6 py-4">
                    <button type="button" wire:click="closeForm" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">Batal</button>
                    <button type="submit" wire:loading.attr="disabled" wire:target="save" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-60">
                        <span wire:loading.remove wire:target="save">Simpan</span>
                        <span wire:loading wire:target="save">Menyimpan...</span>
                    </button>
                </div>
            </form>
        </div>
    @endif

    {{-- Delete confirmation --}}
    @if ($deletingId)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" wire:keydown.escape.window="$set('deletingId', null)">
            <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
                <h2 class="text-lg font-semibold text-slate-900">Hapus mesin?</h2>
                <p class="mt-2 text-sm text-slate-600">Semua history stok mesin ini ikut terhapus. Untuk berhenti mencatat tanpa menghapus history, ubah status menjadi Nonaktif.</p>
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" wire:click="$set('deletingId', null)" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">Batal</button>
                    <button type="button" wire:click="delete" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-500">Hapus</button>
                </div>
            </div>
        </div>
    @endif
</div>
