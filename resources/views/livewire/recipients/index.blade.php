<div class="space-y-4">
    @if (session('status'))
        <div x-data="{ show: true }" x-init="setTimeout(() => show = false, 5000)" x-show="show" x-transition class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('status') }}
        </div>
    @endif
    @if (session('error'))
        <div class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ session('error') }}</div>
    @endif

    @if ($mailer === 'log' || $mailer === 'array')
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            Pengiriman email belum aktif: <code class="rounded bg-amber-100 px-1">MAIL_MAILER={{ $mailer }}</code>, email hanya dicatat ke log aplikasi.
            Isi pengaturan SMTP di file <code class="rounded bg-amber-100 px-1">.env</code> agar email benar-benar terkirim.
        </div>
    @endif

    {{-- Info & toolbar --}}
    <div class="flex flex-col gap-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200 md:flex-row md:items-center md:justify-between">
        <div class="space-y-1 text-sm">
            <p class="text-slate-700">
                File Excel <span class="font-semibold text-slate-900">Daily Stock</span> dikirim otomatis ke
                <span class="font-semibold text-slate-900">{{ $activeCount }} user aktif</span>
                · jadwal <span class="font-semibold text-slate-900">{{ $scheduleLabel }} WIB</span>
            </p>
            <a href="{{ route('daily-stock.index') }}" wire:navigate class="text-xs font-semibold text-indigo-600 hover:underline dark:text-indigo-400">Atur hari &amp; jam di menu Daily Stock →</a>
        </div>

        <div class="flex flex-col gap-2 sm:flex-row">
            <input
                type="search"
                wire:model.live.debounce.300ms="search"
                placeholder="Cari nama atau email..."
                class="rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20"
            >
            @can('recipients.edit')
            <button type="button" wire:click="create" class="inline-flex items-center justify-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                Tambah User
            </button>
            @endcan
        </div>
    </div>

    {{-- Table --}}
    <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50">
                    <tr>
                        @foreach (['Nama', 'Email', 'Status', 'Aksi'] as $header)
                            <th @class(['whitespace-nowrap px-4 py-3 text-xs font-semibold uppercase tracking-wide text-slate-600', 'text-right' => $header === 'Aksi', 'text-left' => $header !== 'Aksi'])>{{ $header }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($this->recipients as $recipient)
                        <tr wire:key="recipient-{{ $recipient->id }}" @class(['hover:bg-indigo-50/50', 'opacity-60' => ! $recipient->is_active])>
                            <td class="whitespace-nowrap px-4 py-3 font-medium text-slate-900">{{ $recipient->name }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-slate-700">{{ $recipient->email }}</td>
                            <td class="whitespace-nowrap px-4 py-3">
                                <button
                                    type="button"
                                    wire:click="toggleActive({{ $recipient->id }})" @disabled(! auth()->user()->can('recipients.edit'))
                                    title="Klik untuk {{ $recipient->is_active ? 'menonaktifkan' : 'mengaktifkan' }}"
                                    @class([
                                        'rounded-full px-2.5 py-0.5 text-xs font-semibold',
                                        'bg-emerald-100 text-emerald-700' => $recipient->is_active,
                                        'bg-slate-200 text-slate-600' => ! $recipient->is_active,
                                    ])
                                >{{ $recipient->is_active ? 'Aktif' : 'Nonaktif' }}</button>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">
                                @can('recipients.edit')
                                <button type="button" wire:click="sendTest({{ $recipient->id }})" wire:loading.attr="disabled" wire:target="sendTest({{ $recipient->id }})" class="rounded-md px-2 py-1 text-xs font-semibold text-emerald-700 hover:bg-emerald-100 disabled:opacity-50">
                                    <span wire:loading.remove wire:target="sendTest({{ $recipient->id }})">Kirim Tes</span>
                                    <span wire:loading wire:target="sendTest({{ $recipient->id }})">Mengirim...</span>
                                </button>
                                @endcan
                                @can('recipients.edit')
                                <button type="button" wire:click="edit({{ $recipient->id }})" class="rounded-md px-2 py-1 text-xs font-semibold text-indigo-600 hover:bg-indigo-100 dark:text-indigo-400">Edit</button>
                                @endcan
                                @can('recipients.delete')
                                <button type="button" wire:click="confirmDelete({{ $recipient->id }})" class="rounded-md px-2 py-1 text-xs font-semibold text-rose-600 hover:bg-rose-100">Hapus</button>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-6 py-12 text-center text-sm text-slate-500">
                                {{ $search !== '' ? 'Tidak ada user yang cocok.' : 'Belum ada user penerima email. Klik Tambah User.' }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Create / edit --}}
    @if ($showForm)
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/60 p-4 sm:p-8" wire:keydown.escape.window="closeForm">
            <form wire:submit="save" class="w-full max-w-md rounded-xl bg-white shadow-xl" @click.outside="$wire.closeForm()">
                <div class="border-b border-slate-200 px-6 py-4">
                    <h2 class="text-lg font-semibold text-slate-900">{{ $editingId ? 'Edit User' : 'Tambah User' }}</h2>
                </div>
                <div class="space-y-4 px-6 py-5">
                    <div>
                        <label for="recipient-name" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-600">Nama</label>
                        <input id="recipient-name" type="text" wire:model="name" maxlength="100" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20">
                        @error('name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="recipient-email" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-600">Email</label>
                        <input id="recipient-email" type="email" wire:model="email" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20">
                        @error('email') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <label class="flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" wire:model="isActive" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                        Aktif (menerima email Daily Stock)
                    </label>
                </div>
                <div class="flex justify-end gap-3 rounded-b-xl border-t border-slate-200 bg-slate-50 px-6 py-4">
                    <button type="button" wire:click="closeForm" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">Batal</button>
                    <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">Simpan</button>
                </div>
            </form>
        </div>
    @endif

    {{-- Delete confirmation --}}
    @if ($deletingId)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" wire:keydown.escape.window="$set('deletingId', null)">
            <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
                <h2 class="text-lg font-semibold text-slate-900">Hapus user?</h2>
                <p class="mt-2 text-sm text-slate-600">User ini tidak akan menerima email Daily Stock lagi.</p>
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" wire:click="$set('deletingId', null)" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">Batal</button>
                    <button type="button" wire:click="delete" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-500">Hapus</button>
                </div>
            </div>
        </div>
    @endif
</div>
