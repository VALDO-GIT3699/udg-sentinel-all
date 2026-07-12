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

        h1, h2, p { margin-top: 0; }
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
        .inline-meta { color: var(--muted); font-size: 12px; }

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
                <h1>Reporte operativo de Monitoring</h1>
                <p class="meta">Resumen fiel al panel actual: inventario, vencimientos preventivos y estados de diagnóstico.</p>
            </div>
            <div class="meta">
                <p><strong>Generado:</strong> {{ $generated_at }}</p>
                <p><strong>Sitios monitoreados:</strong> {{ array_sum($status_counts) }}</p>
            </div>
        </header>

        <section class="grid kpis">
            <article class="card">
                <div class="kpi-label">Operativos</div>
                <div class="kpi-value">{{ (int) ($status_counts['up'] ?? 0) }}</div>
                <div class="kpi-footnote">Sitios con respuesta estable.</div>
            </article>
            <article class="card">
                <div class="kpi-label">Con incidencias</div>
                <div class="kpi-value">{{ (int) ($status_counts['degraded'] ?? 0) }}</div>
                <div class="kpi-footnote">Sitios con degradación o errores parciales.</div>
            </article>
            <article class="card">
                <div class="kpi-label">No responde</div>
                <div class="kpi-value">{{ (int) ($status_counts['down'] ?? 0) }}</div>
                <div class="kpi-footnote">Sitios sin respuesta válida.</div>
            </article>
            <article class="card">
                <div class="kpi-label">Sin actualizar</div>
                <div class="kpi-value">{{ (int) ($status_counts['unknown'] ?? 0) }}</div>
                <div class="kpi-footnote">Sitios en proceso o sin medición reciente.</div>
            </article>
        </section>

        <section class="section grid two">
            <article class="card">
                <h2>Panorama de diagnóstico</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Clasificación</th>
                            <th>Conteo</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($diagnostic_breakdown as $label => $count)
                            <tr>
                                <td>{{ ucwords(str_replace('_', ' ', $label)) }}</td>
                                <td>{{ (int) $count }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </article>

            <article class="card">
                <h2>Notas de operación</h2>
                <p class="note">Este PDF refleja el mismo inventario y los mismos vencimientos que ves en el dashboard, sin métricas ajenas al módulo de Monitoring.</p>
                <p class="note">Secciones incluidas: estado general, certificados a renovar, últimos escaneos y muestra del inventario monitoreado.</p>
            </article>
        </section>

        <section class="section card">
            <h2>Calendario de vencimientos preventivos</h2>
            @if (empty($preventive_expirations))
                <p class="note">No hay certificados que expiren dentro de la ventana configurada.</p>
            @else
                <table>
                    <thead>
                        <tr>
                            <th>Sitio</th>
                            <th>Dominio</th>
                            <th>Vence</th>
                            <th>Días restantes</th>
                            <th>Emisor</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($preventive_expirations as $item)
                            <tr>
                                <td><strong>{{ $item['site_name'] }}</strong></td>
                                <td>{{ $item['domain'] }}</td>
                                <td>{{ $item['valid_until'] ? \Illuminate\Support\Carbon::parse($item['valid_until'])->format('d/m/Y H:i') : 'Sin fecha' }}</td>
                                <td>{{ (int) $item['days_remaining'] }}</td>
                                <td>{{ $item['issuer'] !== '' ? $item['issuer'] : 'Sin emisor' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>

        <section class="section card">
            <h2>Inventario monitoreado</h2>
            @if (empty($sites))
                <p class="note">No hay sitios disponibles para mostrar.</p>
            @else
                <table>
                    <thead>
                        <tr>
                            <th>Sitio</th>
                            <th>Dominio</th>
                            <th>Tecnología</th>
                            <th>Certificado</th>
                            <th>Estado</th>
                            <th>Diagnóstico</th>
                            <th>Último check</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sites as $site)
                            <tr>
                                <td><strong>{{ $site['name'] }}</strong></td>
                                <td>{{ $site['domain'] }}</td>
                                <td>{{ $site['technology'] }}</td>
                                <td>{{ $site['certificate'] }}</td>
                                <td><span class="badge {{ $site['status'] === 'down' ? 'critical' : ($site['status'] === 'degraded' ? 'high' : 'low') }}">{{ strtoupper($site['status_label']) }}</span></td>
                                <td>
                                    <strong>{{ $site['diagnostic_label'] }}</strong><br>
                                    <span class="meta">{{ $site['diagnostic_reason'] }}</span>
                                </td>
                                <td>{{ $site['last_checked_at'] ? \Illuminate\Support\Carbon::parse($site['last_checked_at'])->format('d/m/Y H:i') : 'Sin dato' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>

        <section class="section card">
            <h2>Últimos escaneos masivos</h2>
            @if (empty($recent_runs))
                <p class="note">Todavía no hay ejecuciones registradas.</p>
            @else
                <table>
                    <thead>
                        <tr>
                            <th>Inicio</th>
                            <th>Modo</th>
                            <th>Estado</th>
                            <th>Avance</th>
                            <th>Finalización</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($recent_runs as $run)
                            <tr>
                                <td>{{ $run['started_at'] ? \Illuminate\Support\Carbon::parse($run['started_at'])->format('d/m/Y H:i') : 'Sin dato' }}</td>
                                <td>{{ $run['trigger_mode'] === 'manual' ? 'Manual' : 'Programado' }}</td>
                                <td>{{ $run['status'] }}</td>
                                <td>{{ (int) $run['completed_tasks'] }}/{{ (int) $run['total_tasks'] }} · fallos {{ (int) $run['failed_tasks'] }}</td>
                                <td>{{ $run['completed_at'] ? \Illuminate\Support\Carbon::parse($run['completed_at'])->format('d/m/Y H:i') : 'En curso' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>
    </main>
</body>
</html>
