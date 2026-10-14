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

    {{-- Toolbar --}}
    <div class="flex flex-col gap-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200 md:flex-row md:items-center md:justify-between">
        <div class="flex flex-1 flex-col gap-3 sm:flex-row">
            <div class="relative flex-1 sm:max-w-sm">
                <svg class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                </svg>
                <input
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Cari code, part no, part name, line..."
                    class="w-full rounded-lg border border-slate-300 py-2 pl-9 pr-3 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20"
                >
            </div>

            <select wire:model.live="level" class="rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20">
                <option value="">Semua Level</option>
                @foreach ($this->levels as $levelOption)
                    <option value="{{ $levelOption }}">{{ $levelOption }}</option>
                @endforeach
            </select>

            <select wire:model.live="perPage" class="rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20">
                @foreach ([15, 25, 50, 100] as $size)
                    <option value="{{ $size }}">{{ $size }} / halaman</option>
                @endforeach
            </select>

            <label class="inline-flex items-center gap-2 text-sm text-slate-600">
                <span class="whitespace-nowrap font-medium">Order point hari ke</span>
                <select wire:model.live="orderPointDays" aria-label="Parameter order point (hari)" class="rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20">
                    @foreach ($orderPointDayOptions as $dayOption)
                        <option value="{{ $dayOption }}">{{ $dayOption }}</option>
                    @endforeach
                </select>
            </label>
        </div>

        <div class="flex flex-col gap-2 sm:flex-row">
            @can('partlists.edit')
            <button
                type="button"
                wire:click="openLoadingSettings"
                class="inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-indigo-500/40"
            >
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 11-3 0m3 0a1.5 1.5 0 10-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-9.75 0h9.75" />
                </svg>
                Parameter Loading
            </button>
            @endcan

            @can('partlists.edit')
            <button
                type="button"
                wire:click="openImport"
                class="inline-flex items-center justify-center gap-2 rounded-lg border border-emerald-600 bg-white px-4 py-2 text-sm font-semibold text-emerald-700 shadow-sm hover:bg-emerald-50 focus:outline-none focus:ring-2 focus:ring-emerald-500/40"
            >
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
                </svg>
                Import Excel
            </button>
            @endcan

            @can('partlists.edit')
            <button
                type="button"
                wire:click="create"
                class="inline-flex items-center justify-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/40"
            >
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                Tambah Partlist
            </button>
            @endcan
        </div>
    </div>

    {{-- Table --}}
    <div class="relative overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
        <div wire:loading.delay class="absolute inset-x-0 top-0 h-0.5 animate-pulse bg-indigo-500"></div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50">
                    <tr>
                        @foreach ($fields as $name => $field)
                            <th
                                scope="col"
                                wire:key="th-{{ $name }}"
                                @class([
                                    'whitespace-nowrap px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600',
                                    'sticky left-0 z-10 bg-slate-50' => $name === 'code',
                                    'text-right' => $field['type'] === 'integer',
                                ])
                            >
                                <button type="button" wire:click="sortBy('{{ $name }}')" class="inline-flex items-center gap-1 uppercase hover:text-indigo-600">
                                    {{ str($name)->upper() }}
                                    @if ($sortField === $name)
                                        <span class="text-indigo-600 dark:text-indigo-400">{{ $sortDirection === 'asc' ? '▲' : '▼' }}</span>
                                    @endif
                                </button>
                            </th>
                        @endforeach
                        <th scope="col" class="whitespace-nowrap px-3 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-600" title="PO_DAY × {{ $orderPointDays }} ÷ QTY_KBN (hanya process PG, lainnya 0; dibulatkan ke atas, satuan KBN)">MAT_ORDERPOINT</th>
                        <th scope="col" class="whitespace-nowrap px-3 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-600" title="SPM × persen process (CAT_MACHINE SPH: SPM ÷ 60 dulu; selain SPM/SPH: 0), satuan pcs/menit">LOADING</th>
                        <th scope="col" class="sticky right-0 z-10 bg-slate-50 px-3 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-600">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($this->partlists as $partlist)
                        <tr wire:key="partlist-{{ $partlist->code }}" class="group hover:bg-indigo-50/50">
                            @foreach ($fields as $name => $field)
                                <td
                                    wire:key="partlist-{{ $partlist->code }}-{{ $name }}"
                                    @class([
                                        'whitespace-nowrap px-3 py-2',
                                        'sticky left-0 bg-white font-medium text-slate-900 group-hover:bg-indigo-50' => $name === 'code',
                                        'text-right tabular-nums' => $field['type'] === 'integer',
                                    ])
                                >
                                    {{ $partlist->{$name} ?? '' }}
                                </td>
                            @endforeach
                            <td class="whitespace-nowrap px-3 py-2 text-right font-semibold tabular-nums text-slate-900">
                                @php($orderPoint = $partlist->materialOrderPoint($orderPointDays))
                                {{ $orderPoint !== null ? number_format($orderPoint).' KBN' : '-' }}
                            </td>
                            <td class="whitespace-nowrap px-3 py-2 text-right font-semibold tabular-nums text-slate-900">
                                {{ number_format($partlist->loading($loadingService->percentFor($partlist->process, $this->loadingPercents)), 2) }}
                                <span class="text-xs font-normal text-slate-500">pcs/mnt</span>
                            </td>
                            <td class="sticky right-0 whitespace-nowrap bg-white px-3 py-2 text-right group-hover:bg-indigo-50">
                                @can('partlists.edit')
                                <button type="button" wire:click="edit(@js($partlist->code))" class="rounded-md px-2 py-1 text-xs font-semibold text-indigo-600 dark:text-indigo-400 hover:bg-indigo-100">Edit</button>
                                @endcan
                                @can('partlists.delete')
                                <button type="button" wire:click="confirmDelete(@js($partlist->code))" class="rounded-md px-2 py-1 text-xs font-semibold text-rose-600 hover:bg-rose-100">Hapus</button>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ count($fields) + 3 }}" class="px-6 py-12 text-center text-sm text-slate-500">
                                Belum ada data partlist{{ $search !== '' ? ' yang cocok dengan pencarian "'.$search.'"' : '' }}.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($this->partlists->hasPages())
            <div class="border-t border-slate-200 px-4 py-3">
                {{ $this->partlists->links() }}
            </div>
        @else
            <div class="border-t border-slate-200 px-4 py-3 text-xs text-slate-500">
                Total {{ $this->partlists->total() }} data
            </div>
        @endif
    </div>

    {{-- Create / Edit modal --}}
    @if ($showForm)
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/60 p-4 sm:p-8" wire:keydown.escape.window="closeForm">
            <div class="w-full max-w-5xl rounded-xl bg-white shadow-xl" @click.outside="$wire.closeForm()">
                <form wire:submit="save">
                    <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
                        <h2 class="text-lg font-semibold text-slate-900">
                            {{ $editingCode ? 'Edit Partlist: '.$editingCode : 'Tambah Partlist' }}
                        </h2>
                        <button type="button" wire:click="closeForm" class="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600">
                            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <div class="grid grid-cols-1 gap-4 px-6 py-5 sm:grid-cols-2 lg:grid-cols-4">
                        @foreach ($fields as $name => $field)
                            <div wire:key="field-{{ $name }}" @class(['sm:col-span-2' => in_array($name, ['code', 'part_name'])])>
                                <label for="form-{{ $name }}" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-600">
                                    {{ $field['label'] }}
                                    @if (($field['required'] ?? false) && $name !== 'code')
                                        <span class="text-rose-500">*</span>
                                    @endif
                                </label>
                                <input
                                    id="form-{{ $name }}"
                                    type="{{ $field['type'] === 'integer' ? 'number' : 'text' }}"
                                    @if ($field['type'] === 'integer') min="0" step="1" @else maxlength="{{ $field['max'] }}" @endif
                                    wire:model="form.{{ $name }}"
                                    @if ($name === 'code')
                                        @disabled($editingCode !== null)
                                        placeholder="Kosongkan untuk otomatis: PART_NO + LEVEL"
                                    @endif
                                    @class([
                                        'w-full rounded-lg border px-3 py-2 text-sm focus:outline-none focus:ring-2 disabled:bg-slate-100 disabled:text-slate-500',
                                        'border-rose-400 focus:border-rose-500 focus:ring-rose-500/20' => $errors->has("form.{$name}"),
                                        'border-slate-300 focus:border-indigo-500 focus:ring-indigo-500/20' => ! $errors->has("form.{$name}"),
                                    ])
                                >
                                @error("form.{$name}")
                                    <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                                @enderror
                            </div>
                        @endforeach
                    </div>

                    <div class="flex justify-end gap-3 rounded-b-xl border-t border-slate-200 bg-slate-50 px-6 py-4">
                        <button type="button" wire:click="closeForm" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">
                            Batal
                        </button>
                        <button type="submit" wire:loading.attr="disabled" wire:target="save" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-60">
                            <span wire:loading.remove wire:target="save">Simpan</span>
                            <span wire:loading wire:target="save">Menyimpan...</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- Loading parameter modal --}}
    @if ($showLoadingSettings)
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/60 p-4 sm:p-8" wire:keydown.escape.window="$set('showLoadingSettings', false)">
            <div class="w-full max-w-lg rounded-xl bg-white shadow-xl">
                <form wire:submit="saveLoadingSettings">
                    <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
                        <h2 class="text-lg font-semibold text-slate-900">Parameter Loading per Process</h2>
                        <button type="button" wire:click="$set('showLoadingSettings', false)" class="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600">
                            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <div class="space-y-4 px-6 py-5">
                        <p class="rounded-lg bg-slate-50 p-3 text-xs text-slate-600 ring-1 ring-slate-200">
                            LOADING (pcs/menit) = SPM × persen. Jika CAT_MACHINE <span class="font-semibold">SPH</span>, SPM dibagi 60 dulu; jika <span class="font-semibold">SPM</span> langsung dikali; selain itu (mis. 0) nilainya 0.
                        </p>

                        @forelse ($loadingParameters as $index => $parameter)
                            <div wire:key="loading-parameter-{{ $index }}" class="flex items-center justify-between gap-4">
                                <label for="loading-percent-{{ $index }}" class="text-sm font-semibold text-slate-900">{{ $parameter['process'] }}</label>
                                <div class="flex items-center gap-2">
                                    <input
                                        id="loading-percent-{{ $index }}"
                                        type="number"
                                        min="1"
                                        max="100"
                                        step="1"
                                        wire:model="loadingParameters.{{ $index }}.percent"
                                        @class([
                                            'w-24 rounded-lg border px-3 py-2 text-right text-sm tabular-nums focus:outline-none focus:ring-2',
                                            'border-rose-400 focus:border-rose-500 focus:ring-rose-500/20' => $errors->has("loadingParameters.{$index}.percent"),
                                            'border-slate-300 focus:border-indigo-500 focus:ring-indigo-500/20' => ! $errors->has("loadingParameters.{$index}.percent"),
                                        ])
                                    >
                                    <span class="text-sm text-slate-500">%</span>
                                </div>
                            </div>
                            @error("loadingParameters.{$index}.percent")
                                <p class="-mt-2 text-right text-xs text-rose-600">{{ $message }}</p>
                            @enderror
                        @empty
                            <p class="text-sm text-slate-500">Belum ada process di data partlist.</p>
                        @endforelse
                    </div>

                    <div class="flex justify-end gap-3 rounded-b-xl border-t border-slate-200 bg-slate-50 px-6 py-4">
                        <button type="button" wire:click="$set('showLoadingSettings', false)" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">Batal</button>
                        <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">Simpan</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- Import modal --}}
    @if ($showImport)
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/60 p-4 sm:p-8" wire:keydown.escape.window="closeImport">
            <div class="w-full max-w-2xl rounded-xl bg-white shadow-xl">
                <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
                    <h2 class="text-lg font-semibold text-slate-900">Import Partlist dari Excel</h2>
                    <button type="button" wire:click="closeImport" class="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600">
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                @if ($importResult)
                    <div class="space-y-4 px-6 py-5">
                        <div class="grid grid-cols-3 gap-3 text-center">
                            <div class="rounded-lg bg-emerald-50 p-3 ring-1 ring-emerald-200">
                                <p class="text-2xl font-bold text-emerald-700">{{ $importResult['created'] }}</p>
                                <p class="text-xs font-medium text-emerald-700">Ditambahkan</p>
                            </div>
                            <div class="rounded-lg bg-sky-50 p-3 ring-1 ring-sky-200">
                                <p class="text-2xl font-bold text-sky-700">{{ $importResult['updated'] }}</p>
                                <p class="text-xs font-medium text-sky-700">Diperbarui</p>
                            </div>
                            <div class="rounded-lg bg-rose-50 p-3 ring-1 ring-rose-200">
                                <p class="text-2xl font-bold text-rose-700">{{ $importResult['failed'] }}</p>
                                <p class="text-xs font-medium text-rose-700">Gagal</p>
                            </div>
                        </div>

                        @if ($importResult['ignored_headers'])
                            <p class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 ring-1 ring-amber-200">
                                Kolom tidak dikenali dan diabaikan: <span class="font-semibold">{{ implode(', ', $importResult['ignored_headers']) }}</span>
                            </p>
                        @endif

                        @if ($importResult['errors'])
                            <div class="max-h-72 overflow-y-auto rounded-lg ring-1 ring-slate-200">
                                <table class="min-w-full divide-y divide-slate-200 text-xs">
                                    <thead class="sticky top-0 bg-slate-50">
                                        <tr>
                                            <th class="px-3 py-2 text-left font-semibold text-slate-600">Baris</th>
                                            <th class="px-3 py-2 text-left font-semibold text-slate-600">Code</th>
                                            <th class="px-3 py-2 text-left font-semibold text-slate-600">Keterangan</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        @foreach (array_slice($importResult['errors'], 0, 200) as $error)
                                            <tr wire:key="import-error-{{ $loop->index }}">
                                                <td class="whitespace-nowrap px-3 py-2 tabular-nums">{{ $error['row'] }}</td>
                                                <td class="whitespace-nowrap px-3 py-2 font-medium">{{ $error['code'] ?? '-' }}</td>
                                                <td class="px-3 py-2 text-rose-700">{{ implode(' ', $error['messages']) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            @if (count($importResult['errors']) > 200)
                                <p class="text-xs text-slate-500">Menampilkan 200 dari {{ count($importResult['errors']) }} baris gagal.</p>
                            @endif
                        @endif
                    </div>

                    <div class="flex justify-end gap-3 rounded-b-xl border-t border-slate-200 bg-slate-50 px-6 py-4">
                        <button type="button" wire:click="openImport" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">Import Lagi</button>
                        <button type="button" wire:click="closeImport" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">Selesai</button>
                    </div>
                @else
                    <form wire:submit="import">
                        <div class="space-y-4 px-6 py-5">
                            <div class="rounded-lg bg-slate-50 p-4 text-sm text-slate-600 ring-1 ring-slate-200">
                                <ul class="list-disc space-y-1 pl-5">
                                    <li>Format file: <span class="font-semibold">.xlsx, .xls, .csv</span> (maks. 10 MB). Hanya sheet pertama yang dibaca.</li>
                                    <li>Baris pertama berisi header nama kolom seperti <span class="font-semibold">CODE, LEVEL, PART_NO, ...</span> (urutan bebas).</li>
                                    <li>Kolom wajib: <span class="font-semibold">LEVEL</span> dan <span class="font-semibold">PART_NO</span>. Jika CODE kosong, diisi otomatis PART_NO + LEVEL.</li>
                                </ul>
                                <button type="button" wire:click="downloadTemplate" class="mt-3 inline-flex items-center gap-1 text-sm font-semibold text-indigo-600 dark:text-indigo-400 hover:text-indigo-500">
                                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                                    </svg>
                                    Download template Excel
                                </button>
                            </div>

                            <div>
                                <label for="import-file" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-600">File Excel</label>
                                <input
                                    id="import-file"
                                    type="file"
                                    wire:model="importFile"
                                    accept=".xlsx,.xls,.csv"
                                    class="block w-full cursor-pointer rounded-lg border border-slate-300 text-sm text-slate-600 file:mr-3 file:cursor-pointer file:border-0 file:bg-indigo-50 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-indigo-700 hover:file:bg-indigo-100"
                                >
                                <p wire:loading wire:target="importFile" class="mt-1 text-xs text-slate-500">Mengunggah file...</p>
                                @error('importFile')
                                    <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <label class="flex items-start gap-2 text-sm text-slate-700">
                                <input type="checkbox" wire:model="importUpdateExisting" class="mt-0.5 rounded border-slate-300 text-indigo-600 dark:text-indigo-400 focus:ring-indigo-500">
                                <span>Perbarui data jika <span class="font-semibold">CODE</span> sudah ada <span class="text-slate-500">(jika tidak dicentang, baris dengan code yang sudah ada dilewati)</span></span>
                            </label>
                        </div>

                        <div class="flex justify-end gap-3 rounded-b-xl border-t border-slate-200 bg-slate-50 px-6 py-4">
                            <button type="button" wire:click="closeImport" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">Batal</button>
                            <button type="submit" wire:loading.attr="disabled" wire:target="import,importFile" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500 disabled:opacity-60">
                                <span wire:loading.remove wire:target="import">Import</span>
                                <span wire:loading wire:target="import">Mengimport...</span>
                            </button>
                        </div>
                    </form>
                @endif
            </div>
        </div>
    @endif

    {{-- Delete confirmation --}}
    @if ($deletingCode)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" wire:keydown.escape.window="$set('deletingCode', null)">
            <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
                <h2 class="text-lg font-semibold text-slate-900">Hapus Partlist?</h2>
                <p class="mt-2 text-sm text-slate-600">
                    Data dengan code <span class="font-semibold text-slate-900">{{ $deletingCode }}</span> akan dihapus permanen.
                </p>
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" wire:click="$set('deletingCode', null)" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">Batal</button>
                    <button type="button" wire:click="delete" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-500">Hapus</button>
                </div>
            </div>
        </div>
    @endif
</div>
