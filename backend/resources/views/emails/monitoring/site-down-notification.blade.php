<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Alerta critica de monitoreo</title>
</head>
<body style="margin:0;padding:24px;background:#f8fafc;font-family:Segoe UI,Arial,sans-serif;color:#0f172a;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:680px;margin:0 auto;background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;">
    <tr>
        <td style="padding:20px 24px;background:#dc2626;color:#ffffff;">
            <h1 style="margin:0;font-size:20px;line-height:1.4;font-weight:700;">Incidente critico detectado</h1>
            <p style="margin:8px 0 0 0;font-size:14px;opacity:0.95;">UDG Sentinel detecto una caida en un sitio prioritario.</p>
        </td>
    </tr>
    <tr>
        <td style="padding:24px;">
            <p style="margin:0 0 14px 0;font-size:14px;line-height:1.6;">
                <strong>Sitio:</strong> {{ $siteName }}<br>
                <strong>URL:</strong> <a href="{{ $siteUrl }}" style="color:#0369a1;text-decoration:none;">{{ $siteUrl }}</a><br>
                <strong>Severidad:</strong> {{ strtoupper($severity) }}<br>
                <strong>Estado anterior:</strong> {{ strtoupper($previousStatus) }}<br>
                <strong>Estado actual:</strong> {{ strtoupper($currentStatus) }}<br>
                <strong>Detectado en:</strong> {{ $detectedAtIso }}<br>
                <strong>ID de alerta:</strong> #{{ $alertId }}
            </p>

            <div style="margin:16px 0;padding:14px;border-radius:8px;background:#fff7ed;border:1px solid #fdba74;">
                <p style="margin:0;font-size:14px;line-height:1.6;">
                    <strong>Detalle tecnico:</strong><br>
                    {{ $message }}
                </p>
            </div>

            <p style="margin:0;font-size:13px;line-height:1.6;color:#475569;">
                Este correo se genero automaticamente por el flujo de evaluacion de estado de UDG Sentinel.
            </p>
        </td>
    </tr>
</table>
</body>
</html>
