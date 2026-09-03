<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Verificación en dos pasos · UDG Sentinel</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800&display=swap" rel="stylesheet">

    <link rel="shortcut icon" href="{{ asset('images/universidad-de-guadalajara-logo-png_seeklogo-617642.png') }}?v={{ time() }}">

    @vite(['resources/css/app.css'])
</head>
<body class="font-sans antialiased">
    <main class="relative min-h-screen overflow-x-hidden bg-sky-50 text-slate-900">
        <div class="pointer-events-none fixed inset-0 -z-10 overflow-hidden" aria-hidden="true">
            <div class="absolute -left-40 -top-48 h-[34rem] w-[34rem] rounded-full bg-sky-300/25 blur-[130px]"></div>
            <div class="absolute -right-32 top-1/4 h-[30rem] w-[30rem] rounded-full bg-blue-300/20 blur-[130px]"></div>
        </div>

        <section class="relative z-10 flex min-h-screen items-center justify-center px-4 py-10">
            <div class="w-full max-w-md">
                <div class="glass-panel rounded-2xl bg-white/70 p-6 sm:p-8">
                    <span class="mb-1 inline-block text-[0.68rem] font-bold uppercase tracking-[0.16em] text-cyan-600">
                        UDG Sentinel
                    </span>
                    <h1 class="text-xl font-bold text-slate-900">Verificación en dos pasos</h1>
                    <p class="mt-1 text-sm text-slate-600">
                        Ingresa el código de 6 dígitos de tu app autenticadora, o uno de tus códigos de recuperación.
                    </p>

                    @if ($errors->any())
                        <div class="glass-panel mt-4 flex items-start gap-2 rounded-xl border-rose-400/30 bg-rose-500/10 px-3.5 py-3 text-sm text-rose-700" role="alert">
                            <span>{{ $errors->first() }}</span>
                        </div>
                    @endif

                    <form method="POST" action="{{ route('two-factor.verify') }}" class="mt-6 grid gap-4">
                        @csrf

                        <div>
                            <label for="code" class="mb-1.5 block text-sm text-slate-700">Código</label>
                            <input
                                id="code"
                                name="code"
                                type="text"
                                inputmode="numeric"
                                autocomplete="one-time-code"
                                required
                                autofocus
                                placeholder="123456"
                                class="glass-input h-11 w-full rounded-xl px-3.5 text-center text-lg tracking-[0.3em] text-slate-900 focus:border-cyan-400 focus:outline-none"
                            >
                        </div>

                        <button
                            type="submit"
                            class="glass-btn mt-1 h-11 rounded-xl border-cyan-400/40 text-sm font-semibold text-white hover:border-cyan-300"
                            style="background: color-mix(in oklab, var(--color-udg-blue) 78%, transparent);"
                        >
                            Verificar
                        </button>
                    </form>

                    <p class="mt-5 text-center text-xs text-slate-500">
                        <a href="{{ route('login') }}" class="text-cyan-600 hover:text-cyan-700">Volver al inicio de sesión</a>
                    </p>
                </div>
            </div>
        </section>
    </main>
</body>
</html>
