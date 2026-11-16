@php
    // TB 21: Darstellung nach dem Profil — schon im HTML gesetzt, damit nichts hell aufblitzt; «system» entscheidet der Browser
    $theme = \Basalto\Core\Support\Preferences::of(auth()->user())['theme'];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme-pref="{{ $theme }}"@if ($theme === 'dark') data-theme="dark"@endif>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        {{-- Das Symbol von Basalto — im Backend auch auf der Domain einer Site, die ein eigenes hat --}}
        <link rel="icon" href="{{ route('basalto.icon', ['file' => 'favicon.ico'], false) }}" sizes="32x32">
        <link rel="icon" href="{{ route('basalto.icon', ['file' => 'favicon.svg'], false) }}" type="image/svg+xml">
        <link rel="apple-touch-icon" href="{{ route('basalto.icon', ['file' => 'apple-touch-icon.png'], false) }}">
        @if ($theme === 'system')
            <script>if (window.matchMedia('(prefers-color-scheme: dark)').matches) { document.documentElement.dataset.theme = 'dark'; }</script>
        @endif
        {{-- Assets des Kern-Pakets: fertig gebaut, veröffentlicht nach public/vendor/basalto/core --}}
        {{ app(\Basalto\Core\Backend\Backend::class)->vite() }}
        <x-inertia::head>
            <title>{{ config('app.name', 'Basalto') }}</title>
        </x-inertia::head>
    </head>
    <body class="bg-paper text-basalt antialiased">
        <x-inertia::app />
    </body>
</html>
