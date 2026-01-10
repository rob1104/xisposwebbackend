<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: 'Helvetica', sans-serif; font-size: 12px; color: #333; }
        .header { width: 100%; border-bottom: 2px solid #2c3e50; padding-bottom: 10px; }
        .logo { width: 150px; }
        .title { text-align: right; color: #2c3e50; }
        .section-title { background: #f2f2f2; padding: 5px; font-weight: bold; border-left: 4px solid #2c3e50; margin: 15px 0 5px 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { background: #2c3e50; color: white; padding: 8px; text-align: left; }
        td { padding: 8px; border-bottom: 1px solid #ddd; }
        .totals { float: right; width: 250px; margin-top: 20px; }
        .text-right { text-align: right; }
        .footer { position: fixed; bottom: 0; width: 100%; text-align: center; font-size: 10px; color: #777; }
    </style>
    <title>Compra {{ $compra->folio }}</title>
</head>
<body>
<div class="header">
    <table style="border: none;">
        <tr>
            <td style="border: none; width: 50%;">
                @if($logo) <img src="{{ $logo }}" class="logo" alt="logo"> @endif
            </td>
            <td style="border: none; width: 50%;" class="title">
                <h1 style="margin: 0;">DETALLE DE COMPRA</h1>
                <strong>Folio:</strong> {{ $compra->folio }}<br>
                <strong>Fecha:</strong> {{ $compra->fecha }}
            </td>
        </tr>
    </table>
</div>

<table style="border: none; margin-top: 20px;">
    <tr>
        <td style="border: none; width: 50%; vertical-align: top;">
            <div class="section-title">DATOS DEL PROVEEDOR</div>
            <strong>Razón Social:</strong> {{ $compra->provider->razon_social }}<br>
            <strong>RFC:</strong> {{ $compra->provider->rfc ?? 'N/A' }}<br>
            <strong>Referencia:</strong> {{ $compra->referencia }}
        </td>
        <td style="border: none; width: 50%; vertical-align: top;">
            <div class="section-title">SUCURSAL Y REGISTRO</div>
            <strong>Sucursal:</strong> {{ $compra->sucursal->nombre }}<br>
            <strong>Usuario:</strong> {{ $compra->user->name }}<br>
            <strong>Método Pago:</strong> {{ $compra->metodo_pago }}
        </td>
    </tr>
</table>

<div class="section-title">PRODUCTOS</div>
<table>
    <thead>
    <tr>
        <th>CANT.</th>
        <th>DESCRIPCIÓN</th>
        <th class="text-right">COSTO U.</th>
        <th class="text-right">IMPORTE</th>
    </tr>
    </thead>
    <tbody>
    @foreach($compra->detalles as $detalle)
        <tr>
            <td>{{ number_format($detalle->cantidad, 2) }}</td>
            <td>{{ $detalle->producto->nombre }}</td>
            <td class="text-right">${{ number_format($detalle->costo_unitario, 2) }}</td>
            <td class="text-right">${{ number_format($detalle->importe, 2) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<div class="totals">
    <table>
        <tr><td style="border: none;">Subtotal:</td><td class="text-right" style="border: none;">${{ number_format($compra->subtotal, 2) }}</td></tr>
        <tr><td style="border: none;">Impuestos:</td><td class="text-right" style="border: none;">${{ number_format($compra->iva, 2) }}</td></tr>
        <tr style="font-weight: bold; font-size: 16px;">
            <td style="border: none;">TOTAL:</td><td class="text-right" style="border: none;">${{ number_format($compra->total, 2) }}</td>
        </tr>
    </table>
</div>

<div class="footer">
    Generado por XisPOS - {{ date('d/m/Y H:i:s') }}
</div>
</body>
</html>
