<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cfdi;
use App\Models\Cliente;
use App\Models\Setting;
use App\Models\Sucursal;
use App\Models\Venta;
use App\Services\FinkokService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Luecano\NumeroALetras\NumeroALetras;

class CfdiController extends Controller
{
    protected $finkok;

    public function __construct(FinkokService $finkok)
    {
        $this->finkok = $finkok;
    }

    public function index(Request $request)
    {
        $query = Cfdi::query()->with(['sucursal', 'cliente']);

        // Filtrado por sucursal seleccionada
        if ($request->has('sucursal_id')) {
            $query->where('sucursale_id', $request->sucursal_id);
        }

        $facturas = $query->orderBy('created_at', 'desc')->get()->map(function($f) {
            return [
                'id' => $f->id,
                'serie' => $f->serie,
                'folio' => $f->folio,
                'fecha_only' => $f->created_at->format('d/m/Y'),
                'hora_only' => $f->created_at->format('H:i:s'),
                'receptor_nombre' => $f->receptor_nombre,
                'receptor_rfc' => $f->receptor_rfc,
                'total' => (float) $f->total,
                'uuid' => $f->uuid,
                'status' => $f->status,
                'cliente' => $f->cliente
            ];
        });
        return response()->json($facturas);
    }

    public function store(Request $request)
    {
        // 1. Validar que las ventas seleccionadas existan y no estén facturadas (Candado)
        $ventas = Venta::whereIn('id', $request->ventas_ids)
            ->whereNull('cfdi_id')
            ->where('status', '!=', 'Cancelada')
            ->get();

        if ($ventas->count() !== count($request->ventas_ids)) {
            return response()->json(['message' => 'Una o más ventas ya han sido facturadas o están canceladas.'], 422);
        }

        return DB::transaction(function () use ($request, $ventas) {
            // 2. Obtener consecutivo de folio por sucursal
            $sucursal = auth()->user()->sucursal;
            $ultimoFolio = Cfdi::where('sucursale_id', $sucursal->id)
                ->where('serie', $sucursal->serie_prefijo)
                ->max('folio') ?? 0;

            // 3. Crear cabecera del CFDI
            $cfdi = Cfdi::create([
                'sucursale_id' => $sucursal->id,
                'cliente_id'  => $request->cliente_id,
                'user_id'     => auth()->id(),
                'serie'       => $sucursal->serie_prefijo,
                'folio'       => $ultimoFolio + 1,
                'forma_pago'  => $request->forma_pago,
                'metodo_pago' => $request->metodo_pago,
                'uso_cfdi'    => $request->uso_cfdi,
                'subtotal'    => $ventas->sum('subtotal'),
                'total'       => $ventas->sum('total'),
                'impuestos'   => $ventas->sum('impuestos'),
            ]);

            // 4. Crear detalles y asociar ventas
            $cfdi->generarDetallesDesdeVentas($ventas);
            Venta::whereIn('id', $request->ventas_ids)->update(['cfdi_id' => $cfdi->id]);

            // 5. Timbrar con el servicio
            $resultado = $this->finkok->timbrarFactura($cfdi);

            if ($resultado['success']) {
                $cfdi->update([
                    'uuid' => $resultado['uuid'],
                    'status' => 'Vigente',
                    'xml_path' => $this->guardarXml($resultado['xml'], $cfdi->id)
                ]);
                return response()->json(['message' => 'Factura timbrada con éxito', 'uuid' => $resultado['uuid']]);
            } else {
                // Si falla el timbrado, revertimos la transacción para no dejar basura
                throw new \Exception($resultado['message']);
            }
        });
    }

