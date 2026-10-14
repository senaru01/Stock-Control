<div class="space-y-4">
    <div class="flex flex-col gap-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200 sm:flex-row sm:items-center sm:justify-between">
        <div class="relative flex-1 sm:max-w-sm">
            <svg class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
            </svg>
            <input
                type="search"
                wire:model.live.debounce.300ms="search"
                placeholder="Cari process atau line..."
                class="w-full rounded-lg border border-slate-300 py-2 pl-9 pr-3 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20"
            >
        </div>
        <p class="text-sm text-slate-500">
            {{ count($this->groups) }} process · {{ collect($this->groups)->sum(fn ($lines) => count($lines)) }} line
        </p>
    </div>

    @forelse ($this->groups as $process => $lines)
        <section wire:key="process-{{ $process }}" class="space-y-2">
            <h2 class="flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-slate-600">
                Process {{ $process }}
                <span class="rounded-full bg-slate-200 px-2 py-0.5 text-xs font-medium text-slate-600">{{ count($lines) }} line</span>
            </h2>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 xl:grid-cols-6">
                @foreach ($lines as $line)
                    <a
                        href="{{ route('andon.show', $line['line']) }}"
                        target="_blank"
                        wire:key="line-{{ $line['line'] }}"
                        class="group rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200 transition hover:ring-2 hover:ring-indigo-500"
                    >
                        <p class="truncate text-lg font-bold text-slate-900 group-hover:text-indigo-600">{{ $line['line'] }}</p>
                        <p class="text-xs text-slate-500">{{ number_format($line['total']) }} part</p>
                    </a>
                @endforeach
            </div>
        </section>
    @empty
        <p class="rounded-xl bg-white px-6 py-12 text-center text-sm text-slate-500 ring-1 ring-slate-200">
            Tidak ada process atau line yang ditemukan.
        </p>
    @endforelse
</div>
