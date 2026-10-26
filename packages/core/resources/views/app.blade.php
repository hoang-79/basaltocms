@php
    // TB 21: Darstellung nach dem Profil — schon im HTML gesetzt, damit nichts hell aufblitzt; «system» entscheidet der Browser
    $theme = \Basalto\Core\Support\Preferences::of(auth()->user())['theme'];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme-pref="{{ $theme }}"@if ($theme === 'dark') data-theme="dark"@endif>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
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
