<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Comprobante de Traspaso</title>
    <style>
        body { font-family: 'Helvetica', 'Arial', sans-serif; font-size: 10px; color: #333; margin: 0; padding: 0; }
        .header { width: 100%; border-bottom: 2px solid #0f172a; padding-bottom: 10px; margin-bottom: 20px; text-align: center; }
        .title { font-size: 16px; font-weight: bold; text-transform: uppercase; margin: 0; }
        .subtitle { font-size: 10px; color: #64748b; margin: 0; }
        .info-table { width: 100%; margin-bottom: 20px; border-collapse: collapse; }
        .info-table td { width: 50%; padding: 5px; vertical-align: top; }
        .info-box { border: 1px solid #e2e8f0; padding: 10px; border-radius: 4px; background-color: #f8fafc; }
        .info-box h4 { margin: 0 0 5px 0; font-size: 10px; color: #0f172a; text-transform: uppercase; border-bottom: 1px solid #cbd5e1; padding-bottom: 3px; }
        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .items-table th { background-color: #0f172a; color: #fff; padding: 6px; text-align: left; font-size: 9px; text-transform: uppercase; }
        .items-table td { padding: 6px; border-bottom: 1px solid #e2e8f0; }
        .items-table tr:nth-child(even) { background-color: #f8fafc; }
        .totals { width: 100%; text-align: right; margin-top: 20px; }
        .totals span { font-weight: bold; font-size: 12px; }
        .footer { text-align: center; margin-top: 30px; font-size: 8px; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 10px; }
    </style>
</head>
<body>

<div class="header">
    <div class="title">XISPOS WEB</div>
    <div class="subtitle">Comprobante de Traspaso de Mercancía #{{ $transferencia->id }}</div>
    <div style="margin-top: 5px; font-weight: bold; color: {{ $transferencia->estatus == 'Recibido' ? '#166534' : '#c2410c' }}; font-size: 12px;">ESTADO: {{ strtoupper($transferencia->estatus) }}</div>
</div>

<table class="info-table">
    <tr>
        <td>
            <div class="info-box">
                <h4>ORIGEN</h4>
                <strong>Sucursal:</strong> {{ $transferencia->sucursalOrigen->nombre ?? 'N/A' }}<br>
                <strong>Enviado por:</strong> {{ $transferencia->userEnvia->name ?? 'N/A' }}<br>
                <strong>Fecha Envío:</strong> {{ \Carbon\Carbon::parse($transferencia->fecha_envio)->format('d/m/Y h:i A') }}<br>
                <strong>Notas:</strong> {{ $transferencia->notas ?: 'Sin notas' }}
            </div>
        </td>
        <td>
            <div class="info-box">
                <h4>DESTINO</h4>
                <strong>Sucursal:</strong> {{ $transferencia->sucursalDestino->nombre ?? 'N/A' }}<br>
                @if($transferencia->estatus == 'Recibido')
                <strong>Recibido por:</strong> {{ $transferencia->userRecibe->name ?? 'N/A' }}<br>
                <strong>Fecha Recepción:</strong> {{ \Carbon\Carbon::parse($transferencia->fecha_recepcion)->format('d/m/Y h:i A') }}
                @else
                <strong>Recibido por:</strong> PENDIENTE<br>
                <strong>Fecha Recepción:</strong> PENDIENTE
                @endif
            </div>
        </td>
    </tr>
</table>

<table class="items-table">
    <thead>
        <tr>
            <th>CÓDIGO</th>
            <th>PRODUCTO</th>
            <th style="text-align: right;">ENVIADO</th>
            @if($transferencia->estatus == 'Recibido')
            <th style="text-align: right;">RECIBIDO</th>
            <th style="text-align: right;">DIFERENCIA</th>
            @endif
        </tr>
    </thead>
    <tbody>
        @foreach($transferencia->detalles as $det)
        <tr>
            <td>{{ $det->producto->codigo_barras ?? 'N/A' }}</td>
            <td>{{ $det->producto->nombre ?? 'Desconocido' }}</td>
            <td style="text-align: right;">{{ number_format($det->cantidad_enviada, 2) }}</td>
            @if($transferencia->estatus == 'Recibido')
            <td style="text-align: right;">{{ number_format($det->cantidad_recibida, 2) }}</td>
            <td style="text-align: right; color: {{ $det->cantidad_enviada != $det->cantidad_recibida ? 'red' : 'inherit' }}; font-weight: {{ $det->cantidad_enviada != $det->cantidad_recibida ? 'bold' : 'normal' }};">
                {{ number_format($det->cantidad_enviada - $det->cantidad_recibida, 2) }}
            </td>
            @endif
        </tr>
        @endforeach
    </tbody>
</table>

<div class="totals">
    <span>TOTAL ARTÍCULOS ENVIADOS: {{ number_format($transferencia->detalles->sum('cantidad_enviada'), 2) }}</span>
    @if($transferencia->estatus == 'Recibido')
    <br><span>TOTAL ARTÍCULOS RECIBIDOS: {{ number_format($transferencia->detalles->sum('cantidad_recibida'), 2) }}</span>
    @endif
</div>

<div class="footer">
    Documento interno generado por XISPOS WEB el {{ now()->format('d/m/Y h:i A') }}
</div>

</body>
</html>
