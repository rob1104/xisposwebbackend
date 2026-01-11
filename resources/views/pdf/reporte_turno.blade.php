<style>
    .denominacion-card {
        border: 1px solid #eee;
        padding: 5px;
        text-align: center;
        width: 18%;
        display: inline-block;
        margin: 1%;
    }
</style>

<h2>Resumen de Cierre de Caja - Turno #{{ $turno->id }}</h2>
<table width="100%" border="1" cellpadding="5" style="border-collapse: collapse;">
    <tr>
        <td><strong>Cajero:</strong> {{ $turno->user->name }}</td>
        <td><strong>Fecha:</strong> {{ $turno->created_at }}</td>
    </tr>
    <tr>
        <td><strong>Fondo Inicial:</strong> ${{ $turno->saldo_inicial }}</td>
        <td><strong>Efectivo Contado:</strong> ${{ $turno->saldo_cierre }}</td>
    </tr>
</table>

<h3>Desglose de Efectivo</h3>
<div class="denominaciones-container">
    @foreach($denominaciones as $den)
        <div class="denominacion-card">
            <div style="font-size: 10px; color: #666;">{{ $den['label'] }}</div>
            <div style="font-weight: bold;">x{{ $den['cantidad'] }}</div>
            <div style="color: #2c3e50;">${{ number_format($den['subtotal'], 2) }}</div>
        </div>
    @endforeach
</div>

<div style="margin-top: 30px; border-top: 2px solid brown; padding-top: 10px;">
    <h2 style="color: {{ $turno->diferencia < 0 ? 'red' : 'green' }}">
        Diferencia Final: ${{ number_format($turno->diferencia, 2) }}
    </h2>
</div>
