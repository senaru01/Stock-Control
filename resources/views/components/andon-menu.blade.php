@php($isAndon = request()->routeIs('andon.*'))

<div x-data="{ open: @js($isAndon), filter: '' }">
    <button
        type="button"
        @click="if (collapsed && isDesktop()) { expandSidebar(); open = true } else { open = ! open }"
        title="Andon"
        @class([
            'flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition mini:justify-center mini:px-0',
            'text-white' => $isAndon,
            'hover:bg-slate-800 hover:text-white' => ! $isAndon,
        ])
    >
        <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z" />
        </svg>
        <span class="flex-1 text-left mini:hidden">Andon</span>
        <svg class="size-4 transition-transform mini:hidden" :class="open && 'rotate-90'" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
        </svg>
    </button>

    <div x-show="open" x-collapse x-cloak class="mt-1 space-y-1 pl-4 mini:hidden">
        <a
            href="{{ route('andon.index') }}"
            wire:navigate
            @class([
                'block rounded-md px-3 py-1.5 text-sm transition',
                'bg-indigo-600 text-white' => request()->routeIs('andon.index'),
                'hover:bg-slate-800 hover:text-white' => ! request()->routeIs('andon.index'),
            ])
        >
            Semua Line
        </a>

        <input
            type="search"
            x-model="filter"
            placeholder="Filter process / line..."
            class="w-full rounded-md border-0 bg-slate-800 px-3 py-1.5 text-xs text-slate-200 placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
        >

        <div class="max-h-[60vh] space-y-0.5 overflow-y-auto pr-1">
            @foreach ($groups as $process => $processLines)
                @php($isCurrentProcess = $currentLine !== null && in_array($currentLine, array_column($processLines, 'line'), true))
                @php($lineNames = array_map('mb_strtolower', array_column($processLines, 'line')))

                <div
                    wire:key="andon-process-{{ $process }}"
                    x-data="{
                        groupOpen: @js($isCurrentProcess),
                        process: @js(mb_strtolower((string) $process)),
                        lines: @js($lineNames),
                        matchesProcess() { return this.process.includes(filter.toLowerCase()) },
                        matchesLine(line) { return filter === '' || this.matchesProcess() || line.includes(filter.toLowerCase()) },
                    }"
                    x-show="filter === '' || matchesProcess() || lines.some(line => line.includes(filter.toLowerCase()))"
                >
                    <button
                        type="button"
                        @click="groupOpen = ! groupOpen"
                        @class([
                            'flex w-full items-center gap-2 rounded-md px-3 py-1.5 text-left text-xs font-semibold uppercase tracking-wide transition hover:bg-slate-800 hover:text-white',
                            'text-white' => $isCurrentProcess,
                            'text-slate-400' => ! $isCurrentProcess,
                        ])
                    >
                        <svg class="size-3 shrink-0 transition-transform" :class="(groupOpen || filter !== '') && 'rotate-90'" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                        </svg>
                        <span class="flex-1 truncate">{{ $process }}</span>
                        <span class="rounded bg-slate-800 px-1.5 text-[10px] font-medium text-slate-400">{{ count($processLines) }}</span>
                    </button>

                    <div x-show="groupOpen || filter !== ''" x-cloak class="ml-3 space-y-0.5 border-l border-slate-800 pl-2">
                        @foreach ($processLines as $processLine)
                            <a
                                href="{{ route('andon.show', $processLine['line']) }}"
                                target="_blank"
                                wire:key="andon-line-{{ $processLine['line'] }}"
                                x-show="matchesLine(@js(mb_strtolower($processLine['line'])))"
                                @class([
                                    'flex items-center justify-between gap-2 rounded-md px-3 py-1.5 text-sm transition',
                                    'bg-indigo-600 text-white' => $currentLine === $processLine['line'],
                                    'hover:bg-slate-800 hover:text-white' => $currentLine !== $processLine['line'],
                                ])
                            >
                                <span class="truncate">{{ $processLine['line'] }}</span>
                                <span class="text-[10px] opacity-60">{{ $processLine['total'] }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>
