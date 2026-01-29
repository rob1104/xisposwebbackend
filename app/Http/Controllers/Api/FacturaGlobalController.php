<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cfdi;
use App\Models\Sucursal;
use App\Models\TaxRegime;
use App\Models\Venta;
use App\Services\FinkokService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class FacturaGlobalController extends Controller
{
    public function index(Request $request)
    {
        $sucursal_id = $request->sucursalSeleccionada;
        $inicio = Carbon::parse($request->fechaInicio)->startOfDay();
        $fin = Carbon::parse($request->fechaFin)->endOfDay();

        $query = Venta::query()
                ->whereBetween('created_at', [$inicio, $fin])
                ->when($sucursal_id, fn($q) => $q->where('sucursale_id', $sucursal_id))
                ->where('status', 'Completada')
                ->where('tipo_pago', 'Contado')
                ->where('facturado', false)
                ->orderBy('created_at', 'desc');

        if ($request->filled('metodoPago') && $request->metodoPago !== 'Todos') {
            $query->whereHas('pagos', function ($q) use ($request) {
                $q->where('metodo_pago', $request->metodoPago);
            });

            $tickets = $query->with([
                'detalles',
                'pagos' => function ($q) use ($request) {
                    $q->where('metodo_pago', $request->metodoPago);
                }
            ])->get();
        } else {
            $tickets = $query->with(['detalles', 'pagos'])->get();
        }

        return response()->json($tickets->map(function($t) {
            return [
                'id' => $t->id,
                'folio' => $t->folio,
                'fecha' => $t->created_at->format('Y-m-d H:i'),
                'total' => (float) $t->total,
                'metodo_pago' => $t->pagos->first()->metodo_pago ?? 'Desconocido',
                'seleccionado' => true
            ];
        }));
    }

    public function store(Request $request)
    {
        // 1. Validaciones
        $request->validate([
            'periodicidad' => 'required|string',
            'meses' => 'required|string',
            'ano' => 'required|integer',
            'tickets_ids' => 'required|array|min:1',
            'sucursal_id' => 'required|exists:sucursales,id'
        ]);

        // 2. Obtener los tickets con sus relaciones (profundidad necesaria para impuestos)
        $tickets = Venta::whereIn('id', $request->tickets_ids)
            ->where('facturado', false)
            ->with(['detalles.producto.impuestos', 'pagos'])
            ->get();

        if ($tickets->isEmpty()) {
            return response()->json(['message' => 'Los tickets seleccionados ya fueron procesados o no existen.'], 422);
        }

        try {
            return \DB::transaction(function () use ($tickets, $request) {
                $sucursal = Sucursal::findOrFail($request->sucursal_id);

                // --- CÁLCULO DE TOTALES GLOBALES ---
                $subtotalGlobal = 0;
                $impuestosGlobal = 0;
                $totalGlobal = 0;
                $detallesParaGuardar = [];

                // Regla SAT: "Las partidas se desglosan por ticket"
                // Iteramos cada ticket y analizamos sus productos para separar por tasas
                foreach ($tickets as $ticket) {

                    // Array temporal para agrupar este ticket por tasas
                    // Estructura: [ '0.160000' => ['base' => 100, 'impuesto' => 16], '0.000000' => ... ]
                    $agrupadoPorTasa = [];

                    foreach ($ticket->detalles as $det) {
                        // A. Determinar la tasa de este producto específico
                        $impuestoConfig = $det->producto->impuestos->where('tipo', 'Traslado')->first();
                        $porcentaje = $impuestoConfig ? (float)$impuestoConfig->porcentaje : 16.00;
                        $tasaDecimal = $porcentaje > 0 ? $porcentaje / 100 : 0.0;

                        // Formato string para usarlo como llave del array (evita errores de float)
                        $tasaKey = number_format($tasaDecimal, 6, '.', '');

                        // B. Calcular importes de esta línea
                        $baseLinea = $det->cantidad * $det->precio_unitario; // Subtotal línea
                        $impuestoLinea = round($baseLinea * $tasaDecimal, 2); // Impuesto línea recalculado

                        // C. Acumular en el grupo correspondiente
                        if (!isset($agrupadoPorTasa[$tasaKey])) {
                            $agrupadoPorTasa[$tasaKey] = [
                                'base' => 0,
                                'impuesto' => 0
                            ];
                        }
                        $agrupadoPorTasa[$tasaKey]['base'] += $baseLinea;
                        $agrupadoPorTasa[$tasaKey]['impuesto'] += $impuestoLinea;
                    }

                    // --- GENERAR DETALLES CFDI (Uno por cada tasa encontrada en el ticket) ---
                    foreach ($agrupadoPorTasa as $tasaStr => $valores) {
                        $baseG = $valores['base'];
                        $impuestoG = $valores['impuesto'];


                        // Acumuladores Generales del CFDI (Sumamos lo recalculado)
                        $subtotalGlobal += round($baseG, 2);
                        $impuestosGlobal += round($impuestoG, 2);


                        // Descripción: Si el ticket tiene varias tasas, indicamos cuál es
                        $descExtra = (count($agrupadoPorTasa) > 1) ? " (Tasa " . ($tasaStr * 100) . "%)" : "";

                        $detallesParaGuardar[] = [
                            'clave_prod_serv' => '01010101', // Clave genérica obligatoria
                            'clave_unidad' => 'ACT',        // Unidad "Actividad"
                            'unidad' => 'Actividad',
                            'descripcion' => "Venta del ticket no. {$ticket->folio}" . $descExtra,
                            'cantidad' => 1,
                            'valor_unitario' => number_format($baseG, 2, '.', ''), // Base exacta
                            'importe' => number_format($baseG, 2, '.', ''),
                            'objeto_imp' => '02', // Sí objeto de impuesto

                            // Datos de impuestos para el XML y BD
                            'impuesto_tipo' => '002', // IVA
                            'impuesto_tasa_cuota' => $tasaStr, // Ej: "0.160000"
                            'impuesto_base' => number_format($baseG, 2, '.', ''),
                            'impuesto_importe' => number_format($impuestoG, 2, '.', ''),
                        ];
                    }
                }

                $subtotalGlobal = round($subtotalGlobal, 2);
                $impuestosGlobal = round($impuestosGlobal, 2);
                $totalGlobal = round($subtotalGlobal + $impuestosGlobal, 2);

                // 3. Crear Encabezado CFDI
                $prefijoSucursal = $sucursal->serie_prefijo ?? '';
                $serieReal = 'F' . $prefijoSucursal;
                $ultimoFolio = Cfdi::where('sucursale_id', $sucursal->id)
                    ->where('serie', $serieReal)
                    ->max('folio') ?? 0;

                $cfdi = Cfdi::create([
                    'sucursale_id' => $sucursal->id,
                    'user_id' => \Auth::id(),
                    'serie' => $serieReal,
                    'folio' => $ultimoFolio + 1,
                    'status' => 'Pendiente',
                    'cliente_id' => 1,
                    'uso_cfdi' => 'S01',
                    'forma_pago' => '01',
                    'metodo_pago' => 'PUE',
                    'exportacion' => '01',
                    'subtotal' => $subtotalGlobal,
                    'impuestos' => $impuestosGlobal,
                    'total' => $totalGlobal,
                ]);

                // 4. Guardar Detalles
                $cfdi->detalles()->createMany($detallesParaGuardar);

                // 5. Llenar Tabla Pivote y Actualizar Flags
                foreach ($tickets as $ticket) {
                    $cfdi->ventas()->attach($ticket->id, ['monto_facturado' => $ticket->total]);
                }
                Venta::whereIn('id', $tickets->pluck('id'))->update(['facturado' => true]);

                // 6. Timbrar con el servicio
                $finkok = new FinkokService();

                // Asegurar régimen 616
                $regimen616 = TaxRegime::firstOrCreate(
                    ['code' => '616'],
                    ['name' => 'Sin obligaciones fiscales']
                );

                $datosReceptor = [
                    'rfc'      => 'XAXX010101000',
                    'nombre'   => 'PUBLICO EN GENERAL',
                    'cp'       => $sucursal->emisor->codigo_postal,
                    'regimen'  => $regimen616->id,
                    'uso_cfdi' => 'S01'
                ];

                $infoGlobal = [
                    'Periodicidad' => $request->periodicidad,
                    'Meses' => $request->meses,
                    'Año' => $request->ano
                ];

                $resultado = $finkok->crearYTimbrar($cfdi, $datosReceptor, $infoGlobal);

                if ($resultado['success']) {
                    $cfdi->update([
                        'uuid' => $resultado['uuid'],
                        'status' => 'Vigente',
                        'xml_path' => $resultado['xml_path']
                    ]);

                    return response()->json([
                        'success' => true,
                        'message' => 'Factura Global Timbrada',
                        'uuid' => $resultado['uuid']
                    ]);
                } else {
                    throw new \Exception($resultado['message']);
                }
            });

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al timbrar ante el SAT',
                'error_pac' => $e->getMessage()
            ], 422);
        }
    }
}
