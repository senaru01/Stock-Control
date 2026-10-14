<div class="space-y-4">
    @if (session('status'))
        <div x-data="{ show: true }" x-init="setTimeout(() => show = false, 5000)" x-show="show" x-transition class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('status') }}
        </div>
    @endif
    @if (session('error'))
        <div class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ session('error') }}</div>
    @endif

    {{-- Toolbar --}}
    <div class="flex flex-col gap-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200 md:flex-row md:items-center md:justify-between">
        <p class="text-sm text-slate-700">
            Akun login aplikasi. <span class="font-semibold text-slate-900">Super Admin</span> boleh semua menu;
            level lain memakai hak akses yang dicentang per user.
        </p>
        <div class="flex flex-col gap-2 sm:flex-row">
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Cari nama / username..." class="rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20">
            @can('users.edit')
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
                        @foreach (['Nama', 'Username', 'Level', 'Hak Akses', 'Login Terakhir', 'Status', 'Aksi'] as $header)
                            <th @class(['whitespace-nowrap px-4 py-3 text-xs font-semibold uppercase tracking-wide text-slate-600', 'text-right' => $header === 'Aksi', 'text-left' => $header !== 'Aksi'])>{{ $header }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($this->users as $user)
                        @php
                            $isMe = $user->is(auth()->user());
                            $locked = $user->isSuperAdmin() && ! auth()->user()->isSuperAdmin();
                            $menuCount = $user->isSuperAdmin() ? count($menus) : collect($user->permissions ?? [])->filter(fn ($permission) => str_ends_with($permission, '.view'))->count();
                        @endphp
                        <tr wire:key="user-{{ $user->id }}" @class(['hover:bg-indigo-50/50', 'opacity-60' => ! $user->is_active])>
                            <td class="whitespace-nowrap px-4 py-3 font-medium text-slate-900">
                                {{ $user->name }}
                                @if ($isMe) <span class="ml-1 rounded bg-indigo-100 px-1.5 text-[10px] font-semibold text-indigo-700">Anda</span> @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 font-mono text-slate-700">{{ $user->username }}</td>
                            <td class="whitespace-nowrap px-4 py-3">
                                <span @class([
                                    'rounded-full px-2.5 py-0.5 text-xs font-semibold',
                                    'bg-violet-100 text-violet-700' => $user->isSuperAdmin(),
                                    'bg-indigo-100 text-indigo-700' => $user->role === 'admin',
                                    'bg-sky-100 text-sky-700' => $user->role === 'gm',
                                    'bg-slate-200 text-slate-700' => $user->role === 'section',
                                ])>{{ $user->roleLabel() }}</span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-slate-700">{{ $user->isSuperAdmin() ? 'Semua menu' : $menuCount.' dari '.count($menus).' menu' }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-slate-700">{{ $user->last_login_at?->timezone('Asia/Jakarta')->format('d M Y H:i') ?? '-' }}</td>
                            <td class="whitespace-nowrap px-4 py-3">
                                @can('users.edit')
                                    <button
                                        type="button"
                                        wire:click="toggleActive({{ $user->id }})"
                                        @disabled($isMe || $locked)
                                        title="{{ $isMe ? 'Akun sendiri' : 'Klik untuk '.($user->is_active ? 'menonaktifkan' : 'mengaktifkan') }}"
                                        @class([
                                            'rounded-full px-2.5 py-0.5 text-xs font-semibold disabled:cursor-not-allowed',
                                            'bg-emerald-100 text-emerald-700' => $user->is_active,
                                            'bg-slate-200 text-slate-600' => ! $user->is_active,
                                        ])
                                    >{{ $user->is_active ? 'Aktif' : 'Nonaktif' }}</button>
                                @else
                                    <span @class(['rounded-full px-2.5 py-0.5 text-xs font-semibold', 'bg-emerald-100 text-emerald-700' => $user->is_active, 'bg-slate-200 text-slate-600' => ! $user->is_active])>{{ $user->is_active ? 'Aktif' : 'Nonaktif' }}</span>
                                @endcan
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">
                                @if (! $locked)
                                    @can('users.edit')
                                        <button type="button" wire:click="edit({{ $user->id }})" class="rounded-md px-2 py-1 text-xs font-semibold text-indigo-600 hover:bg-indigo-100 dark:text-indigo-400">Edit / Hak Akses</button>
                                    @endcan
                                    @can('users.delete')
                                        @unless ($isMe)
                                            <button type="button" wire:click="confirmDelete({{ $user->id }})" class="rounded-md px-2 py-1 text-xs font-semibold text-rose-600 hover:bg-rose-100">Hapus</button>
                                        @endunless
                                    @endcan
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center text-sm text-slate-500">{{ $search !== '' ? 'Tidak ada user yang cocok.' : 'Belum ada user.' }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Create / edit --}}
    @if ($showForm)
        @php
            $isSuperAdminRole = $role === 'superadmin';
            $grantable = $this->grantablePermissions;
        @endphp
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/60 p-4 sm:p-8" wire:keydown.escape.window="closeForm">
            <form wire:submit="save" class="w-full max-w-3xl rounded-xl bg-white shadow-xl">
                <div class="border-b border-slate-200 px-6 py-4">
                    <h2 class="text-lg font-semibold text-slate-900">{{ $editingId ? 'Edit User & Hak Akses' : 'Tambah User' }}</h2>
                </div>
                <div class="space-y-5 px-6 py-5">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label for="user-name" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-600">Nama</label>
                            <input id="user-name" type="text" wire:model="name" maxlength="100" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20">
                            @error('name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="user-username" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-600">Username</label>
                            <input id="user-username" type="text" wire:model="username" maxlength="50" autocomplete="off" autocapitalize="none" spellcheck="false" class="w-full rounded-lg border border-slate-300 px-3 py-2 font-mono text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20">
                            @error('username') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="user-password" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-600">Password</label>
                            <input id="user-password" type="password" wire:model="password" autocomplete="new-password" placeholder="{{ $editingId ? 'Kosongkan jika tidak diganti' : 'Minimal 6 karakter' }}" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20">
                            @error('password') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="user-email" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-600">Email (opsional)</label>
                            <input id="user-email" type="email" wire:model="email" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20">
                            @error('email') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="user-role" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-600">Level</label>
                            <select id="user-role" wire:model.live="role" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                @foreach ($this->assignableRoles as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-xs text-slate-500">Memilih level mengisi hak akses default-nya; centang di bawah bisa diubah per user.</p>
                            @error('role') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div class="flex items-end">
                            <label class="flex items-center gap-2 pb-2 text-sm text-slate-700">
                                <input type="checkbox" wire:model="isActive" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                Aktif (boleh login)
                            </label>
                        </div>
                    </div>

                    {{-- Permission matrix --}}
                    <div class="rounded-lg ring-1 ring-slate-200">
                        <div class="border-b border-slate-200 bg-slate-50 px-4 py-3">
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-600">Hak akses per menu</p>
                            <p class="text-xs text-slate-500">
                                @if ($isSuperAdminRole)
                                    Super Admin otomatis boleh semua menu dan semua aksi.
                                @else
                                    Lihat = membuka menu · Ubah = tambah, edit, jadwal, ambil data, kirim email · Hapus = menghapus data. Ubah/Hapus butuh Lihat.
                                @endif
                            </p>
                        </div>
                        <table class="min-w-full divide-y divide-slate-100 text-sm">
                            <thead>
                                <tr>
                                    <th class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">Menu</th>
                                    @foreach ($abilities as $ability => $abilityLabel)
                                        <th class="px-4 py-2 text-center text-xs font-semibold uppercase tracking-wide text-slate-600">{{ $abilityLabel }}</th>
                                    @endforeach
                                    <th class="px-4 py-2"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($menus as $menu => $definition)
                                    <tr wire:key="permission-{{ $menu }}">
                                        <td class="whitespace-nowrap px-4 py-2 text-slate-800">{{ $definition['label'] }}</td>
                                        @foreach ($abilities as $ability => $abilityLabel)
                                            @php
                                                $permission = "{$menu}.{$ability}";
                                            @endphp
                                            <td class="px-4 py-2 text-center">
                                                @if (in_array($ability, $definition['abilities'], true))
                                                    <input
                                                        type="checkbox"
                                                        value="{{ $permission }}"
                                                        wire:model.live="permissions"
                                                        aria-label="{{ $abilityLabel }} {{ $definition['label'] }}"
                                                        @checked($isSuperAdminRole || in_array($permission, $permissions, true))
                                                        @disabled($isSuperAdminRole || ! in_array($permission, $grantable, true))
                                                        class="size-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 disabled:opacity-50"
                                                    >
                                                @else
                                                    <span class="text-slate-300">–</span>
                                                @endif
                                            </td>
                                        @endforeach
                                        <td class="px-4 py-2 text-right">
                                            @unless ($isSuperAdminRole)
                                                <button type="button" wire:click="toggleMenu('{{ $menu }}')" class="text-xs font-medium text-indigo-600 hover:underline dark:text-indigo-400">semua</button>
                                            @endunless
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        @error('permissions.*') <p class="px-4 pb-3 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
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
                <p class="mt-2 text-sm text-slate-600">User ini tidak bisa login lagi. Untuk sementara saja, ubah status menjadi Nonaktif.</p>
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" wire:click="$set('deletingId', null)" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">Batal</button>
                    <button type="button" wire:click="delete" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-500">Hapus</button>
                </div>
            </div>
        </div>
    @endif
</div>
