<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <style>
        @page { margin: 1cm 1.2cm; }
        body { font-family: 'Helvetica', 'Arial', sans-serif; color: #1e293b; line-height: 1.3; font-size: 9px; margin: 0; }
        .text-primary { color: #0284c7; }
        .text-success { color: #059669; }
        .text-danger { color: #dc2626; }
        .text-warning { color: #d97706; }
        .bg-main { background-color: #0f172a; color: white; }
        .header-table { width: 100%; border-bottom: 3px solid #0f172a; padding-bottom: 10px; margin-bottom: 20px; }
        .report-title { font-size: 18px; font-weight: bold; color: #0f172a; margin: 0; text-transform: uppercase; letter-spacing: -0.5px; }
        .report-subtitle { font-size: 9px; color: #64748b; font-weight: bold; }
        .kpi-container { width: 100%; margin-bottom: 20px; }
        .kpi-card { background-color: #f8fafc; border-left: 4px solid #cbd5e1; padding: 8px 12px; border-radius: 4px; }
        .kpi-label { font-size: 8px; color: #64748b; text-transform: uppercase; font-weight: bold; margin-bottom: 2px; }
        .kpi-value { font-size: 14px; font-weight: bold; color: #0f172a; }
        .border-blue { border-left-color: #0284c7; }
        .border-green { border-left-color: #059669; }
        .border-orange { border-left-color: #ea580c; }
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th { background-color: #1e293b; color: #ffffff; padding: 8px; text-transform: uppercase; font-size: 8px; text-align: left; }
        .data-table td { padding: 8px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        .data-table tr:nth-child(even) { background-color: #f8fafc; }
        .product-box { margin-bottom: 4px; padding-left: 5px; border-left: 2px solid #e2e8f0; }
        .product-name { font-weight: bold; color: #334155; font-size: 8.5px; }
        .product-meta { font-size: 8px; color: #64748b; }
        .badge { display: inline-block; padding: 2px 6px; background-color: #f1f5f9; color: #475569; border-radius: 10px; font-size: 7.5px; font-weight: bold; margin-bottom: 2px; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .text-bold { font-weight: bold; }
    </style>
    <title>Reporte de Traspasos</title>
</head>
<body>

<table class="header-table">
    <tr>
        <td>
            <div class="report-title">XISPOS <span class="text-primary">WEB</span></div>
            <div class="report-subtitle">Reporte de Traspasos entre Sucursales</div>
        </td>
        <td class="text-right">
            <div class="text-bold" style="font-size: 10px;">{{ $resumen['inicio'] }} - {{ $resumen['fin'] }}</div>
            <div style="color: #64748b;">Generado : {{ now()->format('d/m/Y h:i A') }}</div>
        </td>
    </tr>
</table>

<table class="kpi-container" cellspacing="6">
    <tr>
        <td width="25%">
            <div class="kpi-card border-blue">
                <div class="kpi-label">Traspasos Registrados</div>
                <div class="kpi-value">{{ $resumen['total_traspasos'] }}</div>
            </div>
        </td>
        <td width="25%">
            <div class="kpi-card border-orange">
                <div class="kpi-label">Artículos Transferidos</div>
                <div class="kpi-value">{{ number_format($resumen['total_articulos'], 2) }}</div>
            </div>
        </td>
        <td width="25%">
            <div class="kpi-card border-blue">
                <div class="kpi-label">Traspasos Pendientes</div>
                <div class="kpi-value">{{ $resumen['pendientes'] }}</div>
            </div>
        </td>
        <td width="25%">
            <div class="kpi-card border-green" style="background-color: #ecfdf5;">
                <div class="kpi-label" style="color: #047857;">Traspasos Completados</div>
                <div class="kpi-value" style="color: #047857;">{{ $resumen['completados'] }}</div>
            </div>
        </td>
    </tr>
</table>

<table class="data-table">
    <thead>
    <tr>
        <th width="15%">Folio / Envío</th>
        <th width="20%">Origen -> Destino</th>
        <th width="15%">Estado / Recepción</th>
        <th width="35%">Artículos Enviados (Diferencias)</th>
        <th width="15%" class="text-right">Total Uds.</th>
    </tr>
    </thead>
    <tbody>
    @foreach($traspasos as $t)
        <tr>
            <td>
                <div class="text-bold text-primary" style="font-size: 10px;">#{{ $t->id }}</div>
                <div style="color: #64748b;">{{ \Carbon\Carbon::parse($t->fecha_envio)->format('d/m/Y') }}</div>
                <div style="font-size: 8px;">Por: {{ $t->userEnvia->name ?? 'N/A' }}</div>
            </td>
            <td>
                <div class="text-bold" style="font-size: 8.5px;">{{ $t->sucursalOrigen->nombre ?? 'N/A' }}</div>
                <div style="color: #64748b; font-size: 8px; margin: 2px 0;">&#8595; hacia &#8595;</div>
                <div class="text-bold" style="font-size: 8.5px;">{{ $t->sucursalDestino->nombre ?? 'N/A' }}</div>
            </td>
            <td>
                <div class="badge" style="background-color: {{ $t->estatus === 'Recibido' ? '#dcfce7' : '#ffedd5' }}; color: {{ $t->estatus === 'Recibido' ? '#166534' : '#c2410c' }};">
                    {{ strtoupper($t->estatus) }}
                </div>
                @if($t->fecha_recepcion)
                    <div style="color: #64748b; font-size: 8px; margin-top: 2px;">{{ \Carbon\Carbon::parse($t->fecha_recepcion)->format('d/m/Y') }}</div>
                    <div style="font-size: 8px;">Por: {{ $t->userRecibe->name ?? 'N/A' }}</div>
                @endif
            </td>
            <td>
                @foreach($t->detalles as $det)
                    <div class="product-box">
                        <div class="product-name">{{ $det->producto->nombre ?? 'Desconocido' }}</div>
                        <div class="product-meta">
                            Enviado: {{ number_format($det->cantidad_enviada, 2) }}
                            @if($t->estatus === 'Recibido')
                                | Recibido: {{ number_format($det->cantidad_recibida, 2) }}
                                @if($det->cantidad_enviada != $det->cantidad_recibida)
                                    <span class="text-danger text-bold"> (Dif: {{ number_format($det->cantidad_enviada - $det->cantidad_recibida, 2) }})</span>
                                @endif
                            @endif
                        </div>
                    </div>
                @endforeach
            </td>
            <td class="text-right">
                <div class="text-bold" style="font-size: 11px;">{{ number_format($t->detalles->sum('cantidad_enviada'), 2) }}</div>
            </td>
        </tr>
    @endforeach
    </tbody>
</table>

<div style="position: fixed; bottom: -10px; left: 0; right: 0; text-align: center; font-size: 7px; color: #94a3b8; border-top: 1px solid #f1f5f9; padding-top: 5px;">
    XISPOS WEB 3.0 - Panel de Control Administrativo.
</div>

</body>
</html>
