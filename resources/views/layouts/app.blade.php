<!DOCTYPE html>
<html
    lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    data-theme="{{ request()->cookie('theme') === 'dark' ? 'dark' : 'light' }}"
    data-sidebar-collapsed="{{ request()->cookie('sidebar_collapsed') === 'true' ? 'true' : 'false' }}"
>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name') }}</title>

    @include('layouts.partials.favicon')

    @include('layouts.partials.ui-preferences')

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body
    class="min-h-screen bg-slate-100 font-sans text-slate-800 antialiased"
    x-data="{
        sidebarOpen: false,
        collapsed: document.documentElement.dataset.sidebarCollapsed === 'true',
        theme: document.documentElement.dataset.theme === 'dark' ? 'dark' : 'light',
        setTheme(theme) {
            this.theme = theme;
            document.documentElement.dataset.theme = theme;
            window.uiPreferences.save('theme', theme);
        },
        isDesktop() { return window.matchMedia('(min-width: 64rem)').matches },
        toggleCollapsed() {
            this.collapsed = ! this.collapsed;
            document.documentElement.dataset.sidebarCollapsed = this.collapsed ? 'true' : 'false';
            window.uiPreferences.save('sidebar_collapsed', this.collapsed ? 'true' : 'false');
        },
        expandSidebar() {
            if (this.collapsed && this.isDesktop()) { this.toggleCollapsed() }
        },
    }"
