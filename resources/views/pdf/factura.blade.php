<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Factura Electrónica {{ $xml['Serie'] }}{{ $xml['Folio'] }}</title>
    <style>
        /* Esto asegura que el padding no empuje los elementos fuera del margen */
        * {
            box-sizing: border-box;
        }

        @page { margin: 0.8cm; }
        body { font-family: 'Helvetica', 'Arial', sans-serif; font-size: 8.5px; color: #1e293b; line-height: 1.3; }
        .text-bold { font-weight: bold; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .text-uppercase { text-transform: uppercase; }

        /* Colores Premium */
        .bg-slate { background-color: #f1f5f9; }
        .bg-indigo { background-color: #1e1b4b; color: white; }
        .border-indigo { border-bottom: 2px solid #1e1b4b; }

        /* Layout */
        .full-width { width: 100%; border-collapse: collapse; }
        .m-t-10 { margin-top: 10px; }
        .p-5 { padding: 5px; }

        /* Secciones */
        .invoice-header { font-size: 16px; font-weight: bold; color: #1e1b4b; margin: 0; }
        .section-title { font-size: 9px; padding: 4px 8px; font-weight: bold; margin-bottom: 5px; border-radius: 3px; }

        /* Tabla de Conceptos */
        .table-concepts th { padding: 8px; font-size: 8px; text-transform: uppercase; }
        .table-concepts td { padding: 7px 8px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }

        .seal-container {
            width: 100%; /* Forzar a que no exceda el ancho */
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 8px;
            margin-top: 15px;
            display: block; /* Asegurar comportamiento de bloque */
        }
        .seal-title { font-size: 7.5px; font-weight: bold; color: #475569; margin-top: 4px; }

        .seal-content {
            font-family: monospace;
            font-size: 7px;
            color: #64748b;
            line-height: 1.1;
            width: 100%; /* Asegurar ancho máximo */

            word-break: break-all;
            word-wrap: break-word;
            white-space: normal;
            overflow-wrap: break-word;
        }

        .footer-legend { margin-top: 15px; font-size: 8px; font-weight: bold; color: #94a3b8; text-align: center; }
    </style>
</head>
<body>

<table class="full-width">
    <tr>
        <td style="width: 25%; vertical-align: middle;">
            @if($logoBase64)
                <img src="{{ $logoBase64 }}" style="max-width: 160px; max-height: 90px;">
            @else
                <div style="width: 100px; height: 50px; background: #f1f5f9;"></div>
            @endif
        </td>
        <td style="width: 40%; vertical-align: top;">
            <div class="invoice-header text-uppercase">{{ (string)$xml->xpath('//cfdi:Emisor')[0]['Nombre'] }}</div>
            <div class="text-bold">RFC: {{ (string)$xml->xpath('//cfdi:Emisor')[0]['Rfc'] }}</div>
            <div>Régimen Fiscal: {{ (string)$xml->xpath('//cfdi:Emisor')[0]['RegimenFiscal'] }}</div>
            <div>Lugar de Expedición (C.P.): {{ (string)$xml['LugarExpedicion'] }}</div>
        </td>
        <td style="width: 35%;" class="text-right">
            <div class="text-bold" style="font-size: 13px;">FACTURA: {{ $xml['Serie'] }}{{ $xml['Folio'] }}</div>
            <div class="text-bold">FOLIO FISCAL (UUID):</div>
            <div style="font-family: monospace; font-size: 9.5px; color: #475569;">{{ $fiscal['uuid'] }}</div>
            <div>No. Certificado Emisor: {{ (string)$xml['NoCertificado'] }}</div>
            <div>Fecha y Hora de Emisión: {{ (string)$xml['Fecha'] }}</div>
            <div>Fecha y Hora de Certificación: {{ $fiscal['fecha_timbrado'] }}</div>
        </td>
    </tr>
</table>

<table class="full-width m-t-10">
    <tr>
        <td style="width: 50%; padding-right: 10px; vertical-align: top;">
            <div class="section-title bg-indigo">RECEPTOR</div>
            <div class="text-bold text-uppercase">{{ (string)$xml->xpath('//cfdi:Receptor')[0]['Nombre'] }}</div>
            <div><span class="text-bold">RFC:</span> {{ (string)$xml->xpath('//cfdi:Receptor')[0]['Rfc'] }}</div>
            <div><span class="text-bold">Domicilio Fiscal:</span> {{ $fiscal['receptor_cp'] }}</div>
            <div><span class="text-bold">Régimen Fiscal:</span> {{ $fiscal['receptor_regimen_txt'] }}</div>
            <div><span class="text-bold">Uso CFDI:</span> {{ $fiscal['receptor_uso_txt'] }}</div>
        </td>
        <td style="width: 50%; vertical-align: top;">
            <div class="section-title bg-indigo">DATOS DE COMPROBANTE</div>
            <div><span class="text-bold">Método de Pago:</span> {{ $fiscal['metodo_pago'] }} - Pago en una sola exhibición</div>
            <div><span class="text-bold">Forma de Pago:</span> {{ $fiscal['forma_pago_txt'] }}</div>
            <div><span class="text-bold">Tipo de Comprobante:</span> {{ (string)$xml['TipoDeComprobante'] }} - Ingreso</div>
            <div><span class="text-bold">Moneda:</span> {{ (string)$xml['Moneda'] }} | <span class="text-bold">Exportación:</span> {{ (string)$xml['Exportacion'] }}</div>
        </td>
    </tr>
</table>

<table class="full-width m-t-10 table-concepts">
    <thead class="bg-slate">
    <tr class="border-indigo">
        <th style="width: 15%;">Clave / Cant.</th>
        <th style="width: 10%;">Unidad</th>
        <th style="width: 45%;">Descripción / Impuestos</th>
        <th style="width: 15%;" class="text-right">Valor Unit.</th>
        <th style="width: 15%;" class="text-right">Importe</th>
    </tr>
    </thead>
    <tbody>
    @foreach($xml->xpath('//cfdi:Concepto') as $concepto)
        <tr>
            <td>
                <div class="text-bold">{{ (string)$concepto['ClaveProdServ'] }}</div>
                <div>{{ number_format((float)$concepto['Cantidad'], 2) }}</div>
            </td>
            <td>{{ (string)$concepto['ClaveUnidad'] }}<br>{{ (string)$concepto['Unidad'] ?? 'PZ' }}</td>
            <td>
                <div class="text-bold text-uppercase">{{ (string)$concepto['Descripcion'] }}</div>
                @if($concepto->xpath('.//cfdi:Traslado'))
                    @foreach($concepto->xpath('.//cfdi:Traslado') as $traslado)
                        <div style="font-size: 7.5px; color: #64748b; margin-top: 2px;">
                            Impuesto: 002-IVA | Tasa: {{ (string)$traslado['TasaOCuota'] }} | Base: ${{ number_format((float)$traslado['Base'], 2) }} | Importe: ${{ number_format((float)$traslado['Importe'], 2) }}
                        </div>
                    @endforeach
                @endif
            </td>
            <td class="text-right">${{ number_format((float)$concepto['ValorUnitario'], 2) }}</td>
            <td class="text-right">${{ number_format((float)$concepto['Importe'], 2) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<table class="full-width m-t-10">
    <tr>
        <td style="width: 130px; vertical-align: top;">
            <img src="{{ $qrUrl }}" width="115" height="115">
        </td>
        <td style="vertical-align: top; padding-top: 10px;">
            <div class="text-bold">Importe con Letra:</div>
            <div class="text-uppercase" style="font-style: italic; font-size: 9px; margin-top: 3px;">
                {{ $totalLetra }}
            </div>

            <div style="margin-top: 15px;">
                <span class="text-bold">Condiciones de Pago:</span> Inmediato<br>
                <span class="text-bold">Observaciones:</span> Comprobante fiscal digital generado exitosamente.
            </div>
        </td>
        <td style="width: 200px; vertical-align: top;">
            <table class="full-width">
                <tr>
                    <td class="p-5">Subtotal:</td>
                    <td class="text-right p-5 text-bold">${{ number_format((float)$xml['SubTotal'], 2) }}</td>
                </tr>
                @foreach($impuestosGlobales as $t)
                    <tr>
                        <td class="p-5">IVA ({{ (float)$t['TasaOCuota'] * 100 }}%):</td>
                        <td class="text-right p-5 text-bold border-bottom">${{ number_format((float)$t['Importe'], 2) }}</td>
                    </tr>
                @endforeach
                <tr class="bg-slate">
                    <td class="p-5 text-bold" style="font-size: 11px;">TOTAL:</td>
                    <td class="text-right p-5 text-bold" style="font-size: 11px; color: #1e1b4b;">
                        ${{ number_format((float)$xml['Total'], 2) }}
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>

<div class="seal-container">
    <div class="seal-title">Cadena Original del Complemento de Certificación Digital del SAT:</div>
    <div class="seal-content">{{ $cadenaOriginal }}</div>

    <div class="seal-title">Sello Digital del Emisor:</div>
    <div class="seal-content">{{ $fiscal['sello_cfd'] }}</div>

    <div class="seal-title">Sello Digital del SAT:</div>
    <div class="seal-content">{{ $fiscal['sello_sat'] }}</div>

    <table class="full-width m-t-10" style="font-size: 7px; color: #475569; border-top: 1px solid #f1f5f9; padding-top: 5px;">
        <tr>
            <td>No. Serie del Certificado del SAT: {{ $fiscal['no_certificado_sat'] }}</td>
            <td class="text-right">RFC Proveedor de Certificación: {{ $fiscal['rfc_prov_certif'] }}</td>
        </tr>
    </table>
</div>

<div class="footer-legend">
    ESTE DOCUMENTO ES UNA REPRESENTACIÓN IMPRESA DE UN CFDI
</div>

</body>
</html>
