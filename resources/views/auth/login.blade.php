<x-guest-layout>
    <style>
        :root {
            --sitra-bg: #dce3ea;
            --sitra-card: #f8fafc;
            --sitra-ink: #0f172a;
            --sitra-muted: #4b5563;
            --sitra-line: #c9d5e3;
            --sitra-cyan: #0ea5c6;
            --sitra-cyan-strong: #0891b2;
            --sitra-navy: #111c4e;
        }

        .sitra-login-page {
            min-height: 100vh;
            margin: 0;
            display: grid;
            place-items: center;
            padding: 30px;
            background:
                radial-gradient(circle at 10% 10%, rgba(255,255,255,.55), transparent 30%),
                radial-gradient(circle at 90% 20%, rgba(255,255,255,.35), transparent 25%),
                var(--sitra-bg);
            font-family: "Segoe UI", "Trebuchet MS", Verdana, sans-serif;
        }

        .sitra-login-wrap {
            width: min(1080px, 100%);
            display: grid;
            grid-template-columns: 440px 1fr;
            border-radius: 28px;
            overflow: hidden;
            background: #ffffff;
            box-shadow: 0 24px 70px rgba(15, 23, 42, .22);
            border: 1px solid rgba(255,255,255,.7);
        }

        .sitra-login-left {
            background: linear-gradient(180deg, #ffffff, var(--sitra-card));
            padding: 44px 34px;
        }

        .sitra-eyebrow {
            margin: 0;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: .24em;
            text-transform: uppercase;
            color: var(--sitra-cyan-strong);
        }

        .sitra-title {
            margin: 10px 0 26px;
            font-size: 40px;
            line-height: 1;
            letter-spacing: -0.02em;
            color: var(--sitra-ink);
            font-weight: 900;
        }

        .sitra-field { margin-bottom: 16px; }

        .sitra-label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: 700;
            color: #22324a;
        }

        .sitra-input-wrap { position: relative; }

        .sitra-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            width: 18px;
            height: 18px;
            color: #7b8ba1;
            pointer-events: none;
        }

        .sitra-input {
            width: 100%;
            border: 1px solid var(--sitra-line);
            border-radius: 12px;
            padding: 13px 14px 13px 40px;
            background: #fff;
            color: var(--sitra-ink);
            font-size: 15px;
            outline: none;
            transition: border-color .2s ease, box-shadow .2s ease, background-color .2s ease;
        }

        .sitra-input:focus {
            border-color: var(--sitra-cyan);
            box-shadow: 0 0 0 3px rgba(14, 165, 198, .16);
            background: #ffffff;
        }

        .sitra-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin: 10px 0 22px;
            font-size: 14px;
            color: var(--sitra-muted);
        }

        .sitra-check { display: inline-flex; align-items: center; gap: 8px; }

        .sitra-link {
            color: var(--sitra-cyan-strong);
            text-decoration: none;
            font-weight: 700;
        }

        .sitra-link:hover { text-decoration: underline; }

        .sitra-btn {
            width: 100%;
            border: 0;
            border-radius: 12px;
            padding: 13px 16px;
            background: linear-gradient(90deg, var(--sitra-cyan-strong), var(--sitra-cyan));
            color: #fff;
            font-size: 27px;
            font-weight: 900;
            letter-spacing: .04em;
            text-transform: uppercase;
            cursor: pointer;
            box-shadow: 0 14px 25px rgba(8, 145, 178, .3);
            transition: transform .2s ease, filter .2s ease;
        }

        .sitra-btn:hover { filter: brightness(1.06); transform: translateY(-1px); }
        .sitra-btn:active { transform: translateY(0); }

        .sitra-login-right {
            position: relative;
            min-height: 590px;
            overflow: hidden;
            background:
                radial-gradient(circle at 70% 20%, rgba(45, 212, 191, .18), transparent 35%),
                var(--sitra-navy);
        }

        .sitra-diag {
            position: absolute;
            left: -150px;
            top: -40px;
            width: 66%;
            height: 130%;
            background: linear-gradient(180deg, #ffffff, #f7fbff);
            transform: rotate(20deg);
            box-shadow: 8px 0 30px rgba(0,0,0,.08);
        }

        .sitra-grid {
            position: absolute;
            inset: 0;
            opacity: .2;
            background-image: radial-gradient(#22d3ee 1px, transparent 1px);
            background-size: 16px 16px;
            background-position: 85% 22%;
        }

        .sitra-copy {
            position: absolute;
            right: 40px;
            bottom: 50px;
            text-align: right;
            color: #e2e8f0;
        }

        .sitra-brand {
            font-size: 56px;
            line-height: .9;
            letter-spacing: .06em;
            font-weight: 900;
            color: #22d3ee;
            text-transform: uppercase;
            margin: 0;
        }

        .sitra-sub {
            margin: 8px 0 0;
            font-size: 13px;
            font-weight: 700;
            letter-spacing: .2em;
            text-transform: uppercase;
            opacity: .85;
        }

        @media (max-width: 920px) {
            .sitra-login-wrap { grid-template-columns: 1fr; max-width: 560px; }
            .sitra-login-right { min-height: 180px; }
            .sitra-diag { width: 100%; left: -20%; top: -120px; transform: rotate(10deg); }
            .sitra-copy { right: 24px; bottom: 20px; }
            .sitra-brand { font-size: 38px; }
            .sitra-title { font-size: 34px; }
        }
    </style>

    <main class="sitra-login-page">
        <section class="sitra-login-wrap">
            <div class="sitra-login-left">
                <p class="sitra-eyebrow">Acceso</p>
                <h2 class="sitra-title">Login SITRA</h2>

                <x-auth-session-status class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700" :status="session('status')" />

                <form method="POST" action="{{ route('login') }}">
                    @csrf

                    <div class="sitra-field">
                        <label for="email" class="sitra-label">Email</label>
                        <div class="sitra-input-wrap">
                            <svg class="sitra-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16v12H4z"/><path d="m4 8 8 6 8-6"/></svg>
                            <input id="email" class="sitra-input" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username" />
                        </div>
                        <x-input-error :messages="$errors->get('email')" class="mt-2 text-sm" />
                    </div>

                    <div class="sitra-field">
                        <label for="password" class="sitra-label">Password</label>
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
                            <a class="sitra-link" href="{{ route('password.request') }}">Olvide mi contrasena</a>
                        @endif
                    </div>

                    <button type="submit" class="sitra-btn">Entrar</button>
                </form>
            </div>

            <div class="sitra-login-right">
                <div class="sitra-diag"></div>
                <div class="sitra-grid"></div>
                <div class="sitra-copy">
                    <p class="sitra-brand">SITRA</p>
                    <p class="sitra-sub">Sistema de acceso</p>
                </div>
            </div>
        </section>
    </main>
</x-guest-layout>
