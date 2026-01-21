<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <style>
        @page { margin: 1cm 1.2cm; }
        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            color: #1e293b;
            line-height: 1.3;
            font-size: 9px;
            margin: 0;
        }

        /* Colores Gerenciales */
        .text-primary { color: #0284c7; }
        .text-success { color: #059669; }
        .text-danger { color: #dc2626; }
        .bg-main { background-color: #0f172a; color: white; }

        /* Encabezado Premium */
        .header-table { width: 100%; border-bottom: 3px solid #0f172a; padding-bottom: 10px; margin-bottom: 20px; }
        .report-title { font-size: 18px; font-weight: bold; color: #0f172a; margin: 0; text-transform: uppercase; letter-spacing: -0.5px; }
        .report-subtitle { font-size: 9px; color: #64748b; font-weight: bold; }

        /* KPI Cards (Resumen Ejecutivo) */
        .kpi-container { width: 100%; margin-bottom: 20px; }
        .kpi-card {
            background-color: #f8fafc;
            border-left: 4px solid #cbd5e1;
            padding: 8px 12px;
            border-radius: 4px;
        }
        .kpi-label { font-size: 8px; color: #64748b; text-transform: uppercase; font-weight: bold; margin-bottom: 2px; }
        .kpi-value { font-size: 14px; font-weight: bold; color: #0f172a; }
        .border-blue { border-left-color: #0284c7; }
        .border-green { border-left-color: #059669; }
        .border-red { border-left-color: #dc2626; }

        /* Tabla de Datos Principal */
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th {
            background-color: #1e293b;
            color: #ffffff;
            padding: 8px;
            text-transform: uppercase;
            font-size: 8px;
            text-align: left;
        }
        .data-table td { padding: 8px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        .data-table tr:nth-child(even) { background-color: #f8fafc; }

        /* Estilos Detalle Producto */
        .product-box { margin-bottom: 4px; padding-left: 5px; border-left: 2px solid #e2e8f0; }
        .product-name { font-weight: bold; color: #334155; font-size: 8.5px; }
        .product-meta { font-size: 8px; color: #64748b; }

        /* Badges de Pago */
        .payment-badge {
            display: inline-block;
            padding: 2px 6px;
            background-color: #f1f5f9;
            color: #475569;
            border-radius: 10px;
            font-size: 7.5px;
            font-weight: bold;
            margin-bottom: 2px;
        }

        .text-right { text-align: right; }
        .text-bold { font-weight: bold; }
    </style>
    <title>Reporte de ventas detalladas</title>
</head>
<body>

<table class="header-table">
    <tr>
        <td>
            <div class="report-title">XISPOS <span class="text-primary">WEB</span></div>
            <div class="report-subtitle">Reporte Gerencial de Ventas Detalladas</div>
        </td>
        <td class="text-right">
            <div class="text-bold" style="font-size: 10px;">{{ $resumen['inicio'] }} — {{ $resumen['fin'] }}</div>
            <div style="color: #64748b;">Generado : {{ now()->format('d/m/Y h:i A') }}</div>
        </td>
    </tr>
</table>

<table class="kpi-container" cellspacing="6">
    <tr>
        <td width="25%">
            <div class="kpi-card border-blue">
                <div class="kpi-label">Ventas Realizadas</div>
                <div class="kpi-value">{{ $resumen['conteo'] }} <small style="font-size: 9px; color: #94a3b8;">Tickets</small></div>
            </div>
        </td>
        <td width="25%">
            <div class="kpi-card">
                <div class="kpi-label">Subtotal Neto</div>
                <div class="kpi-value">${{ number_format($resumen['neto'], 2) }}</div>
            </div>
        </td>
        <td width="25%">
            <div class="kpi-card border-red">
                <div class="kpi-label">Impuestos (IVA)</div>
                <div class="kpi-value">${{ number_format($resumen['taxes'], 2) }}</div>
            </div>
        </td>
        <td width="25%">
            <div class="kpi-card border-green" style="background-color: #ecfdf5;">
                <div class="kpi-label" style="color: #047857;">Ingreso Total Bruto</div>
                <div class="kpi-value" style="color: #047857;">${{ number_format($resumen['total'], 2) }}</div>
            </div>
        </td>
    </tr>
</table>

<table class="data-table">
    <thead>
    <tr>
        <th width="15%">Folio / Fecha</th>
        <th width="20%">Cliente / Sucursal</th>
        <th width="35%">Desglose de Artículos</th>
        <th width="15%" class="text-right">Métodos de Pago</th>
        <th width="15%" class="text-right">Total Final</th>
    </tr>
    </thead>
    <tbody>
    @foreach($ventas as $venta)
        <tr>
            <td>
                <div class="text-bold text-primary" style="font-size: 10px;">{{ $venta->folio }}</div>
                <div style="color: #64748b;">{{ $venta->created_at->format('d/m/Y') }}</div>
                <div style="font-size: 8px;">{{ $venta->created_at->format('h:i A') }}</div>
            </td>
            <td>
                <div class="text-bold">{{ $venta->cliente->nombre ?? 'PÚBLICO GENERAL' }}</div>
                <div style="color: #64748b; font-size: 8px;">{{ $venta->sucursal->nombre }}</div>
            </td>
            <td>
                @foreach($venta->detalles as $det)
                    <div class="product-box">
                        <div class="product-name">{{ $det->producto->nombre }}</div>
                        <div class="product-meta">
                            {{ number_format($det->cantidad, 2) }} uds x ${{ number_format($det->precio_unitario, 2) }}
                            <span style="float: right;" class="text-bold">${{ number_format($det->total, 2) }}</span>
                        </div>
                    </div>
                @endforeach
            </td>
            <td class="text-right">
                @foreach($venta->pagos as $pago)
                    <div class="payment-badge">
                        {{ strtoupper($pago->metodo_pago) }}
                    </div><br>
                    <small class="text-bold" style="color: #059669;">${{ number_format($pago->monto, 2) }}</small>
                @endforeach
            </td>
            <td class="text-right">
                <div class="text-bold" style="font-size: 11px;">${{ number_format($venta->total, 2) }}</div>
                <div style="font-size: 7px; color: #94a3b8;">Impuestos: ${{ number_format($venta->impuestos, 2) }}</div>
            </td>
        </tr>
    @endforeach
    </tbody>
</table>

<div style="position: fixed; bottom: -10px; left: 0; right: 0; text-align: center; font-size: 7px; color: #94a3b8; border-top: 1px solid #f1f5f9; padding-top: 5px;">
    Este reporte utiliza precisión contable de 6 decimales para cálculos internos.
    XISPOS WEB 3.0 — Panel de Control Administrativo.
</div>

</body>
</html>
