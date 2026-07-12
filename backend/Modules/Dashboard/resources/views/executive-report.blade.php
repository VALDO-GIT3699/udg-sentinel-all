<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reporte ejecutivo UDG Sentinel</title>
    <style>
        :root {
            color-scheme: light;
            --bg: #f8fafc;
            --card: #ffffff;
            --text: #0f172a;
            --muted: #475569;
            --line: #dbe2ea;
            --accent: #0f766e;
            --accent-soft: #ccfbf1;
            --warn: #b45309;
            --danger: #b91c1c;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            background: var(--bg);
            color: var(--text);
        }

        .page {
            max-width: 1120px;
            margin: 0 auto;
            padding: 32px 24px 48px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            gap: 24px;
            align-items: flex-end;
            border-bottom: 2px solid var(--line);
            padding-bottom: 18px;
            margin-bottom: 24px;
        }

        .eyebrow {
            font-size: 12px;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: var(--accent);
            margin: 0 0 8px;
            font-weight: 700;
        }

        h1, h2, h3, p { margin-top: 0; }

        h1 { font-size: 32px; margin-bottom: 6px; }
        h2 { font-size: 20px; margin-bottom: 14px; }

        .meta { color: var(--muted); font-size: 13px; }

        .grid {
            display: grid;
            gap: 16px;
        }

        .grid.kpis { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        .grid.two { grid-template-columns: repeat(2, minmax(0, 1fr)); }

        .card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 18px;
            padding: 18px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.04);
        }

        .kpi-label {
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-size: 12px;
            font-weight: 700;
        }

        .kpi-value {
            font-size: 32px;
            font-weight: 700;
            margin-top: 8px;
        }

        .kpi-footnote {
            color: var(--muted);
            font-size: 12px;
            margin-top: 6px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            text-align: left;
            padding: 10px 8px;
            border-bottom: 1px solid var(--line);
            font-size: 14px;
            vertical-align: top;
        }

        th {
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-size: 11px;
        }

        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
        }

        .badge.critical { background: #fee2e2; color: var(--danger); }
        .badge.high { background: #ffedd5; color: var(--warn); }
        .badge.medium { background: #fef3c7; color: #92400e; }
        .badge.low { background: #dbeafe; color: #1d4ed8; }
        .badge.other { background: #e2e8f0; color: #334155; }

        .section { margin-top: 20px; }
        .note { color: var(--muted); font-size: 13px; line-height: 1.5; }

        @media print {
            body { background: white; }
            .page { padding: 0; max-width: none; }
            .card { box-shadow: none; }
        }

        @media (max-width: 960px) {
            .grid.kpis, .grid.two { grid-template-columns: 1fr 1fr; }
        }

        @media (max-width: 640px) {
            .header { grid-template-columns: 1fr; display: grid; }
            .grid.kpis, .grid.two { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <main class="page">
        <header class="header">
            <div>
                <p class="eyebrow">UDG Sentinel</p>
                <h1>Reporte ejecutivo</h1>
                <p class="meta">Resumen para dirección listo para imprimir o guardar como PDF desde el navegador.</p>
            </div>
            <div class="meta">
                <p><strong>Generado:</strong> {{ $generated_at }}</p>
                <p><strong>Disponibilidad última semana:</strong> {{ number_format((float) $uptime_week_pct, 2) }}%</p>
            </div>
        </header>

        <section class="grid kpis">
            <article class="card">
                <div class="kpi-label">Disponibilidad 7 días</div>
                <div class="kpi-value">{{ number_format((float) $uptime_week_pct, 2) }}%</div>
                <div class="kpi-footnote">Promedio del parque monitoreado.</div>
            </article>
            <article class="card">
                <div class="kpi-label">Disponibilidad 30 días</div>
                <div class="kpi-value">{{ number_format((float) $uptime_month_pct, 2) }}%</div>
                <div class="kpi-footnote">Base comparativa para seguimiento mensual.</div>
            </article>
            <article class="card">
                <div class="kpi-label">Tiempo de respuesta</div>
                <div class="kpi-value">{{ $avg_response_time_week_ms !== null ? number_format((float) $avg_response_time_week_ms, 0) . ' ms' : 'Sin datos' }}</div>
                <div class="kpi-footnote">Promedio de las últimas 7 días.</div>
            </article>
            <article class="card">
                <div class="kpi-label">Tecnologías obsoletas</div>
                <div class="kpi-value">{{ count($obsolete_technologies) }}</div>
                <div class="kpi-footnote">Detectadas en la última recolección.</div>
            </article>
        </section>

        <section class="section grid two">
            <article class="card">
                <h2>Top de alertas activas</h2>
                @if (empty($top_alerts))
                    <p class="note">No hay alertas activas en la ventana actual.</p>
                @else
                    <table>
                        <thead>
                            <tr>
                                <th>Sitio</th>
                                <th>Alerta</th>
                                <th>Severidad</th>
                                <th>Fecha</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($top_alerts as $alert)
                                <tr>
                                    <td>
                                        <strong>{{ $alert['site']['name'] ?? 'Sin sitio' }}</strong><br>
                                        <span class="meta">{{ $alert['site']['domain'] ?? '' }}</span>
                                    </td>
                                    <td>{{ $alert['title'] }}</td>
                                    <td><span class="badge {{ strtolower((string) $alert['severity']) }}">{{ strtoupper((string) $alert['severity']) }}</span></td>
                                    <td>{{ $alert['triggered_at'] ? \Illuminate\Support\Carbon::parse($alert['triggered_at'])->format('d/m/Y H:i') : 'Sin fecha' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </article>

            <article class="card">
                <h2>Tecnologías obsoletas detectadas</h2>
                @if (empty($obsolete_technologies))
                    <p class="note">No se detectaron tecnologías obsoletas con la heurística operativa actual.</p>
                @else
                    <table>
                        <thead>
                            <tr>
                                <th>Sitio</th>
                                <th>Tecnología</th>
                                <th>Categoría</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($obsolete_technologies as $item)
                                <tr>
                                    <td>
                                        <strong>{{ $item['site']['name'] ?? 'Sin sitio' }}</strong><br>
                                        <span class="meta">{{ $item['site']['domain'] ?? '' }}</span>
                                    </td>
                                    <td>{{ $item['technology']['display_name'] ?? $item['technology']['name'] ?? 'No identificada' }}</td>
                                    <td>{{ $item['technology']['category_label'] ?? $item['technology']['category'] ?? 'Otro' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </article>
        </section>

        <section class="section card">
            <h2>Sitios con atención prioritaria</h2>
            @if (empty($critical_sites))
                <p class="note">No hay sitios en estado degradado o caído dentro de esta ventana.</p>
            @else
                <table>
                    <thead>
                        <tr>
                            <th>Sitio</th>
                            <th>Dominio</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($critical_sites as $site)
                            <tr>
                                <td>{{ $site['name'] }}</td>
                                <td>{{ $site['domain'] }}</td>
                                <td><span class="badge {{ strtolower((string) $site['status']) }}">{{ strtoupper((string) $site['status']) }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>
    </main>
</body>
</html>