    public function descargarXml($id)
    {
        // 1. Buscar el registro en la tabla cfdis
        $cfdi = Cfdi::findOrFail($id);

        // 2. Verificar si la ruta del XML existe en la base de datos y en el disco
        if (!$cfdi->xml_path || !Storage::disk('private')->exists($cfdi->xml_path)) {
            return response()->json(['message' => 'El archivo XML no existe en el servidor.'], 404);
        }

        $file = Storage::disk('private')->get($cfdi->xml_path);

        return response($file, 200)
            ->header('Content-Type', 'text/xml')
            // Permitimos que el frontend vea el tamaño y tipo si es necesario
            ->header('Access-Control-Expose-Headers', 'Content-Disposition');
    }


    public function reintentar($id)
    {
        // 1. Cargamos el CFDI con sus detalles y productos
        $cfdi = \App\Models\Cfdi::with(['detalles.producto', 'sucursal.emisor', 'cliente'])->findOrFail($id);

        if ($cfdi->status === 'Vigente') {
            return response()->json(['message' => 'Esta factura ya está timbrada.'], 422);
        }

        return \DB::transaction(function () use ($cfdi) {
            $subtotalCalculado = 0;
            $impuestosCalculados = 0;

            // 2. RE-CALIBRACIÓN: Actualizamos cada detalle con la tasa oficial del catálogo
            foreach ($cfdi->detalles as $det) {
                $producto = $det->producto;

                // Buscamos el impuesto configurado actualmente en el catálogo
                $impuestoConfigurado = $producto->impuestos()
                    ->where('tipo', 'Traslado')
                    ->first();



                // Obtenemos el porcentaje real (ej. 0.160000 o 0.080000)
                $tasaSAT = $impuestoConfigurado ? (float) $impuestoConfigurado->porcentaje : 0.160000;

                // Recalculamos el importe del impuesto basado en la tasa oficial
                $nuevoImpuestoImporte = round($det->impuesto_base * $tasaSAT, 2);

                // Actualizamos el registro en la base de datos
                $det->update([
                    'impuesto_tasa_cuota' => number_format($tasaSAT, 6, '.', ''),
                    'impuesto_importe'    => $nuevoImpuestoImporte
                ]);

                $subtotalCalculado += round($det->importe, 2);
                $impuestosCalculados += $nuevoImpuestoImporte;
            }

            // 3. Actualizamos los totales del encabezado para evitar el error de redondeo
            $cfdi->update([
                'subtotal'  => $subtotalCalculado,
                'impuestos' => $impuestosCalculados,
                'total'     => $subtotalCalculado + $impuestosCalculados
            ]);

            // 4. Preparamos los datos del receptor (del cliente vinculado)
            $receptor = [
                'rfc'      => $cfdi->cliente->rfc,
                'nombre'   => $cfdi->cliente->razon_social,
                'cp'       => $cfdi->cliente->codigo_postal,
                'regimen'  => $cfdi->cliente->tax_regime_id,
                'uso_cfdi' => $cfdi->uso_cfdi
            ];
            // 5. Llamamos al servicio de Finkok con los datos corregidos
            $finkok = new FinkokService();
            $resultado = $finkok->crearYTimbrar($cfdi, $receptor);

            if ($resultado['success']) {
                $cfdi->update([
                    'uuid'     => $resultado['uuid'],
                    'status'   => 'Vigente',
                    'xml_path' => $resultado['xml_path']
                ]);

                return response()->json([
                    'success' => true,
                    'message' => '¡Reintento exitoso! Factura timbrada.',
                    'uuid'    => $resultado['uuid']
                ]);
            }

            throw new \Exception($resultado['message']);
        });
    }

