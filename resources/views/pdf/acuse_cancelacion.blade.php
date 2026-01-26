<!DOCTYPE html>
<html>
<head>
    <title>Acuse de Cancelación</title>
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #333; }
        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #b91c1c; padding-bottom: 10px; }
        .title { font-size: 18px; font-weight: bold; color: #b91c1c; }
        .info-box { border: 1px solid #ddd; padding: 10px; margin-bottom: 15px; background: #f9f9f9; }
        .label { font-weight: bold; color: #555; }
        .sello { word-break: break-all; font-size: 9px; font-family: monospace; border: 1px dashed #ccc; padding: 5px; }
    </style>
</head>
<body>
<div class="header">
    <div class="title">ACUSE DE CANCELACIÓN DE CFDI</div>
    <div>Comprobante Fiscal Digital por Internet</div>
</div>

<div class="info-box">
    <p><span class="label">Fecha de Solicitud:</span> {{ $datosAcuse['fecha'] }}</p>
    <p><span class="label">RFC Emisor:</span> {{ $cfdi->sucursal->emisor->rfc }}</p>
    <p><span class="label">RFC Receptor:</span> {{ $cfdi->cliente->rfc }}</p>
</div>

<div class="info-box">
    <h3>Detalle del Comprobante Cancelado</h3>
    <p><span class="label">Folio Fiscal (UUID):</span> {{ $datosAcuse['folio_fiscal'] }}</p>
    <p><span class="label">Serie/Folio Interno:</span> {{ $cfdi->serie }}-{{ $cfdi->folio }}</p>
    <p><span class="label">Monto Total:</span> ${{ number_format($cfdi->total, 2) }}</p>
    <p><span class="label">Estado SAT:</span> <strong style="color: #b91c1c;">{{ $datosAcuse['estatus'] }}</strong></p>
</div>

<div style="margin-top: 20px;">
    <p class="label">Sello Digital del SAT (Sello de Cancelación):</p>
    <div class="sello">{{ $datosAcuse['sello_sat'] }}</div>
</div>

<div style="margin-top: 50px; text-align: center; font-size: 10px; color: #888;">
    Este documento es una representación impresa del acuse de cancelación emitido por el SAT.
</div>
</body>
</html><?php
