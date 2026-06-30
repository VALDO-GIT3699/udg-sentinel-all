<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <meta name="csrf-token" content="{{ csrf_token() }}" />
        <meta http-equiv="X-UA-Compatible" content="IE=edge" />

        {{-- Título dinámico gestionado por Inertia --}}
        <title inertia>{{ config('app.name') }}</title>

        {{-- Fuentes: Inter (professional SaaS look) --}}
        <link rel="preconnect" href="https://fonts.bunny.net" />
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800&display=swap" rel="stylesheet" />

        {{-- Favicon --}}
        <link rel="icon" type="image/svg+xml" href="/favicon.svg" />

        {{-- Inertia + Vite --}}
        @routes
        @vite(['resources/css/app.css', 'resources/js/app.ts'])
        @inertiaHead
    </head>
    <body class="font-sans antialiased bg-gray-950 text-gray-100">
        @inertia
    </body>
</html>
