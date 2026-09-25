<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Laravel') }}</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=manrope:400,500,700,800&display=swap" rel="stylesheet" />

    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-[Manrope] text-slate-900 antialiased">
    @if (request()->routeIs('login'))
        {{ $slot }}
    @else
        <main class="sitra-guest-page">
            <a class="sitra-guest-brand" href="{{ route('login') }}" aria-label="SITRA, ir al inicio de sesión">
                <span class="sitra-guest-brand-mark" aria-hidden="true">S</span>
                <span>SITRA <span class="font-normal text-slate-500">Postal</span></span>
            </a>
            <section class="sitra-guest-card">
                {{ $slot }}
            </section>
            <p class="sitra-guest-footer">Correos de Bolivia · Gestión postal y aduanera</p>
        </main>
    @endif
</body>
</html>
