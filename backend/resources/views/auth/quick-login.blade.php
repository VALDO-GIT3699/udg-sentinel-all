<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Ingreso Seguro · UDG Sentinel</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800&display=swap" rel="stylesheet">

    <link rel="shortcut icon" href="{{ asset('images/universidad-de-guadalajara-logo-png_seeklogo-617642.png') }}?v={{ time() }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/universidad-de-guadalajara-logo-png_seeklogo-617642.png') }}?v={{ time() }}">
    <link rel="apple-touch-icon" href="{{ asset('images/universidad-de-guadalajara-logo-png_seeklogo-617642.png') }}?v={{ time() }}">

    @vite(['resources/css/app.css'])
</head>
<body class="font-sans antialiased">
    <main class="relative min-h-screen overflow-x-hidden bg-sky-50 text-slate-900">
        {{-- Fondo "aurora": misma identidad visual que el Dashboard y el detalle de sitio --}}
        <div class="pointer-events-none fixed inset-0 -z-10 overflow-hidden" aria-hidden="true">
            <div class="absolute -left-40 -top-48 h-[34rem] w-[34rem] rounded-full bg-sky-300/25 blur-[130px]"></div>
            <div class="absolute -right-32 top-1/4 h-[30rem] w-[30rem] rounded-full bg-blue-300/20 blur-[130px]"></div>
            <div class="absolute bottom-[-10rem] left-1/3 h-[28rem] w-[28rem] rounded-full bg-emerald-300/15 blur-[130px]"></div>
            <div class="absolute left-1/2 top-0 h-[24rem] w-[50rem] -translate-x-1/2 rounded-full bg-sky-200/25 blur-[150px]"></div>
        </div>

        <section class="relative z-10 flex min-h-screen items-center justify-center px-4 py-10">
            <div class="w-full max-w-md">

                {{-- Encabezado institucional: UDG + CGTA --}}
                <div class="mb-6 flex flex-col items-center gap-3 text-center">
                    <div class="glass-panel flex items-center gap-3 rounded-2xl bg-white/60 px-5 py-3">
                        <img
                            src="{{ asset('images/universidad-de-guadalajara-logo-png_seeklogo-617642.png') }}"
                            alt="Universidad de Guadalajara"
                            class="h-11 w-11 shrink-0 object-contain"
                            width="44"
                            height="44"
                        >
                        <div class="h-9 w-px bg-slate-900/10"></div>
                        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-xs font-extrabold tracking-tight text-white"
                             style="background: linear-gradient(135deg, var(--color-udg-blue), var(--color-udg-green));"
                             aria-hidden="true">
                            CGTA
                        </div>
                        <div class="text-left leading-tight">
                            <p class="text-[0.68rem] font-semibold uppercase tracking-wider text-slate-700">Universidad de Guadalajara</p>
                            <p class="text-[0.68rem] text-slate-600">Coordinación General de Tecnologías&nbsp;Administrativas</p>
                        </div>
                    </div>
                </div>

                {{-- Tarjeta de acceso --}}
                <div class="glass-panel rounded-2xl bg-white/70 p-6 sm:p-8">
                    <span class="mb-1 inline-block text-[0.68rem] font-bold uppercase tracking-[0.16em] text-cyan-600">
                        UDG Sentinel
                    </span>
                    <h1 class="text-xl font-bold text-slate-900">Ingreso al sistema</h1>
                    <p class="mt-1 text-sm text-slate-600">Monitoreo en tiempo real de la infraestructura web institucional.</p>

                    @if ($errors->any())
                        <div
                            class="glass-panel mt-4 flex items-start gap-2 rounded-xl border-rose-400/30 bg-rose-500/10 px-3.5 py-3 text-sm text-rose-700"
                            role="alert"
                        >
                            <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.72-1.36 3.486 0l6.28 11.19c.75 1.334-.213 2.98-1.744 2.98H3.72c-1.53 0-2.493-1.646-1.744-2.98l6.28-11.19zM11 14a1 1 0 11-2 0 1 1 0 012 0zm-.25-6.5a.75.75 0 00-1.5 0v3.5a.75.75 0 001.5 0v-3.5z" clip-rule="evenodd"/>
                            </svg>
                            <span>{{ $errors->first() }}</span>
                        </div>
                    @endif

                    <form
                        id="sentinel-login-form"
                        method="POST"
                        action="{{ route('login.perform') }}"
                        autocomplete="off"
                        class="mt-6 grid gap-4"
                    >
                        @csrf

                        {{-- Honeypot anti-bot: campos señuelo invisibles para humanos, atractivos para bots de autofill --}}
                        <input type="text" name="shadow_user" autocomplete="username" tabindex="-1" aria-hidden="true" style="position:absolute;left:-10000px;opacity:0;pointer-events:none;">
                        <input type="password" name="shadow_password" autocomplete="new-password" tabindex="-1" aria-hidden="true" style="position:absolute;left:-10000px;opacity:0;pointer-events:none;">

                        <div>
                            <label for="email" class="mb-1.5 block text-sm text-slate-700">Usuario / correo</label>
                            <input
                                id="email"
                                name="email"
                                type="text"
                                value="{{ old('email', $defaultUser) }}"
                                required
                                autofocus
                                autocomplete="off"
                                autocorrect="off"
                                autocapitalize="off"
                                spellcheck="false"
                                class="glass-input h-11 w-full rounded-xl px-3.5 text-sm text-slate-900 focus:border-cyan-400 focus:outline-none"
                            >
                        </div>

                        <div>
                            <label for="password" class="mb-1.5 block text-sm text-slate-700">Contraseña</label>
                            <div class="relative">
                                <input
                                    id="password"
                                    name="password"
                                    type="password"
                                    required
                                    autocomplete="off"
                                    autocorrect="off"
                                    autocapitalize="off"
                                    spellcheck="false"
                                    class="glass-input h-11 w-full rounded-xl px-3.5 pr-11 text-sm text-slate-900 focus:border-cyan-400 focus:outline-none"
                                >
                                <button
                                    type="button"
                                    id="toggle-password"
                                    class="absolute inset-y-0 right-0 flex w-11 items-center justify-center text-slate-500 hover:text-slate-700"
                                    aria-label="Mostrar contraseña"
                                    aria-pressed="false"
                                >
                                    <svg id="icon-eye-open" class="h-4.5 w-4.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path d="M10 3.5c-4.14 0-7.66 2.6-9 6.5 1.34 3.9 4.86 6.5 9 6.5s7.66-2.6 9-6.5c-1.34-3.9-4.86-6.5-9-6.5zm0 10.83A4.33 4.33 0 1110 5.67a4.33 4.33 0 010 8.66zm0-6.83a2.5 2.5 0 100 5 2.5 2.5 0 000-5z"/>
                                    </svg>
                                    <svg id="icon-eye-closed" class="hidden h-4.5 w-4.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path d="M3.28 2.22a.75.75 0 00-1.06 1.06l14.5 14.5a.75.75 0 101.06-1.06l-1.98-1.98c1.86-1.24 3.28-3.08 4.06-5.24-1.34-3.9-4.86-6.5-9-6.5-1.47 0-2.85.33-4.08.93L3.28 2.22zM10 5.67c.36 0 .7.05 1.03.14l1.4 1.4a2.5 2.5 0 01-3.29 3.29l1.4 1.4c.14.02.29.03.46.03a4.33 4.33 0 000-8.66l-.99.99c.32-.09.65-.13 1-.13zM3.02 4.08A9.77 9.77 0 001 10c1.34 3.9 4.86 6.5 9 6.5.9 0 1.77-.12 2.6-.35l-1.53-1.53a4.33 4.33 0 01-5.6-5.6L3.02 4.08z"/>
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <label for="remember" class="flex cursor-pointer items-center gap-2 text-sm text-slate-600">
                            <input id="remember" name="remember" type="checkbox" value="1" {{ old('remember') ? 'checked' : '' }} class="h-4 w-4 rounded border-slate-300 bg-white text-cyan-600 focus:ring-cyan-400/40">
                            Mantener sesión activa
                        </label>

                        <button
                            type="submit"
                            id="submit-button"
                            class="glass-btn mt-1 h-11 rounded-xl border-cyan-400/40 text-sm font-semibold text-white hover:border-cyan-300"
                            style="background: color-mix(in oklab, var(--color-udg-blue) 78%, transparent);"
                        >
                            Entrar al dashboard
                        </button>
                    </form>

                    <p class="mt-5 flex items-center justify-center gap-1.5 text-center text-xs text-slate-500">
                        <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M10 1a4 4 0 00-4 4v2H5a2 2 0 00-2 2v7a2 2 0 002 2h10a2 2 0 002-2v-7a2 2 0 00-2-2h-1V5a4 4 0 00-4-4zm2 6V5a2 2 0 10-4 0v2h4z" clip-rule="evenodd"/>
                        </svg>
                        Conexión cifrada · acceso restringido a personal autorizado
                    </p>

                    @if ($showLocalQuickAccess ?? false)
                        <div class="mt-4 border-t border-slate-900/10 pt-4 text-center">
                            <p class="mb-2 text-[0.7rem] uppercase tracking-wide text-slate-500">Solo entorno local</p>
                            <a
                                href="{{ route('monitoring.local-login') }}"
                                class="glass-btn inline-flex h-9 items-center justify-center rounded-lg border-slate-900/10 px-4 text-xs font-medium text-slate-600 hover:border-slate-900/25 hover:text-slate-900"
                            >
                                Acceso rápido de desarrollo
                            </a>
                        </div>
                    @endif
                </div>

                <p class="mt-6 text-center text-xs text-slate-500">
                    © {{ now()->year }} Universidad de Guadalajara — Coordinación General de Tecnologías Administrativas
                </p>
            </div>
        </section>
    </main>

    {{-- Script externo (no inline): permite un Content-Security-Policy sin
         'unsafe-inline' en script-src, que es lo que realmente bloquea la
         inyeccion de scripts de terceros. --}}
    <script src="{{ asset('js/quick-login.js') }}?v={{ filemtime(public_path('js/quick-login.js')) }}" defer></script>
</body>
</html>
