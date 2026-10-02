<x-guest-layout>
<main class="sitra-login-page">
        <section class="sitra-login-wrap">
            <div class="sitra-login-left">
                <img class="sitra-login-logo" src="{{ asset('images/correos-bolivia.png') }}" alt="Correos de Bolivia">
                <p class="sitra-eyebrow">Acceso</p>
                <h1 class="sitra-title">Tu operación postal,<br>en un solo lugar.</h1><p class="sitra-login-intro">Ingresa con tu cuenta institucional para continuar.</p>

                <x-auth-session-status class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700" :status="session('status')" />

                <form method="POST" action="{{ route('login') }}">
                    @csrf

                    <div class="sitra-field">
                        <label for="email" class="sitra-label">Correo electrónico</label>
                        <div class="sitra-input-wrap">
                            <svg class="sitra-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16v12H4z"/><path d="m4 8 8 6 8-6"/></svg>
                            <input id="email" class="sitra-input" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username" />
                        </div>
                        <x-input-error :messages="$errors->get('email')" class="mt-2 text-sm" />
                    </div>

                    <div class="sitra-field">
                        <label for="password" class="sitra-label">Contraseña</label>
                        <div class="sitra-input-wrap">
                            <svg class="sitra-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="11" width="16" height="9" rx="2"/><path d="M8 11V8a4 4 0 1 1 8 0v3"/></svg>
                            <input id="password" class="sitra-input" type="password" name="password" required autocomplete="current-password" />
                        </div>
                        <x-input-error :messages="$errors->get('password')" class="mt-2 text-sm" />
                    </div>

                    <div class="sitra-row">
                        <label for="remember_me" class="sitra-check">
                            <input id="remember_me" type="checkbox" name="remember">
                            <span>Recordarme</span>
                        </label>

                        @if (Route::has('password.request'))
                            <a class="sitra-link" href="{{ route('password.request') }}">Olvidé mi contraseña</a>
                        @endif
                    </div>

                    <button type="submit" class="sitra-btn">Entrar</button>
                </form>
            </div>

            <div class="sitra-login-right">
                <div class="sitra-route-art" aria-hidden="true"><span class="route-node route-node-a"></span><span class="route-node route-node-b"></span><span class="route-node route-node-c"></span><div class="sitra-parcel"><span></span><i></i></div></div>
                <div class="sitra-grid"></div>
                <div class="sitra-copy">
                    <p class="sitra-brand">SITRA</p>
                    <p class="sitra-sub">Cada envío, una conexión.</p><p class="sitra-login-story">Información, seguimiento y gestión<br>para acercar Bolivia al mundo.</p>
                </div>
            </div>
        </section>
    </main>
</x-guest-layout>
