<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Laravel') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('images/correos-bolivia.png') }}">

    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="stylesheet" href="{{ asset('css/correos-brand.css') }}">
    <link rel="stylesheet" href="{{ asset('css/sitra-auth.css') }}?v=1">
</head>
<body class="font-[Manrope] text-slate-900 antialiased">
    @if (request()->routeIs('login'))
        {{ $slot }}
    @else
        <main class="sitra-guest-page">
            <a class="sitra-guest-brand" href="{{ route('login') }}" aria-label="SITRA, ir al inicio de sesión">
                <img class="sitra-guest-brand-logo" src="{{ asset('images/correos-bolivia.png') }}" alt="Correos de Bolivia">
                <span>SITRA <span class="font-normal text-slate-500">Postal</span></span>
            </a>
            <section class="sitra-guest-card">
                <div class="sitra-auth-heading"><span class="sitra-eyebrow">CUENTA INSTITUCIONAL</span><h1>{{ match(true) { request()->routeIs('register') => 'Crear una cuenta', request()->routeIs('password.request') => 'Recuperar acceso', request()->routeIs('password.reset') => 'Nueva contraseña', request()->routeIs('password.confirm') => 'Confirmar identidad', default => 'Verifica tu correo' } }}</h1></div>
                {{ $slot }}
            </section>
            <a class="sitra-link" href="{{ route('login') }}">Volver al inicio de sesión</a>
            <p class="sitra-guest-footer">Correos de Bolivia · Gestión postal y aduanera</p>
        </main>
    @endif
</body>
</html>
