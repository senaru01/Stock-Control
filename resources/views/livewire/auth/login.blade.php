<div class="w-full max-w-sm">
    <div class="mb-6 flex flex-col items-center gap-2 text-center">
        <div class="rounded-xl bg-[#0f172a] px-5 py-3">
            <img src="{{ asset('images/logo.png') }}" alt="STEP" class="h-11 w-auto">
        </div>
        <div>
            <p class="text-lg font-semibold text-slate-900">LOT MAKING</p>
            <p class="text-sm text-slate-500">SOS Integrated View</p>
        </div>
    </div>

    <form wire:submit="login" class="space-y-4 rounded-xl border-t-4 border-[#8a5a2b] bg-white p-6 shadow-xl ring-1 ring-slate-200 dark:border-[#c9a24a]">
        <h1 class="text-base font-semibold text-slate-900">Masuk</h1>

        <div>
            <label for="login-username" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-600">Username</label>
            <input id="login-username" type="text" wire:model="username" autocomplete="username" autofocus autocapitalize="none" spellcheck="false" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20">
            @error('username') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>

        <div x-data="{ show: false }">
            <label for="login-password" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-600">Password</label>
            <div class="relative">
                <input id="login-password" :type="show ? 'text' : 'password'" type="password" wire:model="password" autocomplete="current-password" class="w-full rounded-lg border border-slate-300 py-2 pl-3 pr-16 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20">
                <button type="button" @click="show = ! show" class="absolute inset-y-0 right-0 px-3 text-xs font-medium text-slate-500 hover:text-slate-700" x-text="show ? 'Sembunyi' : 'Lihat'">Lihat</button>
            </div>
            @error('password') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>

        <label class="flex items-center gap-2 text-sm text-slate-700">
            <input type="checkbox" wire:model="remember" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
            Ingat saya di perangkat ini
        </label>

        <button type="submit" wire:loading.attr="disabled" wire:target="login" class="w-full rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-60">
            <span wire:loading.remove wire:target="login">Masuk</span>
            <span wire:loading wire:target="login">Memeriksa...</span>
        </button>
    </form>
</div>