    public function timbrar(Request $request)
    {
        $venta = Venta::with(['detalles.producto.impuestos', 'sucursal.emisor'])->findOrFail($request->venta_id);

        $subtotalFactura = 0;
        $impuestosFactura = 0;
        $detallesCalculados = [];

        // 1. PRIMER PASO: Calcular y redondear cada línea
        foreach ($venta->detalles as $det) {
            $producto = $det->producto;
            // Obtener tasa (si es 8.00 lo convierte a 0.080000)
            $impConfig = $producto->impuestos->where('tipo', 'Traslado')->first();
            $porcentaje = $impConfig ? (float)$impConfig->porcentaje : 16.00;
            $tasaDecimal = $porcentaje > 1 ? $porcentaje / 100 : $porcentaje;

            // Redondeo estricto a 2 decimales por concepto
            $importeLinea = round($det->cantidad * $det->precio_unitario, 2);
            $impuestoLinea = round($importeLinea * $tasaDecimal, 2);

            $detallesCalculados[] = [
                'producto_id'         => $det->producto_id,
                'clave_prod_serv'     => $producto->clave_prod_serv ?? '01010101',
                'clave_unidad'        => $producto->clave_unidad ?? 'H87',
                'descripcion'         => mb_strtoupper($producto->nombre),
                'cantidad'            => $det->cantidad,
                'valor_unitario'      => $det->precio_unitario,
                'importe'             => $importeLinea,
                'impuesto_base'       => $importeLinea,
                'impuesto_importe'    => $impuestoLinea,
                'impuesto_tasa_cuota' => number_format($tasaDecimal, 6, '.', ''),
                'objeto_imp'          => '02'
            ];

            $subtotalFactura += $importeLinea;
            $impuestosFactura += $impuestoLinea;
        }

        if ($request->actualizar_catalogo && $request->receptor['cliente_id']) {
            $cliente = Cliente::find($request->receptor['cliente_id']);
            if ($cliente) {
                $cliente->update([
                    'rfc'           => $request->receptor['rfc'],
                    'razon_social'  => mb_strtoupper($request->receptor['nombre']),
                    'codigo_postal' => $request->receptor['cp'],
                    'tax_regime_id' => $request->receptor['regimen']
                ]);
            }
        }

        $sucursal = Sucursal::findOrFail($request->sucursal_id);
        $serie = "F" . ($sucursal->prefijo ?? 'GEN');
        $ultimoFolio = Cfdi::where('serie', $serie)
            ->where('sucursale_id', $request->sucursal_id)
            ->max('folio');
        $nuevoFolio = ($ultimoFolio ?? 0) + 1;

        // 2. SEGUNDO PASO: Crear el encabezado con los totales ya calculados
        $cfdi = Cfdi::create([
            'sucursale_id' => $venta->sucursale_id,
            'user_id'      => auth()->id(),
            'venta_id'     => $venta->id,
            'cliente_id'   => $request->receptor['cliente_id'],
            'status'       => 'Pendiente',
            'serie'        => $serie,
            'folio'        => $nuevoFolio,
            'subtotal'     => $subtotalFactura,  // Ya no será null
            'impuestos'    => $impuestosFactura,
            'total'        => $subtotalFactura + $impuestosFactura,
            'forma_pago'   => $request->receptor['forma_pago'],
            'metodo_pago'  => 'PUE',
            'uso_cfdi'     => $request->receptor['uso_cfdi'],
            'exportacion'  => '01',
        ]);

        // 3. TERCER PASO: Guardar los detalles
        foreach ($detallesCalculados as $detalle) {
            $cfdi->detalles()->create($detalle);
        }

        // 4. CUARTO PASO: Timbrar
        $cfdi->load('sucursal.emisor', 'detalles');
        $finkok = new FinkokService();
        $resultado = $finkok->crearYTimbrar($cfdi, $request->receptor);
        $cfdi->update(['xml_path' => $resultado['xml_path']]);

        if ($resultado['success']) {
            $cfdi->update([
                'uuid'   => $resultado['uuid'],
                'status' => 'Vigente'
            ]);
            return response()->json(['success' => true, 'uuid' => $resultado['uuid']]);
        }

        // Si falló, devolvemos el error pero el registro y el XML ya están en el servidor
        return response()->json([
            'success' => false,
            'message' => 'Error al timbrar: ' . $resultado['message'],
            'cfdi_id' => $cfdi->id // Para que el frontend sepa cuál registro quedó pendiente
        ], 422);

    }

