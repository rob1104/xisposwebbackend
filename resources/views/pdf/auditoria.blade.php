<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte de Conteo Fisico #{{ $auditoria->id }}</title>
    <style>
        /* Configuraciones de Página para DomPDF */
        @page { margin: 1.5cm; }
        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            color: #2c3e50;
            line-height: 1.4;
            font-size: 11px;
        }


        .header-table { width: 100%; border-bottom: 2px solid #00acc1; padding-bottom: 10px; margin-bottom: 20px; }
        .brand-title { font-size: 22px; font-weight: bold; color: #00acc1; margin: 0; }
        .folio-box { text-align: right; }
        .folio-label { font-size: 10px; color: #7f8c8d; text-transform: uppercase; }
        .folio-number { font-size: 18px; font-weight: bold; color: #e74c3c; }


        .summary-table { width: 100%; background-color: #f8f9fa; border-radius: 8px; padding: 15px; margin-bottom: 25px; }
        .info-label { font-weight: bold; color: #34495e; width: 120px; text-transform: uppercase; font-size: 9px; }
        .info-value { color: #2c3e50; font-size: 11px; }

        /* Tabla de Productos */
        .data-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .data-table th {
            background-color: #37474f;
            color: #ffffff;
            padding: 10px 5px;
            text-transform: uppercase;
            font-size: 9px;
            letter-spacing: 0.5px;
        }
        .data-table td { padding: 8px 5px; border-bottom: 1px solid #ebedef; }
        .data-table tr:nth-child(even) { background-color: #fcfcfc; }

        /* Estilos de Celda Dinámicos */
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-bold { font-weight: bold; }
        .negative { color: #c0392b; background-color: #f9ebea; font-weight: bold; }
        .positive { color: #27ae60; background-color: #eafaf1; font-weight: bold; }
        .neutral { color: #95a5a6; }

        /* Pie de Página / Firmas */
        .footer-signatures { margin-top: 50px; width: 100%; }
        .signature-line { border-top: 1px solid #333; width: 200px; margin: 0 auto; margin-top: 40px; }
        .signature-text { text-align: center; font-size: 9px; color: #7f8c8d; margin-top: 5px; text-transform: uppercase; }
    </style>
</head>
<body>

<table class="header-table">
    <tr>
        <td>
            <h1 class="brand-title">XISPOS CONTROL <span style="color: #34495e; font-weight: normal;">INVENTARIOS</span></h1>
            <span style="font-size: 10px; color: #7f8c8d;">Reporte Oficial de Ajuste de Existencias</span>
        </td>
        <td class="folio-box">
            <span class="folio-label">Folio de Auditoría</span><br>
            <span class="folio-number">#{{ str_pad($auditoria->id, 6, '0', STR_PAD_LEFT) }}</span>
        </td>
    </tr>
</table>

<table class="summary-table">
    <tr>
        <td class="info-label">Sucursal:</td>
        <td class="info-value">{{ $auditoria->sucursal->nombre }}</td>
        <td class="info-label">Fecha Emisión:</td>
        <td class="info-value">{{ \Carbon\Carbon::parse($auditoria->fecha)->format('d/m/Y h:i A') }}</td>
    </tr>
    <tr>
        <td class="info-label">Auditor:</td>
        <td class="info-value">{{ $auditoria->user->name }}</td>
        <td class="info-label">Estatus:</td>
        <td class="info-value" style="color: #27ae60; font-weight: bold;">{{ strtoupper($auditoria->status) }}</td>
    </tr>
    @if($auditoria->observaciones)
        <tr>
            <td class="info-label">Observaciones:</td>
            <td colspan="3" class="info-value">{{ $auditoria->observaciones }}</td>
        </tr>
    @endif
</table>

<table class="data-table">
    <thead>
    <tr>
        <th width="15%">Código</th>
        <th width="35%" align="left">Descripción del Producto</th>
        <th width="15%" class="text-center">Stock Sistema</th>
        <th width="15%" class="text-center">Stock Físico</th>
        <th width="20%" class="text-center">Diferencia / Ajuste</th>
    </tr>
    </thead>
    <tbody>
    @foreach($auditoria->detalles as $detalle)
        <tr>
            <td class="text-center">{{ $detalle->producto->codigo_barras }}</td>
            <td>{{ $detalle->producto->nombre }}</td>
            <td class="text-center">{{ number_format($detalle->stock_sistema, 2) }}</td>
            <td class="text-center text-bold">{{ number_format($detalle->stock_fisico, 2) }}</td>
            <td class="text-center {{ $detalle->diferencia < 0 ? 'negative' : ($detalle->diferencia > 0 ? 'positive' : 'neutral') }}">
                {{ $detalle->diferencia > 0 ? '+' : '' }}{{ number_format($detalle->diferencia, 2) }}
            </td>
        </tr>
    @endforeach
    </tbody>
</table>

<table class="footer-signatures">
    <tr>
        <td align="center">
            <div class="signature-line"></div>
            <div class="signature-text">Firma Auditor<br>({{ $auditoria->user->name }})</div>
        </td>
        <td align="center">
            <div class="signature-line"></div>
            <div class="signature-text">Sello y Firma Gerencia<br>Responsable de Sucursal</div>
        </td>
    </tr>
</table>

<div style="position: fixed; bottom: -20px; left: 0; right: 0; text-align: center; font-size: 8px; color: #bdc3c7;">
    Este documento es un registro oficial de movimientos de almacén generado automáticamente por el sistema XISPOS WEB 3.0. <br>
    Fecha de impresión: {{ date('d/m/Y H:i:s') }}
</div>

</body>
</html>
