<style>
    body { font-family: 'Helvetica', sans-serif; font-size: 11px; color: #333; }
    .header { text-align: center; border-bottom: 2px solid #2c3e50; padding-bottom: 10px; margin-bottom: 15px; }

    /* Estilo de Tarjetas KPI */
    .kpi-table { width: 100%; margin-bottom: 20px; border-collapse: collapse; }
    .kpi-box { background-color: #f8f9fa; padding: 10px; border: 1px solid #e2e8f0; text-align: center; }
    .label { font-size: 8px; color: #64748b; text-transform: uppercase; font-weight: bold; }
    .value { font-size: 12px; font-weight: bold; }

    /* Sección de Movimientos y Detalle */
    .section-title { background-color: #334155; color: white; padding: 5px 10px; text-transform: uppercase; font-size: 9px; margin-top: 20px; }
    .data-table { width: 100%; border-collapse: collapse; margin-top: 5px; }
    .data-table th { border-bottom: 2px solid #334155; padding: 5px; font-size: 8px; text-align: left; }
    .data-table td { border-bottom: 1px solid #eee; padding: 6px 5px; }

    .denominacion-card {
        border: 1px solid #e2e8f0;
        padding: 5px;
        text-align: center;
        width: 18%;
        display: inline-block;
        margin: 0.5%;
        background-color: #fff;
    }

    .text-right { text-align: right; }
    .text-success { color: #15803d; }
    .text-danger { color: #b91c1c; }
</style>

<div class="header">
    <h2 style="margin:0;">CIERRE DE CAJA (CORTE X)</h2>
    <p style="margin:5px 0;">Turno #{{ $turno->id }} | Cajero: {{ $turno->user->name }} | Fecha: {{ $turno->created_at }}</p>
</div>

<table class="kpi-table">
    <tr>
        <td width="25%">
            <div class="kpi-box">
                <div class="label">Fondo Inicial</div>
                <div class="value">${{ number_format($turno->saldo_inicial, 2) }}</div>
            </div>
        </td>
        <td width="25%">
            <div class="kpi-box">
                <div class="label">Ventas Efectivo</div>
                <div class="value">${{ number_format($resumen['ventas_efectivo'], 2) }}</div>
            </div>
        </td>
        <td width="25%">
            <div class="kpi-box">
                <div class="label">Ingresos / Retiros</div>
                <div class="value" style="color: {{ ($resumen['total_entradas'] - $resumen['total_retiros']) < 0 ? 'red' : 'green' }}">
                    {{ ($resumen['total_entradas'] - $resumen['total_retiros']) >= 0 ? '+' : '' }}${{ number_format($resumen['total_entradas'] - $resumen['total_retiros'], 2) }}
                </div>
            </div>
        </td>
        <td width="25%">
            <div class="kpi-box" style="background-color: #f1f5f9;">
                <div class="label">Efectivo Físico</div>
                <div class="value" style="color: #0369a1;">${{ number_format($turno->saldo_cierre, 2) }}</div>
            </div>
        </td>
    </tr>
</table>

<div class="section-title">Detalle de Movimientos (Entradas y Retiros)</div>
<table class="data-table">
    <thead>
    <tr>
        <th width="45%">Concepto / Motivo</th>
        <th width="20%">Tipo</th>
        <th width="15%">Hora</th>
        <th width="20%" class="text-right">Monto</th>
    </tr>
    </thead>
    <tbody>
    @foreach($movimientos as $mov)
        <tr>
            <td>{{ $mov->concepto }}</td>
            <td class="{{ $mov->tipo == 'Entrada' ? 'text-success' : 'text-danger' }}">
                <strong>{{ strtoupper($mov->tipo) }}</strong>
            </td>
            <td>{{ \Carbon\Carbon::parse($mov->created_at)->format('h:i A') }}</td>
            <td class="text-right text-bold">
                {{ $mov->tipo == 'Entrada' ? '+' : '-' }}${{ number_format($mov->monto, 2) }}
            </td>
        </tr>
    @endforeach
    @if($movimientos->isEmpty())
        <tr>
            <td colspan="4" style="text-align: center; color: #94a3b8; font-style: italic; padding: 15px;">
                No se registraron movimientos manuales durante este turno.
            </td>
        </tr>
    @endif
    </tbody>
    <tfoot>
    <tr style="background-color: #f8fafc;">
        <td colspan="3" class="text-right text-bold">TOTAL MOVIMIENTOS NETO:</td>
        <td class="text-right text-bold" style="font-size: 11px;">
            ${{ number_format($resumen['total_entradas'] - $resumen['total_retiros'], 2) }}
        </td>
    </tr>
    </tfoot>
</table>

<div class="section-title">Conteo Físico por Denominación</div>
<div style="margin-top: 10px; background-color: #f8fafc; padding: 10px; border-radius: 5px;">
    @foreach($denominaciones as $den)
        <div class="denominacion-card">
            <div style="font-size: 8px; color: #64748b; text-transform: uppercase;">{{ $den['label'] }}</div>
            <div style="font-weight: bold; font-size: 11px;">x{{ $den['cantidad'] }}</div>
            <div style="color: #0369a1; font-weight: bold;">${{ number_format($den['subtotal'], 2) }}</div>
        </div>
    @endforeach
</div>

<div style="margin-top: 25px; border-top: 2px solid #334155; padding-top: 10px; text-align: right;">
    <h2 style="margin:0; color: {{ $turno->diferencia < 0 ? '#b91c1c' : '#15803d' }}">
        Diferencia Final: ${{ number_format($turno->diferencia, 2) }}
    </h2>
    <p style="font-size: 8px; color: #64748b; margin-top: 5px;">
        * Resultado de la comparación entre el saldo esperado (Aritmética de Sistema) y el efectivo contado físicamente.
    </p>
</div>
