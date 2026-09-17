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
            font-size: 10px;
            margin: 0;
        }

        .text-primary { color: #0284c7; }
        .text-success { color: #059669; }
        .bg-main { background-color: #0f172a; color: white; }

        .header-table { width: 100%; border-bottom: 3px solid #0f172a; padding-bottom: 10px; margin-bottom: 20px; }
        .report-title { font-size: 18px; font-weight: bold; color: #0f172a; margin: 0; text-transform: uppercase; letter-spacing: -0.5px; }
        .report-subtitle { font-size: 10px; color: #64748b; font-weight: bold; }

        .kpi-container { width: 100%; margin-bottom: 20px; }
        .kpi-card {
            background-color: #f8fafc;
            border-left: 4px solid #cbd5e1;
            padding: 10px 12px;
            border-radius: 4px;
        }
        .kpi-label { font-size: 9px; color: #64748b; text-transform: uppercase; font-weight: bold; margin-bottom: 2px; }
        .kpi-value { font-size: 16px; font-weight: bold; color: #0f172a; }
        .border-blue { border-left-color: #0284c7; }
        .border-green { border-left-color: #059669; }
        .border-purple { border-left-color: #8b5cf6; }

        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th {
            background-color: #1e293b;
            color: #ffffff;
            padding: 8px;
            text-transform: uppercase;
            font-size: 9px;
            text-align: left;
        }
        .data-table td { padding: 8px; border-bottom: 1px solid #e2e8f0; vertical-align: middle; }
        .data-table tr:nth-child(even) { background-color: #f8fafc; }

        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .text-bold { font-weight: bold; }
        
        .badge {
            background-color: #e2e8f0;
            color: #334155;
            padding: 3px 6px;
            border-radius: 4px;
            font-size: 8px;
            font-weight: bold;
        }
    </style>
    <title>Reporte de Ventas por Producto</title>
</head>
<body>

<table class="header-table">
    <tr>
        <td>
            <div class="report-title">XISPOS <span class="text-primary">WEB</span></div>
            <div class="report-subtitle">Reporte Gerencial de Ventas por Producto</div>
        </td>
        <td class="text-right">
            <div class="text-bold" style="font-size: 10px;">{{ $resumen['inicio'] }} - {{ $resumen['fin'] }}</div>
            <div style="color: #64748b; font-size: 9px;">Generado: {{ now()->format('d/m/Y h:i A') }}</div>
        </td>
    </tr>
</table>

<table class="kpi-container" cellspacing="8">
    <tr>
        <td width="33%">
            <div class="kpi-card border-purple">
                <div class="kpi-label">Productos Únicos Vendidos</div>
                <div class="kpi-value">{{ count($reporte) }}</div>
            </div>
        </td>
        <td width="33%">
            <div class="kpi-card border-blue">
                <div class="kpi-label">Artículos Vendidos (Cantidad)</div>
                <div class="kpi-value">{{ number_format($resumen['total_vendido'], 2) }}</div>
            </div>
        </td>
        <td width="33%">
            <div class="kpi-card border-green" style="background-color: #ecfdf5;">
                <div class="kpi-label" style="color: #047857;">Ingresos Generados</div>
                <div class="kpi-value" style="color: #047857;">${{ number_format($resumen['total_ingresos'], 2) }}</div>
            </div>
        </td>
    </tr>
</table>

<table class="data-table">
    <thead>
    <tr>
        <th width="5%">#</th>
        <th width="15%">Código / SKU</th>
        <th width="35%">Nombre del Producto</th>
        <th width="15%">Categoría</th>
        <th width="15%" class="text-right">Cantidad Vendida</th>
        <th width="15%" class="text-right">Total Ingresos</th>
    </tr>
    </thead>
    <tbody>
    @php $index = 1; @endphp
    @foreach($reporte as $item)
        <tr>
            <td class="text-bold" style="color: #64748b;">{{ $index++ }}</td>
            <td class="text-bold" style="font-size: 9px;">{{ $item->codigo_barras }}</td>
            <td class="text-bold text-primary">{{ $item->producto_nombre }}</td>
            <td><span class="badge">{{ $item->categoria_nombre ?? 'SIN CATEGORÍA' }}</span></td>
            <td class="text-right text-bold" style="font-size: 11px;">
                {{ number_format($item->total_vendido, 2) }}
            </td>
            <td class="text-right text-bold text-success" style="font-size: 11px;">
                ${{ number_format($item->total_ingresos, 2) }}
            </td>
        </tr>
    @endforeach
    </tbody>
</table>

<div style="position: fixed; bottom: -10px; left: 0; right: 0; text-align: center; font-size: 8px; color: #94a3b8; border-top: 1px solid #f1f5f9; padding-top: 5px;">
    XISPOS WEB 3.0 - Resumen de Ventas por Producto. Ordenado por mayor volumen de ingresos.
</div>

</body>
</html>
