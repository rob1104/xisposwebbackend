<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CajaTurno;
use App\Models\InventarioMovimiento;
use App\Models\Producto;
use App\Models\Sucursal;
use App\Models\Venta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VentaController extends Controller
{
    public function index(Request $request)
    {

        $sucursalId = $request->header('X-Sucursal-Id');

        $query = Venta::with(['cliente', 'pagos', 'user'])
            ->orderBy('created_at', 'desc');

        if ($sucursalId) {
            $query->where('sucursale_id', $sucursalId);
        }

        return response()->json($query->get());
    }

    public function show($id)
    {
        $venta = Venta::with([
            'detalles.producto',
            'pagos',
            'user',
            'cliente'
        ])->findOrFail($id);

        return response()->json($venta);
    }

    public function store(Request $request)
    {
        $request->validate([
            'items' => 'required|array',
            'pagos' => 'required|array',
            'total' => 'required|numeric',
        ]);

        // 0. Obtenemos el turno y generamos el folio por adelantado
        $turno = CajaTurno::where('user_id', auth()->id())
            ->where('status', 'Abierto')
            ->firstOrFail();

        $folio = $this->generarFolioUnico($turno->sucursale_id);

        return DB::transaction(function () use ($request, $turno, $folio) {
            $totalSubtotal = 0;
            $totalImpuestos = 0;
            $detallesParaInsertar = [];

            foreach ($request->items as $item) {
                $producto = Producto::with('impuestos')->findOrFail($item['id']);
                $tasaTotal = $producto->impuestos->sum('porcentaje') / 100;

                // Ajuste: Usamos precio_venda para ser consistentes con el frontend
                $precioFinalConImpuesto = (float) $item['precio'];
                $cantidad = (float) $item['cantidad'];

                // 3. DESGLOSE MATEMÁTICO
                $precioBaseUnitario = $precioFinalConImpuesto / (1 + $tasaTotal);
                $impuestoUnitario = $precioFinalConImpuesto - $precioBaseUnitario;

                $subtotalLinea = $precioBaseUnitario * $cantidad;
                $totalLinea = $precioFinalConImpuesto * $cantidad;
                $impuestoLinea = $totalLinea - $subtotalLinea;

                $totalSubtotal += $subtotalLinea;
                $totalImpuestos += $impuestoLinea;

                $detallesParaInsertar[] = [
                    'producto_id' => $producto->id,
                    'cantidad' => $cantidad,
                    'precio_unitario' => $precioBaseUnitario,
                    'impuesto_unitario' => $impuestoUnitario,
                    'subtotal' => $subtotalLinea,
                    'total' => $totalLinea,
                ];

                // 5. AFECTACIÓN DE INVENTARIO Y MOVIMIENTO
                $stockSucursal = $producto->sucursales()
                    ->where('sucursal_id', $turno->sucursale_id)
                    ->first();

                if ($stockSucursal) {
                    $nuevoStock = $stockSucursal->pivot->stock_actual - $cantidad;

                    // Actualizamos existencia
                    $producto->sucursales()->updateExistingPivot($turno->sucursale_id, [
                        'stock_actual' => $nuevoStock
                    ]);

                    // REGISTRAMOS LA SALIDA EN EL KARDEX
                    InventarioMovimiento::create([
                        'producto_id'  => $producto->id,
                        'sucursal_id' => $turno->sucursale_id,
                        'tipo_movimiento'  => 'SALIDA POR VENTA',
                        'observaciones' => "vENTA registrada con folio: " . $folio,
                        'cantidad'     => $cantidad,
                        'referencia_tipo' => 'VENTA',
                        'stock_anterior'  => $stockSucursal->pivot->stock_actual,
                        'stock_nuevo' => $nuevoStock,
                        'user_id'      => auth()->id()
                    ]);
                }
            }

            // 6. Crear Cabecera de Venta
            $venta = Venta::create([
                'folio' => $folio,
                'sucursale_id' => $turno->sucursale_id,
                'user_id' => auth()->id(),
                'caja_turno_id' => $turno->id,
                'subtotal' => $totalSubtotal,
                'impuestos' => $totalImpuestos,
                'total' => $request->total,
                'tipo_cambio' => $turno->tipo_cambio,
                'status' => 'Completada'
            ]);

            // 7. Detalles y Pagos
            $venta->detalles()->createMany($detallesParaInsertar);

            foreach ($request->pagos as $pago) {
                $venta->pagos()->create([
                    'metodo_pago' => $pago['metodo'],
                    'monto' => $pago['monto'],
                    'referencia_pago' => $pago['referencia'] ?? null,
                    'tarjeta_ultimos_4' => $pago['tarjeta'] ?? null,
                    'efectivo_recibido' => $pago['efectivo_recibido'] ?? null,
                    'cambio_entregado' => $pago['cambio_entregado'] ?? null,
                ]);
            }

            return response()->json([
                'message' => 'Venta finalizada',
                'folio' => $venta->folio,
                'id' => $venta->id
            ]);
        });
    }

    public function cancelar(Request $request, $id)
    {
        $request->validate(['motivo' => 'required|string|min:5']);

        return DB::transaction(function () use ($request, $id) {
            $venta = Venta::with('detalles')->findOrFail($id);

            if ($venta->status === 'Cancelada') {
                return response()->json(['error' => 'Esta venta ya fue anulada anteriormente.'], 422);
            }

            foreach ($venta->detalles as $detalle) {
                // Obtenemos stock actual de la sucursal donde se vendió
                $stockPivot = DB::table('sucursal_productos')
                    ->where('producto_id', $detalle->producto_id)
                    ->where('sucursal_id', $venta->sucursale_id)
                    ->first();

                if ($stockPivot) {
                    $nuevoStock = $stockPivot->stock_actual + $detalle->cantidad;

                    // Revertimos stock
                    DB::table('sucursal_productos')
                        ->where('id', $stockPivot->id)
                        ->update(['stock_actual' => $nuevoStock]);

                    // REGISTRO DE MOVIMIENTO: ENTRADA POR CANCELACIÓN
                    InventarioMovimiento::create([
                        'producto_id'  => $detalle->producto_id,
                        'sucursal_id' => $venta->sucursale_id,
                        'tipo_movimiento'         => 'ENTRADA (CANCELACION DE VENTA)',
                        'observaciones'       => "Cancelación de compra folio: " . $venta->folio,
                        'cantidad'     => $detalle->cantidad,
                        'stock_anterior'  => $stockPivot->stock_actual,
                        'stock_nuevo'  => $nuevoStock,
                        'referencia'   => $venta->folio,
                        'user_id'      => auth()->id()
                    ]);
                }
            }

            // Actualizamos la venta
            $venta->update([
                'status' => 'Cancelada',
                'notas' => $request->motivo
            ]);

            return response()->json(['message' => "Venta {$venta->folio} anulada y stock restablecido."]);
        });
    }

    /**
     * Genera un folio único basado en el prefijo de la sucursal
     * y el conteo actual de ventas en dicha sucursal.
     */
    private function generarFolioUnico($sucursalId)
    {
        // Buscamos la sucursal para obtener su prefijo
        $sucursal = Sucursal::find($sucursalId);

        // Si no tiene prefijo, usamos 'VTA' (Venta) por defecto
        $prefijo = $sucursal && $sucursal->prefijo ? strtoupper($sucursal->prefijo) : 'VTA';

        // Contamos las ventas existentes en esa sucursal
        // Usamos sucursale_id para ser consistente con tu tabla caja_turnos
        $consecutivo = Venta::where('sucursale_id', $sucursalId)->count() + 1;

        // Retornamos el formato PREFIJO-00000001 (8 dígitos de padding)
        return sprintf("%s-%s", $prefijo, str_pad($consecutivo, 8, '0', STR_PAD_LEFT));
    }
}
