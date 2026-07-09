<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ingreso Seguro - UDG Sentinel</title>
    <style>
        :root {
            --bg: #0f172a;
            --panel: #111827;
            --line: #334155;
            --accent: #06b6d4;
            --text: #e2e8f0;
            --muted: #94a3b8;
            --danger: #fb7185;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            background: radial-gradient(circle at top, #1e293b 0%, var(--bg) 60%);
            color: var(--text);
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            padding: 1rem;
        }
        .card {
            width: min(440px, 100%);
            border: 1px solid var(--line);
            border-radius: 14px;
            background: #111827;
            padding: 1.25rem;
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.35);
        }
        .brand {
            display: inline-block;
            font-size: 0.72rem;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            color: #67e8f9;
            margin-bottom: 0.4rem;
            font-weight: 700;
        }
        h1 { margin: 0; font-size: 1.4rem; }
        p { color: var(--muted); margin: 0.5rem 0 0; }
        form { margin-top: 1rem; display: grid; gap: 0.8rem; }
        label { font-size: 0.85rem; color: var(--muted); }
        input {
            width: 100%;
            border: 1px solid var(--line);
            background: #020617;
            color: var(--text);
            border-radius: 10px;
            padding: 0.65rem 0.75rem;
        }
        button {
            border: none;
            border-radius: 10px;
            background: var(--accent);
            color: #082f49;
            font-weight: 700;
            padding: 0.7rem 0.9rem;
            cursor: pointer;
        }
        .error {
            color: var(--danger);
            font-size: 0.85rem;
            margin-top: 0.2rem;
        }
        .hint {
            margin-top: 0.9rem;
            font-size: 0.8rem;
            color: var(--muted);
        }
    </style>
</head>
<body>
    <main class="card">
        <span class="brand">UDG Sentinel</span>
        <h1>Ingreso</h1>
        <p>Inicia sesión para acceder al dashboard.</p>

        @if ($errors->any())
            <p class="error">{{ $errors->first() }}</p>
        @endif

        <form method="POST" action="{{ route('login.perform') }}" autocomplete="off" novalidate>
            @csrf
            <input type="text" name="shadow_user" autocomplete="username" tabindex="-1" aria-hidden="true" style="position:absolute;left:-10000px;opacity:0;pointer-events:none;" />
            <input type="password" name="shadow_password" autocomplete="new-password" tabindex="-1" aria-hidden="true" style="position:absolute;left:-10000px;opacity:0;pointer-events:none;" />
            <div>
                <label for="email">Usuario / Email</label>
                <input id="email" name="email" type="text" value="{{ old('email', $defaultUser) }}" required autofocus autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false">
            </div>
            <div>
                <label for="password">Contraseña</label>
                <input id="password" name="password" type="password" required autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false">
            </div>
            <div>
                <label for="remember">
                    <input id="remember" name="remember" type="checkbox" value="1" {{ old('remember') ? 'checked' : '' }}>
                    Mantener sesión activa
                </label>
            </div>
            <button type="submit">Entrar al dashboard</button>
        </form>

        @if (app()->environment('local'))
            <p class="hint">En entorno local se permite auto-login controlado en /monitoring/local-autologin.</p>
        @endif
    </main>
</body>
</html>