    // App\Http\Controllers\Api\FacturaController.php

    public function destroy($id)
    {
        $cfdi = Cfdi::findOrFail($id);

        // SEGURIDAD: Si ya tiene UUID, no se puede borrar, se debe cancelar
        if ($cfdi->uuid || $cfdi->status === 'Vigente') {
            return response()->json([
                'message' => 'No se puede eliminar un CFDI timbrado. Debe utilizar la opción de Cancelar.'
            ], 422);
        }

        // 1. Borrar el archivo físico (borrador) si existe
        if ($cfdi->xml_path && \Storage::disk('private')->exists($cfdi->xml_path)) {
            \Storage::disk('private')->delete($cfdi->xml_path);
        }

        // 2. Eliminar de la base de datos (los detalles se borran por cascada o manualmente)
        $cfdi->detalles()->delete();
        $cfdi->delete();

        return response()->json(['message' => 'Borrador eliminado correctamente']);
    }

    public function generarPdf($id)
    {
        $cfdi = Cfdi::with('sucursal.emisor')->findOrFail($id);

        $logoBase64 = null;
        $setts = Setting::where('clave', 'logo_url')->first();
        $pathLogo = $setts->valor;

        if ($pathLogo) {
            // 1. Convertimos la URL en una ruta de carpeta real
            // Reemplazamos la URL base por la ruta física del servidor
            // 'http://localhost:8000/' se convierte en la ruta de tu carpeta 'public/'
            $relativePart = str_replace(url('/'), '', $pathLogo);
            $fullSystemPath = public_path($relativePart);

            // 2. Verificamos si el archivo existe físicamente en el servidor
            if (file_exists($fullSystemPath)) {
                $logoData = file_get_contents($fullSystemPath);
                $type = pathinfo($fullSystemPath, PATHINFO_EXTENSION);

                // 3. Generamos el Base64
                $logoBase64 = 'data:image/' . $type . ';base64,' . base64_encode($logoData);
            }
        }

        $catRegimen = [
            '601' => 'General de Ley Personas Morales',
            '603' => 'Personas Morales con Fines no Lucrativos',
            '605' => 'Sueldos y Salarios e Ingresos Asimilados a Salarios',
            '606' => 'Arrendamiento',
            '607' => 'Régimen de Enajenación o Adquisición de Bienes',
            '608' => 'Demás ingresos',
            '610' => 'Residentes en el Extranjero sin Establecimiento Permanente en México',
            '611' => 'Ingresos por Dividendos (socios y accionistas)',
            '612' => 'Personas Físicas con Actividades Empresariales y Profesionales',
            '614' => 'Ingresos por intereses',
            '615' => 'Régimen de los ingresos por obtención de premios',
            '616' => 'Sin obligaciones fiscales',
            '620' => 'Sociedades Cooperativas de Producción que optan por diferir sus ingresos',
            '621' => 'Incorporación Fiscal',
            '622' => 'Actividades Agrícolas, Ganaderas, Silvícolas y Pesqueras',
            '623' => 'Opcional para Grupos de Sociedades',
            '624' => 'Coordinados',
            '625' => 'Régimen de las Actividades Empresariales con ingresos a través de Plataformas Tecnológicas',
            '626' => 'Régimen Simplificado de Confianza'
        ];


        $catUso = [
            'G01' => 'Adquisición de mercancías',
            'G02' => 'Devoluciones, descuentos o bonificaciones',
            'G03' => 'Gastos en general',

            'I01' => 'Construcciones',
            'I02' => 'Mobiliario y equipo de oficina por inversiones',
            'I03' => 'Equipo de transporte',
            'I04' => 'Equipo de cómputo y accesorios',
            'I05' => 'Dados, troqueles, moldes, matrices y herramental',
            'I06' => 'Comunicaciones telefónicas',
            'I07' => 'Comunicaciones satelitales',
            'I08' => 'Otra maquinaria y equipo',

            'D01' => 'Honorarios médicos, dentales y gastos hospitalarios',
            'D02' => 'Gastos médicos por incapacidad o discapacidad',
            'D03' => 'Gastos funerales',
            'D04' => 'Donativos',
            'D05' => 'Intereses reales efectivamente pagados por créditos hipotecarios',
            'D06' => 'Aportaciones voluntarias al SAR',
            'D07' => 'Primas por seguros de gastos médicos',
            'D08' => 'Gastos de transportación escolar obligatoria',
            'D09' => 'Depósitos en cuentas para el ahorro, primas que tengan como base planes de pensiones',
            'D10' => 'Pagos por servicios educativos (colegiaturas)',

            'S01' => 'Sin efectos fiscales',
            'CP01' => 'Pagos'
        ];

        $catForma = [
            '01' => 'Efectivo',
            '02' => 'Cheque nominativo',
            '03' => 'Transferencia electrónica de fondos',
            '04' => 'Tarjeta de crédito',
            '28' => 'Tarjeta de débito',
            '99' => 'Por definir'
        ];

        $catForma = [
            '01' => 'Efectivo',
            '02' => 'Cheque nominativo',
            '03' => 'Transferencia electrónica de fondos',
            '04' => 'Tarjeta de crédito',
            '05' => 'Monedero electrónico',
            '06' => 'Dinero electrónico',
            '08' => 'Vales de despensa',
            '12' => 'Dación en pago',
            '13' => 'Pago por subrogación',
            '14' => 'Pago por consignación',
            '15' => 'Condonación',
            '17' => 'Compensación',
            '23' => 'Novación',
            '24' => 'Confusión',
            '25' => 'Remisión de deuda',
            '26' => 'Prescripción o caducidad',
            '27' => 'A satisfacción del acreedor',
            '28' => 'Tarjeta de débito',
            '29' => 'Tarjeta de servicios',
            '30' => 'Aplicación de anticipos',
            '31' => 'Intermediario pagos',
            '99' => 'Por definir'
        ];




        // 1. Cargar XML y registrar Namespaces
        $xmlContent = \Storage::disk('private')->get($cfdi->xml_path);
        $xml = new \SimpleXMLElement($xmlContent);
        $xml->registerXPathNamespace('cfdi', 'http://www.sat.gob.mx/cfd/4');
        $xml->registerXPathNamespace('tfd', 'http://www.sat.gob.mx/TimbreFiscalDigital');

        // Procesamos las descripciones (Código - Descripción)
        $regimenCodigo = (string)$xml->xpath('//cfdi:Receptor')[0]['RegimenFiscalReceptor'];
        $usoCodigo = (string)$xml->xpath('//cfdi:Receptor')[0]['UsoCFDI'];
        $formaCodigo = (string)$xml['FormaPago'];

        // 2. Extraer Nodos Principales
        $receptor = $xml->xpath('//cfdi:Receptor')[0];
        $timbre = $xml->xpath('//tfd:TimbreFiscalDigital')[0];

        // 3. Preparar Datos Fiscales (Lo que falta en tu imagen)
        $fiscal = [
            'uuid'              => (string)$timbre['UUID'],
            'sello_sat'         => (string)$timbre['SelloSAT'],
            'sello_cfd'         => (string)$timbre['SelloCFD'],
            'no_certificado_sat'=> (string)$timbre['NoCertificadoSAT'],
            'fecha_timbrado'    => (string)$timbre['FechaTimbrado'],
            'rfc_prov_certif'   => (string)$timbre['RfcProvCertif'],
            'receptor_cp'       => (string)$receptor['DomicilioFiscalReceptor'],
            'receptor_regimen'  => (string)$receptor['RegimenFiscalReceptor'],
            'receptor_uso'      => (string)$receptor['UsoCFDI'],
            'metodo_pago'       => (string)$xml['MetodoPago'],
            'forma_pago'        => (string)$xml['FormaPago'],
            'receptor_regimen_txt' => $regimenCodigo . ' - ' . ($catRegimen[$regimenCodigo] ?? 'Régimen no definido'),
            'receptor_uso_txt'    => $usoCodigo . ' - ' . ($catUso[$usoCodigo] ?? 'Uso no definido'),
            'forma_pago_txt'   => $formaCodigo . ' - ' . ($catForma[$formaCodigo] ?? 'Forma de pago no definida'),
        ];

        // 4. Cadena Original del Complemento de Certificación Digital del SAT
        $cadenaOriginal = "||1.1|{$fiscal['uuid']}|{$fiscal['fecha_timbrado']}|{$fiscal['rfc_prov_certif']}|{$fiscal['sello_cfd']}|{$fiscal['no_certificado_sat']}||";

        // 5. Total con Letra

        $total = (float)$xml['Total']; // Obtenemos el total del XML [cite: 21]
        $entero = floor($total); // Parte entera (Pesos)
        $centavos = round(($total - $entero) * 100); // Parte decimal (Centavos)

        $formatter = new NumeroALetras();

        // 1. Convertimos solo la parte entera a letras
        $textoEntero = $formatter->toWords($entero);

        // 2. Construimos la cadena manualmente con el formato exacto de Walmart
        // str_pad asegura que siempre sean 2 dígitos (ej: 0 -> 00)
        $totalLetra = "(" . mb_strtoupper($textoEntero) . " PESOS " . str_pad($centavos, 2, '0', STR_PAD_LEFT) . "/100 M.N.)";
        $impuestosGlobales = $xml->xpath('/cfdi:Comprobante/cfdi:Impuestos/cfdi:Traslados/cfdi:Traslado');

        // 6. URL para QR (Basado en el estándar del SAT)
        $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode(
                "https://verificacfdi.facturaelectronica.sat.gob.mx/default.aspx?" .
                "id={$fiscal['uuid']}&re={$xml->xpath('//cfdi:Emisor')[0]['Rfc']}&rr={$receptor['Rfc']}&tt={$xml['Total']}&fe=" . substr($fiscal['sello_cfd'], -8)
            );
        // 1. Generar el PDF a partir de la vista Blade
        $pdf = Pdf::loadView('pdf.factura', compact('cfdi', 'xml', 'qrUrl', 'totalLetra', 'cadenaOriginal', 'fiscal', 'impuestosGlobales', 'logoBase64'));

        // 2. Definir ruta y guardar en disco privado
        $nombreArchivo = 'pdfs/factura_' . $cfdi->folio . '_' . time() . '.pdf';
        \Storage::disk('private')->put($nombreArchivo, $pdf->output());

        // 3. Actualizar la base de datos
        $cfdi->update(['pdf_path' => $nombreArchivo]);

        return response()->json([
            'success' => true,
            'message' => 'PDF generado correctamente',
            'pdf_path' => $nombreArchivo
        ]);
    }

    public function descargarPdf($id)
    {
        $cfdi = Cfdi::findOrFail($id);

        if (!$cfdi->pdf_path || !\Storage::disk('private')->exists($cfdi->pdf_path)) {
            return response()->json(['message' => 'PDF no encontrado'], 404);
        }

        $nombreDescarga = "Factura_{$cfdi->serie}{$cfdi->folio}.pdf";
        return \Storage::disk('private')->download($cfdi->pdf_path, $nombreDescarga);
    }
    public function ventasPendientes(Request $request)
    {
        $ventas = Venta::where('sucursale_id', $request->sucursal_id)
            ->whereDoesntHave('cfdi')
            ->with('cliente')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($ventas);
    }

    private function guardarXml($xmlContent, $id) {
        $path = "cfdis/xml/factura_{$id}.xml";
        Storage::disk('public')->put($path, $xmlContent);
        return $path;
    }
}
