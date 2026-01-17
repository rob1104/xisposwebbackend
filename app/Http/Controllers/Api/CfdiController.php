<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cfdi;
use App\Models\Venta;
use App\Services\FinkokService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CfdiController extends Controller
{
    protected $finkok;

    public function __construct(FinkokService $finkok)
    {
        $this->finkok = $finkok;
    }

    public function index(Request $request)
    {
        $query = Cfdi::query()->with('sucursal');

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
                'regimen'  => $cfdi->cliente->regimen_fiscal,
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

        // 2. SEGUNDO PASO: Crear el encabezado con los totales ya calculados
        $cfdi = Cfdi::create([
            'sucursale_id' => $venta->sucursale_id,
            'user_id'      => auth()->id(),
            'venta_id'     => $venta->id,
            'cliente_id'   => $venta->cliente_id,
            'status'       => 'Pendiente',
            'serie'        => 'F',
            'folio'        => $venta->id,
            'subtotal'     => $subtotalFactura,  // Ya no será null
            'impuestos'    => $impuestosFactura,
            'total'        => $subtotalFactura + $impuestosFactura,
            'forma_pago'   => $venta->metodo_pago ?? '01',
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

    public function generarPdf($id)
    {
        $cfdi = Cfdi::with(['detalles.producto', 'sucursal.emisor', 'cliente'])->findOrFail($id);

        // 1. Generar el PDF a partir de la vista Blade
        $pdf = Pdf::loadView('pdf.factura', compact('cfdi'));

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
