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

    <title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name') }} Admin</title>

    @include('layouts.partials.favicon')

    @include('layouts.partials.ui-preferences')

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
{{-- Full-screen andon display (for genba TVs): no sidebar or header, the page uses the whole window. --}}
<body class="min-h-screen bg-slate-100 font-sans text-slate-800 antialiased">
    <main class="p-3 sm:p-4">
        {{ $slot }}
    </main>

    @livewireScripts
</body>
</html>
