<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\FacturaMailable;
use App\Models\Cfdi;
use App\Models\Cliente;
use App\Models\Setting;
use App\Models\Sucursal;
use App\Models\Venta;
use App\Services\FinkokService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Luecano\NumeroALetras\NumeroALetras;

class CfdiController extends Controller
{
    protected FinkokService $finkok;

    public function __construct(FinkokService $finkok)
    {
        $this->finkok = $finkok;
    }

    public function index(Request $request)
    {
        $query = Cfdi::query()->with(['sucursal', 'cliente']);

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
            ->where('facturado', false)
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

            foreach($ventas as $venta) {
                $cfdi->ventas()->attach($venta->id, [
                    'monto_facturado' => $venta->total
                ]);
            }

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

    public function timbrar(Request $request)
    {
        $venta = Venta::with(['detalles.producto.impuestos', 'sucursal.emisor'])->findOrFail($request->venta_id);

        if($venta->facturado) {
            return response()->json(['message' => 'Esta venta ya fue facturada'], 422);
        }

        $calculos = $this->calcularDesgloseVenta($venta);

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

        // Crear el encabezado con los totales ya calculados
        $cfdi = Cfdi::create([
            'sucursale_id' => $venta->sucursale_id,
            'user_id'      => auth()->id(),
            'venta_id'     => $venta->id,
            'cliente_id'   => $request->receptor['cliente_id'],
            'status'       => 'Pendiente',
            'serie'        => $serie,
            'folio'        => $nuevoFolio,
            'subtotal'     => $calculos['subtotal'],
            'impuestos'    => $calculos['impuestos'],
            'total'        => $calculos['total'],
            'forma_pago'   => $request->receptor['forma_pago'],
            'metodo_pago'  => 'PUE',
            'uso_cfdi'     => $request->receptor['uso_cfdi'],
            'exportacion'  => '01',
        ]);

        $cfdi->ventas()->attach($venta->id, ['monto_facturado' => $venta->total]);

        // Guardar los detalles
        foreach ($calculos['detalles'] as $detalle) {
            $cfdi->detalles()->create($detalle);
        }

        // Timbrar
        $cfdi->load('sucursal.emisor', 'detalles');
        $finkok = new FinkokService();
        $resultado = $finkok->crearYTimbrar($cfdi, $request->receptor);
        $venta->update(['facturado' => true]);
        $cfdi->update(['xml_path' => $resultado['xml_path']]);
        if ($resultado['success']) {
            $cfdi->update([
                'uuid'   => $resultado['uuid'],
                'status' => 'Vigente'
            ]);
            return response()->json(['success' => true, 'uuid' => $resultado['uuid']]);
        }
        return response()->json([
            'success' => false,
            'message' => 'Error al timbrar: ' . $resultado['message'],
            'cfdi_id' => $cfdi->id
        ], 422);
    }

    public function reintentar($id)
    {
        // Cargar CFDI y su Venta asociada (necesitas la relación 'venta' en el modelo Cfdi)
        $cfdi = Cfdi::with(['ventas.detalles.producto.impuestos', 'cliente', 'sucursal.emisor'])->findOrFail($id);

        if($cfdi->ventas->isEmpty()) {
            return response()->json(['message' => 'No se encontraron ventas asociadas.'], 422);
        }

        if ($cfdi->status === 'Vigente') {
            return response()->json(['message' => 'Esta factura ya está timbrada.'], 422);
        }

        // Validar que exista la venta ligada
        if (!$cfdi->venta) {
            return response()->json(['message' => 'No se encontró la venta original asociada a este CFDI.'], 422);
        }

        return DB::transaction(function () use ($cfdi) {

            // REUTILIZAR EL CÁLCULO CENTRALIZADO
            // Usamos la venta original para recalcular exactamente igual que en 'timbrar'
            $ventaPrincipal = $cfdi->ventas->first();
            $calculos = $this->calcularDesgloseVenta($ventaPrincipal);

            // ACTUALIZAR ENCABEZADO
            $cfdi->update([
                'subtotal'  => $calculos['subtotal'],
                'impuestos' => $calculos['impuestos'],
                'total'     => $calculos['total']
            ]);

            // REGENERAR DETALLES
            // Borramos los detalles viejos (que podrían tener error) y ponemos los nuevos calculados
            $cfdi->detalles()->delete();
            $cfdi->detalles()->createMany($calculos['detalles']);

            // PREPARAR DATOS DEL RECEPTOR
            // En reintentar no tenemos $request->receptor, así que los tomamos de la DB
            $receptor = [
                'rfc'      => $cfdi->cliente->rfc,
                'nombre'   => $cfdi->cliente->razon_social,
                'cp'       => $cfdi->cliente->codigo_postal,
                'regimen'  => $cfdi->cliente->tax_regime_id,
                'uso_cfdi' => $cfdi->uso_cfdi,
                'forma_pago' => $cfdi->forma_pago // Importante pasar la forma de pago guardada
            ];

            // TIMBRAR
            $cfdi->load('sucursal.emisor', 'detalles'); // Recargar relaciones
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

    public function cancelar(Request $request, $id)
    {
        $request->validate([
            'motivo' => 'required|in:01,02,03,04',
            'uuid_sustituicion' => 'nullable|string'
        ]);

        $cfdi = Cfdi::findOrFail($id);

        // Validaciones de seguridad
        if ($cfdi->status === 'Cancelado') {
            return response()->json(['message' => 'La factura ya está cancelada'], 422);
        }
        if (!$cfdi->uuid) {
            return response()->json(['message' => 'No se puede cancelar una factura sin UUID'], 422);
        }

        // Validación estricta SAT para motivo 01
        if ($request->motivo === '01' && empty($request->uuid_sustitucion)) {
            return response()->json(['message' => 'Para el motivo 01 es obligatorio el UUID de sustitución'], 422);
        }

        try {
            $finkok = new FinkokService();
            $resultado = $finkok->cancelarCfdi($cfdi, $request->motivo, $request->uuid_sustitucion);
            if ($resultado['success']) {
                $statusLocal = 'Cancelado';
                $mensaje = 'Factura cancelada correctamente';
                if (isset($resultado['codigo_estatus']) && $resultado['codigo_estatus'] == '203') {
                    $statusLocal = 'En Proceso Cancelacion';
                    $mensaje = 'Solicitud enviada. Esperando aprobación del receptor en Buzón Tributario.';
                }

                $pathAcuse = null;
                if(!empty($resultado['acuse'])) {
                    $pathAcuse = "cfdis/acuses/acuse_{$cfdi->uuid}.xml";
                    \Storage::disk('private')->put($pathAcuse, $resultado['acuse']);
                }


                $cfdi->update([
                    'status' => $statusLocal,
                    'motivo_cancelacion' => $request->motivo,
                    'fecha_cancelacion' => now(),
                    'acuse_path' => $pathAcuse ?? null
                ]);

                if ($statusLocal === 'Cancelado') {
                    Venta::where('cfdi_id', $cfdi->id)->update(['cfdi_id' => null]);
                    $cfdi->venta_id = null;
                    $cfdi->save();

                    $ventasIds = $cfdi->ventas()->pluck('ventas.id');
                    Venta::whereIn('id', $ventasIds)->update(['facturado' => false]);

                }
                return response()->json([
                    'success' => true,
                    'message' => $mensaje,
                    'status_sat' => $resultado['codigo_estatus'] ?? '201',
                    'acuse' => $resultado['acuse'] ?? null
                ]);
            }
            else {
                throw new \Exception($resultado['message']);
            }
        }
        catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al cancelar ante el SAT',
                'error_sat' => $e->getMessage()
            ], 500);
        }
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

        $ventasIds = $cfdi->ventas()->pluck('ventas.id');
        Venta::whereIn('id', $ventasIds)->update(['facturado' => false]);
        $cfdi->ventas()->detach();

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

    public function descargarAcuse($id)
    {
        $cfdi = Cfdi::with('sucursal.emisor')->findOrFail($id);

        if (!$cfdi->acuse_path || !\Storage::disk('private')->exists($cfdi->acuse_path)) {
            return response()->json(['message' => 'El acuse XML no se encuentra en el servidor.'], 404);
        }

        $xmlContent = \Storage::disk('private')->get($cfdi->acuse_path);

        // Limpieza de prefijos SOAP comunes para facilitar lectura
        $xmlClean = str_replace(['s:', 'S:', 'soap:', 'ns1:'], '', $xmlContent);
        $xml = new \SimpleXMLElement($xmlClean);

        // 1. ENCONTRAR EL NODO REAL "CancelaCFDResult"
        // Usamos xpath para buscarlo donde sea que esté (ignorando si hay Envelope/Body antes)
        $nodosResultado = $xml->xpath('//CancelaCFDResult') ?: $xml->xpath('//*[local-name()="CancelaCFDResult"]');

        // Si no encuentra el nodo específico, asumimos que el XML es el resultado
        $resultNode = count($nodosResultado) > 0 ? $nodosResultado[0] : $xml;

        // 2. ENCONTRAR EL SELLO (SignatureValue)
        // El sello suele estar protegido por namespaces, lo buscamos por su nombre local
        $nodosFirma = $xml->xpath('//*[local-name()="SignatureValue"]');
        $selloSat = count($nodosFirma) > 0 ? (string)$nodosFirma[0] : 'No disponible';

        // 3. EXTRAER DATOS
        $datosAcuse = [
            // Fecha y RFC son ATRIBUTOS de CancelaCFDResult
            'fecha' => (string) $resultNode['Fecha'],
            'rfc_emisor' => (string) $resultNode['RfcEmisor'],
            // UUID y Estatus son TEXTO dentro de <Folios>, NO atributos
            'folio_fiscal' => (string) ($resultNode->Folios->UUID ?? $cfdi->uuid),
            'estatus' => (string) ($resultNode->Folios->EstatusUUID ?? 'Cancelado'),

            'sello_sat' => $selloSat,

            'cadena_original' => "||{$cfdi->uuid}|{$cfdi->cliente->rfc}|" . ((string)$resultNode['Fecha'] ?? date('Y-m-d')) . "||"
        ];

        $pdf = Pdf::loadView('pdf.acuse_cancelacion', compact('cfdi', 'datosAcuse'));

        return $pdf->download("Acuse_Cancelacion_{$cfdi->folio}.pdf");
    }

    public function ventasPendientes(Request $request)
    {
        $ventas = Venta::where('sucursale_id', $request->sucursal_id)
            ->where('facturado', false)
            ->where('status', '!=', 'Cancelada')
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

    public function enviarCorreo($id)
    {
        $cfdi = Cfdi::with(['cliente', 'sucursal'])->findOrFail($id);

        // 1. Validar que el cliente tenga correo
        if (empty($cfdi->cliente->email)) {
            return response()->json(['message' => 'El cliente no tiene un correo electrónico registrado.'], 422);
        }

        // 2. Validar que existan los archivos (para no enviar correo vacío)
        if (!$cfdi->xml_path || !Storage::disk('private')->exists($cfdi->xml_path)) {
            return response()->json(['message' => 'El archivo XML no se encuentra disponible.'], 404);
        }

        // Si no tiene PDF, intentamos generarlo al vuelo antes de enviar
        if (!$cfdi->pdf_path || !Storage::disk('private')->exists($cfdi->pdf_path)) {
            // Llamamos a tu función interna (asegúrate que generarPdf sea accesible o copia la lógica)
            $this->generarPdf($id);
            $cfdi->refresh(); // Recargamos para obtener el path nuevo
        }

        try {
            // 3. Enviar el correo
            Mail::to($cfdi->cliente->email)
                ->send(new FacturaMailable($cfdi));

            $adjuntos = [];
            if ($cfdi->xml_path) $adjuntos[] = 'XML';
            if ($cfdi->pdf_path) $adjuntos[] = 'PDF';

            activity()
                ->performedOn($cfdi)
                ->inLog('email')
                ->causedBy(auth()->user())
                ->withProperties([
                    'accion' => 'envio_correo_cfdi',
                    'destinatario' => $cfdi->cliente->email,
                    'archivos_adjuntos' => $adjuntos,
                    'ip_origen' => request()->ip(),
                    'navegador' => request()->userAgent(),
                    'mensaje_sistema' => 'Envío exitoso mediante servidor SMTP'
                ])
                ->log('Se envió cfdi por correo electrónico');

            return response()->json([
                'success' => true,
                'message' => "Correo enviado exitosamente a: {$cfdi->cliente->email}"
            ]);

        } catch (\Exception $e) {

            activity()
                ->performedOn($cfdi)
                ->inLog('email')
                ->causedBy(auth()->user())
                ->withProperties(['error' => $e->getMessage()])
                ->log('Fallo al enviar correo de factura');

            return response()->json([
                'message' => 'Error al intentar enviar el correo.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Lógica centralizada para calcular los detalles de la factura
     * basada en una Venta, aplicando redondeos estrictos.
     */
    private function calcularDesgloseVenta(Venta $venta)
    {
        $subtotalFactura = 0;
        $impuestosFactura = 0;
        $detallesCalculados = [];

        foreach ($venta->detalles as $det) {
            $producto = $det->producto;

            // Obtener tasa (si es 16.00 lo convierte a 0.160000)
            $impConfig = $producto->impuestos->where('tipo', 'Traslado')->first();
            $porcentaje = $impConfig ? (float)$impConfig->porcentaje : 16.00;
            $tasaDecimal = $porcentaje > 1 ? $porcentaje / 100 : $porcentaje;

            // Redondeo estricto a 2 decimales por concepto (Igual que en timbrar)
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

        return [
            'detalles'  => $detallesCalculados,
            'subtotal'  => $subtotalFactura,
            'impuestos' => $impuestosFactura,
            'total'     => $subtotalFactura + $impuestosFactura
        ];
    }
}
