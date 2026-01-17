<style>
    body { font-family: 'Helvetica'; font-size: 10px; color: #333; }
    .header { text-align: center; margin-bottom: 20px; }
    .section-title { background: #eee; padding: 5px; font-weight: bold; margin-top: 10px; }
    table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    th, td { border: 1px solid #ccc; padding: 5px; text-align: left; }
    .totals { float: right; width: 40%; margin-top: 20px; }
    .footer { font-size: 8px; margin-top: 50px; word-break: break-all; }
</style>

<div class="header">
    <h2>{{ $cfdi->sucursal->emisor->razon_social }}</h2>
    <p>RFC: {{ $cfdi->sucursal->emisor->rfc }} | Régimen: {{ $cfdi->sucursal->emisor->regimen_fiscal }}</p>
</div>

<div class="section-title">DATOS DEL RECEPTOR</div>
<p>
    Nombre: {{ $cfdi->receptor_nombre }} <br>
    RFC: {{ $cfdi->receptor_rfc }} <br>
    Uso CFDI: {{ $cfdi->uso_cfdi }} | CP: {{ $cfdi->cliente->codigo_postal ?? 'N/A' }}
</p>

<div class="section-title">CONCEPTOS</div>
<table>
    <thead>
    <tr>
        <th>Cant.</th>
        <th>Clave SAT</th>
        <th>Descripción</th>
        <th>P. Unitario</th>
        <th>Importe</th>
    </tr>
    </thead>
    <tbody>
    @foreach($cfdi->detalles as $det)
        <tr>
            <td>{{ number_format($det->cantidad, 2) }}</td>
            <td>{{ $det->clave_prod_serv }}</td>
            <td>{{ $det->descripcion }}</td>
            <td>${{ number_format($det->valor_unitario, 2) }}</td>
            <td>${{ number_format($det->importe, 2) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<div class="totals">
    <p>Subtotal: <strong>${{ number_format($cfdi->subtotal, 2) }}</strong></p>
    <p>Impuestos (IVA): <strong>${{ number_format($cfdi->impuestos, 2) }}</strong></p>
    <p>Total: <strong>${{ number_format($cfdi->total, 2) }}</strong></p>
</div>

<div class="footer">
    <div class="section-title">INFORMACIÓN DEL TIMBRADO</div>
    <p>UUID: {{ $cfdi->uuid }}</p>
    <p>Sello Digital SAT: {{ substr($cfdi->uuid, 0, 20) }}...</p>
</div>
