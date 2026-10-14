<!DOCTYPE html>
<html
    lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    data-theme="{{ request()->cookie('theme') === 'dark' ? 'dark' : 'light' }}"
>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name') }}</title>

    @include('layouts.partials.ui-preferences')

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="batik-backdrop flex min-h-screen items-center justify-center p-4 font-sans text-slate-800 antialiased">
    {{ $slot }}

    @livewireScripts
</body>
</html>