>
    {{-- Mobile overlay --}}
    <div x-show="sidebarOpen" x-transition.opacity class="fixed inset-0 z-30 bg-black/60 lg:hidden" @click="sidebarOpen = false" x-cloak></div>

    {{-- Sidebar --}}
    <aside
        class="fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col bg-slate-900 text-slate-300 transition-[width,translate] duration-200 lg:translate-x-0 mini:w-16"
        :class="{ 'translate-x-0': sidebarOpen }"
    >
        <div class="flex flex-col items-start gap-2 border-b border-slate-800 px-5 py-4 mini:items-center mini:px-0">
            <img src="{{ asset('images/logo.png') }}" alt="STEP" class="h-11 w-auto mini:hidden">
            <img src="{{ asset('images/logo-mark.png') }}" alt="STEP" class="hidden size-10 mini:block">
            <div class="leading-tight mini:hidden">
                <p class="text-sm font-semibold text-white">LOT MAKING</p>
                <p class="text-xs text-slate-400">SOS Integrated View</p>
            </div>
        </div>

        <nav class="flex-1 space-y-1 overflow-y-auto overflow-x-hidden px-3 py-4 mini:px-2">
            @can('partlists.view')
            <p class="px-3 pb-2 text-xs font-semibold uppercase tracking-wider text-slate-500 mini:hidden">Master Data</p>
            @endcan

            @can('partlists.view')
            <a
                href="{{ route('partlists.index') }}"
                wire:navigate
                title="Partlist"
                @class([
                    'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition mini:justify-center mini:px-0',
                    'bg-indigo-600 text-white' => request()->routeIs('partlists.*'),
                    'hover:bg-slate-800 hover:text-white' => ! request()->routeIs('partlists.*'),
                ])
            >
                <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5m8.25 3v6.75m0 0l-3-3m3 3l3-3M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" />
                </svg>
                <span class="mini:hidden">Partlist</span>
            </a>
            @endcan

            @canany(['stock_parts.view', 'stock_history.view', 'daily_stock.view', 'machine_stock.view'])
            <p class="px-3 pb-2 pt-4 text-xs font-semibold uppercase tracking-wider text-slate-500 mini:hidden">Stock</p>
            <div class="my-3 hidden border-t border-slate-800 mini:block"></div>
            @endcanany

            @can('stock_parts.view')
            <a
                href="{{ route('stock-parts.index') }}"
                wire:navigate
                title="Stock Part All"
                @class([
                    'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition mini:justify-center mini:px-0',
                    'bg-indigo-600 text-white' => request()->routeIs('stock-parts.*'),
                    'hover:bg-slate-800 hover:text-white' => ! request()->routeIs('stock-parts.*'),
                ])
            >
                <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 006 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0118 16.5h-2.25m-7.5 0h7.5m-7.5 0l-1 3m8.5-3l1 3m0 0l.5 1.5m-.5-1.5h-9.5m0 0l-.5 1.5M9 11.25v1.5M12 9v3.75m3-6v6" />
                </svg>
                <span class="mini:hidden">Stock Part All</span>
            </a>
            @endcan

            @can('stock_history.view')
            <a
                href="{{ route('stock-history.index') }}"
                wire:navigate
                title="History Stock"
                @class([
                    'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition mini:justify-center mini:px-0',
                    'bg-indigo-600 text-white' => request()->routeIs('stock-history.*'),
                    'hover:bg-slate-800 hover:text-white' => ! request()->routeIs('stock-history.*'),
                ])
            >
                <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />
                </svg>
                <span class="mini:hidden">History Stock</span>
            </a>
            @endcan

            @can('daily_stock.view')
            <a
                href="{{ route('daily-stock.index') }}"
                wire:navigate
                title="Daily Stock"
                @class([
                    'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition mini:justify-center mini:px-0',
                    'bg-indigo-600 text-white' => request()->routeIs('daily-stock.*'),
                    'hover:bg-slate-800 hover:text-white' => ! request()->routeIs('daily-stock.*'),
                ])
            >
                <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.375 19.5h17.25m-17.25 0a1.125 1.125 0 01-1.125-1.125M3.375 19.5h7.5c.621 0 1.125-.504 1.125-1.125m-9.75 0V5.625m0 12.75v-1.5c0-.621.504-1.125 1.125-1.125m18.375 2.625V5.625m0 12.75c0 .621-.504 1.125-1.125 1.125m1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125m0 3.75h-7.5A1.125 1.125 0 0112 18.375m9.75-12.75c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125m19.5 0v1.5c0 .621-.504 1.125-1.125 1.125M2.25 5.625v1.5c0 .621.504 1.125 1.125 1.125m0 0h17.25m-17.25 0h7.5c.621 0 1.125.504 1.125 1.125M3.375 8.25c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125m17.25-3.75h-7.5c-.621 0-1.125.504-1.125 1.125m8.625-1.125c.621 0 1.125.504 1.125 1.125v1.5c0 .621-.504 1.125-1.125 1.125m-17.25 0h7.5m-7.5 0c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125M12 10.875v-1.5m0 1.5c0 .621-.504 1.125-1.125 1.125M12 10.875c0 .621.504 1.125 1.125 1.125m-2.25 0c.621 0 1.125.504 1.125 1.125M13.125 12h7.5m-7.5 0c-.621 0-1.125.504-1.125 1.125M20.625 12c.621 0 1.125.504 1.125 1.125v1.5c0 .621-.504 1.125-1.125 1.125m-17.25 0h7.5M12 14.625v-1.5m0 1.5c0 .621-.504 1.125-1.125 1.125M12 14.625c0 .621.504 1.125 1.125 1.125m-2.25 0c.621 0 1.125.504 1.125 1.125m0 1.5v-1.5m0 0c0-.621.504-1.125 1.125-1.125m0 0h7.5" />
                </svg>
                <span class="mini:hidden">Daily Stock</span>
            </a>
            @endcan

            @can('machine_stock.view')
            <a
                href="{{ route('machine-stock.index') }}"
                wire:navigate
                title="Kontrol Stok Mesin"
                @class([
                    'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition mini:justify-center mini:px-0',
                    'bg-indigo-600 text-white' => request()->routeIs('machine-stock.index'),
                    'hover:bg-slate-800 hover:text-white' => ! request()->routeIs('machine-stock.index'),
                ])
            >
                <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18L9 11.25l4.306 4.307a11.95 11.95 0 015.814-5.519l2.74-1.22m0 0l-5.94-2.28m5.94 2.28l-2.28 5.941" />
                </svg>
                <span class="mini:hidden">Kontrol Stok Mesin</span>
            </a>
            @endcan

            @can('andon.view')
            <p class="px-3 pb-2 pt-4 text-xs font-semibold uppercase tracking-wider text-slate-500 mini:hidden">Monitoring</p>
            <div class="my-3 hidden border-t border-slate-800 mini:block"></div>

            <x-andon-menu />
            @endcan

            @canany(['users.view', 'recipients.view', 'machines.view'])
            <p class="px-3 pb-2 pt-4 text-xs font-semibold uppercase tracking-wider text-slate-500 mini:hidden">Pengaturan</p>
            <div class="my-3 hidden border-t border-slate-800 mini:block"></div>
            @endcanany

            @can('users.view')
            <a
                href="{{ route('users.index') }}"
                wire:navigate
                title="User & Hak Akses"
                @class([
                    'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition mini:justify-center mini:px-0',
                    'bg-indigo-600 text-white' => request()->routeIs('users.*'),
                    'hover:bg-slate-800 hover:text-white' => ! request()->routeIs('users.*'),
                ])
            >
                <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                </svg>
                <span class="mini:hidden">User &amp; Hak Akses</span>
            </a>
            @endcan

            @can('recipients.view')
            <a
                href="{{ route('recipients.index') }}"
                wire:navigate
                title="Penerima Email"
                @class([
                    'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition mini:justify-center mini:px-0',
                    'bg-indigo-600 text-white' => request()->routeIs('recipients.*'),
                    'hover:bg-slate-800 hover:text-white' => ! request()->routeIs('recipients.*'),
                ])
            >
                <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />
                </svg>
                <span class="mini:hidden">Penerima Email</span>
            </a>
            @endcan

            @can('machines.view')
            <a
                href="{{ route('machine-stock.machines') }}"
                wire:navigate
                title="Mesin Monitoring"
                @class([
                    'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition mini:justify-center mini:px-0',
                    'bg-indigo-600 text-white' => request()->routeIs('machine-stock.machines'),
                    'hover:bg-slate-800 hover:text-white' => ! request()->routeIs('machine-stock.machines'),
                ])
            >
                <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17L17.25 21A2.652 2.652 0 0021 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 11-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 004.486-6.336l-3.276 3.277a3.004 3.004 0 01-2.25-2.25l3.276-3.276a4.5 4.5 0 00-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m-1.745 1.437l1.745-1.437m6.615 8.206L15.75 15.75M4.867 19.125h.008v.008h-.008v-.008z" />
                </svg>
                <span class="mini:hidden">Mesin Monitoring</span>
            </a>
            @endcan
        </nav>

        <button
            type="button"
            @click="toggleCollapsed()"
            class="hidden items-center gap-3 border-t border-slate-800 px-6 py-3 text-sm text-slate-400 hover:bg-slate-800 hover:text-white lg:flex mini:justify-center mini:px-0"
            :title="collapsed ? 'Perlebar sidebar' : 'Kecilkan sidebar'"
        >
            <svg class="size-5 shrink-0 transition-transform mini:rotate-180" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M18.75 19.5l-7.5-7.5 7.5-7.5m-6 15L5.25 12l7.5-7.5" />
            </svg>
            <span class="mini:hidden">Kecilkan sidebar</span>
        </button>
    </aside>

    <div class="transition-[padding] duration-200 lg:pl-64 mini:pl-16">
        <header class="sticky top-0 z-20 flex h-16 items-center gap-4 border-b border-slate-200 bg-white px-4 sm:px-6">
            <button type="button" class="rounded-md p-2 text-slate-500 hover:bg-slate-100 lg:hidden" @click="sidebarOpen = true">
                <span class="sr-only">Buka menu</span>
                <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                </svg>
            </button>
            <button
                type="button"
                class="hidden rounded-md p-2 text-slate-500 hover:bg-slate-100 lg:inline-flex"
                @click="toggleCollapsed()"
                :title="collapsed ? 'Perlebar sidebar' : 'Kecilkan sidebar'"
            >
                <span class="sr-only">Collapse sidebar</span>
                <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                </svg>
            </button>
            <h1 class="flex-1 truncate text-lg font-semibold text-slate-900">{{ $title ?? 'Dashboard' }}</h1>

            <div class="flex items-center rounded-lg bg-slate-100 p-1 text-sm font-medium" role="group" aria-label="Pilihan tampilan">
                <button
                    type="button"
                    @click="setTheme('light')"
                    :class="theme === 'light' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'"
                    class="flex items-center gap-1.5 rounded-md px-3 py-1.5 transition"
                    :aria-pressed="theme === 'light'"
                    title="Tampilan terang"
                >
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386l-1.591 1.591M21 12h-2.25m-.386 6.364l-1.591-1.591M12 18.75V21m-4.773-4.227l-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z" />
                    </svg>
                    <span class="hidden sm:inline">Terang</span>
                </button>
                <button
                    type="button"
                    @click="setTheme('dark')"
                    :class="theme === 'dark' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'"
                    class="flex items-center gap-1.5 rounded-md px-3 py-1.5 transition"
                    :aria-pressed="theme === 'dark'"
                    title="Tampilan gelap (TV genba)"
                >
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z" />
                    </svg>
                    <span class="hidden sm:inline">Gelap</span>
                </button>
            </div>

            @auth
                <div class="flex items-center gap-3">
                    <div class="hidden text-right leading-tight md:block">
                        <p class="text-sm font-semibold text-slate-900">{{ auth()->user()->name }}</p>
                        <p class="text-xs text-slate-500">{{ auth()->user()->roleLabel() }}</p>
                    </div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" title="Keluar" class="flex items-center gap-1.5 rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-100">
                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9" />
                            </svg>
                            <span class="hidden sm:inline">Keluar</span>
                        </button>
                    </form>
                </div>
            @endauth
        </header>

        <main class="p-4 sm:p-6">
            {{ $slot }}
        </main>
    </div>

    @livewireScripts
</body>
</html>
