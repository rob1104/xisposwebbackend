<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte de Cierre de Turno #{{ $turno->id }}</title>
    <style>
        @page { margin: 0cm 0cm; }
        body {
            font-family: 'Helvetica Neue', 'Helvetica', Helvetica, Arial, sans-serif;
            margin-top: 3cm;
            margin-bottom: 2cm;
            margin-left: 2cm;
            margin-right: 2cm;
            font-size: 10px;
            color: #334155; /* Slate 700 */
        }

        /* --- HEADER --- */
        header {
            position: fixed;
            top: 0cm;
            left: 0cm;
            right: 0cm;
            height: 2.5cm;
            background-color: #1e293b; /* Slate 800 */
            color: white;
            padding: 0 2cm;
            line-height: 2.5cm;
        }
        .header-title { float: left; font-size: 18px; font-weight: bold; text-transform: uppercase; }
        .header-meta { float: right; font-size: 10px; text-align: right; line-height: 1.2; margin-top: 0.8cm; }

        /* --- FOOTER --- */
        footer {
            position: fixed;
            bottom: 0cm;
            left: 0cm;
            right: 0cm;
            height: 1.5cm;
            background-color: #f1f5f9;
            text-align: center;
            line-height: 1.5cm;
            font-size: 9px;
            color: #64748b;
            border-top: 1px solid #e2e8f0;
        }

        /* --- ESTILOS GENERALES --- */
        h3 {
            border-bottom: 2px solid #334155;
            padding-bottom: 5px;
            margin-top: 25px;
            margin-bottom: 10px;
            color: #1e293b;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* --- KPI CARDS (RESUMEN FINANCIERO) --- */
        .kpi-container { width: 100%; display: table; margin-bottom: 20px; border-collapse: separate; border-spacing: 10px 0; margin-left: -10px; }
        .kpi-box {
            display: table-cell;
            width: 25%;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            padding: 10px;
            text-align: center;
        }
        .kpi-label { font-size: 8px; text-transform: uppercase; color: #64748b; font-weight: bold; letter-spacing: 0.5px; }
        .kpi-value { font-size: 14px; font-weight: bold; color: #0f172a; margin-top: 5px; }
        .kpi-highlight { background-color: #e0f2fe; border-color: #bae6fd; } /* Azul claro para el total final */
        .text-green { color: #16a34a; }
        .text-red { color: #dc2626; }

        /* --- TABLAS ELEGANTES --- */
        table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        th {
            background-color: #f1f5f9;
            color: #475569;
            font-weight: bold;
            text-transform: uppercase;
            font-size: 8px;
            padding: 8px;
            text-align: left;
            border-bottom: 1px solid #cbd5e1;
        }
        td {
            padding: 6px 8px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 9px;
        }
        tr:nth-child(even) { background-color: #fcfcfc; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .font-mono { font-family: 'Courier New', Courier, monospace; }
        .total-row td { border-top: 2px solid #cbd5e1; font-weight: bold; background-color: white; }

        /* --- ARQUEO --- */
        .denominaciones-grid { width: 100%; margin-top: 10px; }
        .denominacion-item {
            display: inline-block;
            width: 18%;
            border: 1px solid #e2e8f0;
            background: #fff;
            padding: 5px;
            margin-right: 1%;
            margin-bottom: 5px;
            text-align: center;
            border-radius: 4px;
        }
        .bill-label { font-size: 7px; color: #94a3b8; text-transform: uppercase; }
        .bill-val { font-size: 10px; font-weight: bold; color: #334155; }

        /* --- FIRMAS --- */
        .signatures { margin-top: 50px; width: 100%; }
        .sig-box { width: 40%; display: inline-block; text-align: center; border-top: 1px solid #94a3b8; padding-top: 5px; }
    </style>
</head>
<body>

<header>
    <div class="header-title">Reporte de Cierre de Turno #{{ $turno->id }} - CORTE DE CAJA</div>
    <div class="header-meta">
        SUCURSAL: {{ strtoupper($turno->sucursal->nombre) }}<br>
        TURNO ID: #{{ str_pad($turno->id, 6, '0', STR_PAD_LEFT) }}<br>
        FECHA: {{ $turno->created_at->format('d/m/Y h:i A') }}
    </div>
</header>

<footer>
    Sistema XisPOS - Generado el {{ date('d/m/Y H:i:s') }} - Página <span class="pagenum"></span>
</footer>

<div class="kpi-container">
    <div class="kpi-box">
        <div class="kpi-label">Fondo Inicial</div>
        <div class="kpi-value">${{ number_format($turno->saldo_inicial, 2) }}</div>
    </div>
    <div class="kpi-box">
        <div class="kpi-label">Ventas Efectivo</div>
        <div class="kpi-value">${{ number_format($resumen['ventas_efectivo'], 2) }}</div>
    </div>
    <div class="kpi-box">
        <div class="kpi-label">Entradas / Retiros</div>
        @php $netoMovs = $resumen['total_entradas'] - $resumen['total_retiros']; @endphp
        <div class="kpi-value {{ $netoMovs < 0 ? 'text-red' : 'text-green' }}">
            {{ $netoMovs >= 0 ? '+' : '' }}${{ number_format($netoMovs, 2) }}
        </div>
    </div>
    <div class="kpi-box kpi-highlight">
        <div class="kpi-label">Total en Caja</div>
        <div class="kpi-value" style="color: #0284c7;">${{ number_format($turno->saldo_cierre, 2) }}</div>
    </div>
</div>

<h3>Productos Vendidos</h3>
<table>
    <thead>
    <tr>
        <th width="15%">CÓDIGO</th>
        <th width="45%">PRODUCTO</th>
        <th width="20%" class="text-center">CANTIDAD</th>
        <th width="20%" class="text-right">TOTAL VENDIDO</th>
    </tr>
    </thead>
    <tbody>
    @foreach($productosVendidos as $prod)
        <tr>
            <td class="font-mono text-center">{{ $prod->codigo_barras ?? '---' }}</td>
            <td>{{ $prod->nombre }}</td>
            <td class="text-center">{{ floatval($prod->cantidad_total) }}</td>
            <td class="text-right">${{ number_format($prod->dinero_total, 2) }}</td>
        </tr>
    @endforeach
    @if($productosVendidos->isEmpty())
        <tr><td colspan="4" class="text-center" style="color: #94a3b8; padding: 20px;">No hubo ventas de productos en este turno.</td></tr>
    @endif
    </tbody>
</table>

<h3>Ventas</h3>
<table>
    <thead>
    <tr>
        <th width="15%">FOLIO</th>
        <th width="15%">HORA</th>
        <th width="35%">CLIENTE</th>
        <th width="15%">TIPO PAGO</th>
        <th width="20%" class="text-right">TOTAL</th>
    </tr>
    </thead>
    <tbody>
    @foreach($listadoVentas as $venta)
        <tr>
            <td class="font-mono">{{ $venta->folio }}</td>
            <td>{{ $venta->created_at->format('H:i:s') }}</td>
            <td>{{ $venta->cliente ? $venta->cliente->nombre_comercial : 'Público General' }}</td>
            <td>
                        <span style="
                            padding: 2px 4px; border-radius: 3px; font-size: 7px; font-weight: bold;
                            background-color: {{ $venta->tipo_pago == 'Contado' ? '#dcfce7' : '#ffedd5' }};
                            color: {{ $venta->tipo_pago == 'Contado' ? '#166534' : '#9a3412' }};">
                            {{ strtoupper($venta->tipo_pago) }}
                        </span>
            </td>
            <td class="text-right">${{ number_format($venta->total, 2) }}</td>
        </tr>
    @endforeach
    </tbody>
    <tfoot>
    <tr class="total-row">
        <td colspan="4" class="text-right">TOTAL VENTAS TURNO:</td>
        <td class="text-right">${{ number_format($listadoVentas->sum('total'), 2) }}</td>
    </tr>
    </tfoot>
</table>

<h3>Movimientos de Efectivo (Ingresos/Retiros)</h3>
<table>
    <thead>
    <tr>
        <th>CONCEPTO</th>
        <th width="15%">TIPO</th>
        <th width="15%">HORA</th>
        <th width="20%" class="text-right">MONTO</th>
    </tr>
    </thead>
    <tbody>
    @foreach($movimientos as $mov)
        <tr>
            <td>{{ $mov->concepto }}</td>
            <td>
                <strong class="{{ $mov->tipo == 'Entrada' ? 'text-green' : 'text-red' }}">
                    {{ strtoupper($mov->tipo) }}
                </strong>
            </td>
            <td>{{ \Carbon\Carbon::parse($mov->created_at)->format('H:i') }}</td>
            <td class="text-right font-mono">{{ $mov->tipo == 'Entrada' ? '+' : '-' }}${{ number_format($mov->monto, 2) }}</td>
        </tr>
    @endforeach
    @if($movimientos->isEmpty())
        <tr><td colspan="4" class="text-center" style="color: #94a3b8;">Sin movimientos manuales.</td></tr>
    @endif
    </tbody>
</table>

<h3>Desglose de Efectivo (Arqueo)</h3>
<div class="denominaciones-grid">
    @if(is_array($denominaciones))
        @foreach($denominaciones as $den)
            <div class="denominacion-item">
                <div class="bill-label">{{ $den['label'] }}</div>
                <div class="bill-val">x{{ $den['cantidad'] }}</div>
                <div style="font-size: 9px; color: #0284c7; margin-top:2px;">${{ number_format($den['subtotal'], 2) }}</div>
            </div>
        @endforeach
    @endif
</div>

<div style="background-color: #f8fafc; border: 1px solid #e2e8f0; padding: 15px; margin-top: 20px; text-align: right;">
    <table style="width: 50%; margin-left: auto; margin-bottom: 0;">
        <tr>
            <td style="border:none; text-align: right; color: #64748b;">Efectivo Esperado (Sistema):</td>
            <td style="border:none; text-align: right; width: 100px; font-weight: bold;">
                ${{ number_format( ($turno->saldo_inicial + $resumen['ventas_efectivo'] + $resumen['total_entradas']) - $resumen['total_retiros'], 2) }}
            </td>
        </tr>
        <tr>
            <td style="border:none; text-align: right; color: #64748b;">Efectivo Contado (Real):</td>
            <td style="border:none; text-align: right; font-weight: bold; color: #0284c7;">
                ${{ number_format($turno->saldo_cierre, 2) }}
            </td>
        </tr>
        <tr>
            <td style="border-top: 1px solid #cbd5e1; padding-top: 10px; text-align: right; font-weight: bold; font-size: 12px;">DIFERENCIA:</td>
            <td style="border-top: 1px solid #cbd5e1; padding-top: 10px; text-align: right; font-weight: bold; font-size: 14px; color: {{ $turno->diferencia < 0 ? '#dc2626' : '#16a34a' }}">
                {{ $turno->diferencia > 0 ? '+' : '' }}${{ number_format($turno->diferencia, 2) }}
            </td>
        </tr>
    </table>
</div>

<div class="signatures">
    <div class="sig-box" style="float: left;">
        <br>
        {{ strtoupper($turno->user->name) }}<br>
        <span style="font-size: 8px; color: #64748b;">CAJERO RESPONSABLE</span>
    </div>
    <div class="sig-box" style="float: right;">
        <br><br>
        <span style="font-size: 8px; color: #64748b;">SUPERVISOR / GERENTE</span>
    </div>
</div>

</body>
</html>
