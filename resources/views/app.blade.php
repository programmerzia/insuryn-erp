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
        @vite(['resources/css/app.css', 'resources/js/app.ts'])
        <x-inertia::head />
    </head>
    <body class="bg-surface font-sans text-ink">
        <x-inertia::app />
    </body>
</html>
