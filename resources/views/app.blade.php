<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
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
