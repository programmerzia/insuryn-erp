<!DOCTYPE html>
@php
    $preferences = $page['props']['preferences'] ?? [];
    $theme = in_array($preferences['theme'] ?? null, ['light', 'dark'], true) ? $preferences['theme'] : null;
    $density = ($preferences['density'] ?? null) === 'comfortable' ? 'comfortable' : 'compact';
@endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @if ($theme) data-theme="{{ $theme }}" @endif data-density="{{ $density }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title inertia>{{ config('app.name', 'Insuryn') }}</title>
        {{-- UX brief §7 first paint: a skeleton frame comes with the HTML, drawn with the theme tokens (a 1 KB stylesheet); the app stylesheet does not block it. --}}
        @vite(['resources/css/theme/corebari.css'])
        <style>
            #boot { position: fixed; inset: 0; display: grid; grid-template: 44px 1fr 28px / 208px 1fr; background: var(--surface); color: var(--ink); font: 13px/20px system-ui, sans-serif; z-index: 100; }
            #boot > header { grid-column: 1 / 3; display: flex; align-items: center; gap: 8px; padding: 0 16px; border-bottom: 1px solid var(--line); font-weight: 600; }
            #boot > nav { background: var(--surface-2); border-right: 1px solid var(--line); padding: 12px; display: grid; align-content: start; gap: 14px; }
            #boot > main { padding: 16px 24px; display: grid; align-content: start; gap: 14px; }
            #boot > footer { grid-column: 1 / 3; background: var(--surface-2); border-top: 1px solid var(--line); }
            #boot i { display: block; height: 10px; border-radius: 4px; background: var(--surface-2); }
            #boot nav i { background: var(--line); }
            @media (max-width: 640px) { #boot { grid-template-columns: 0 1fr; } }
        </style>
        @vite(['resources/css/app.css', 'resources/js/app.ts'])
        <x-inertia::head />
    </head>
    <body class="bg-surface font-sans text-ink">
        <div id="boot" aria-hidden="true">
            @if (! empty($page['props']['auth']['user']))
            <header>Insuryn</header>
            <nav><i style="width: 70%"></i><i style="width: 55%"></i><i style="width: 62%"></i><i style="width: 48%"></i><i style="width: 66%"></i></nav>
            <main><i style="width: 180px; height: 16px"></i><i></i><i></i><i style="width: 85%"></i><i></i><i style="width: 70%"></i></main>
            <footer></footer>
            @endif
        </div>
        <x-inertia::app />
    </body>
</html>
