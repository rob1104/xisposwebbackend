<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CajaTurno;
use App\Models\Cliente;
use App\Models\InventarioMovimiento;
use App\Models\PrecioModificacion;
use App\Models\Producto;
use App\Models\RestMesa;
use App\Models\RestOrden;
use App\Models\Sucursal;
use App\Models\Ticket;
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
        // ... (Tus validaciones iniciales se quedan igual) ...
        $request->validate([
            'cliente_id' => 'nullable|exists:clientes,id',
            'items' => 'required|array',
            'pagos' => 'required_if:tipo_pago,Contado|array',
            'pagos.*.monto' => 'required_with:pagos|numeric',
            'pagos.*.metodo_pago' => 'required_with:pagos',
            'total' => 'required|numeric',
            'tipo_pago' => 'required|in:Contado,Credito',
            'referencia_orden' => 'nullable|string',
            'via_venta' => 'nullable|string'
        ]);

        // ... (Validación de crédito y lógica de CMD se quedan igual) ...
        if ($request->tipo_pago === 'Credito' && (is_null($request->cliente_id) || $request->cliente_id == 1)) {
            return response()->json(['message' => 'No se puede realizar una venta a crédito al público general.'], 422);
        }

        // ... (Lógica de RestOrden CMD- se queda igual) ...
        if (($request->referencia_orden && str_starts_with($request->referencia_orden, 'CMD-'))) {
            // ... tu lógica existente de CMD ...
            $ordenRest = RestOrden::where('codigo_cobro', $request->referencia_orden)->first();
            if ($ordenRest) {
                $ordenRest->update(['estatus' => 'Cobrada']);
                if ($ordenRest->mesa_id) {
                    RestMesa::where('id', $ordenRest->mesa_id)->update(['ocupada' => false]);
                }
            }
        }

        // 0. Obtenemos turno y folio
        $turno = CajaTurno::where('user_id', auth()->id())->where('status', 'Abierto')->firstOrFail();
        $folio = $this->generarFolioUnico($turno->sucursale_id);

        return DB::transaction(function () use ($request, $turno, $folio) {
            $totalSubtotal = 0;
            $totalImpuestos = 0;
            $detallesParaInsertar = [];
            $clienteId = $request->cliente_id;

            // ... (Lógica de crédito se queda igual) ...
            if ($request->tipo_pago === 'Credito') {
                $cliente = Cliente::lockForUpdate()->find($clienteId);
                $this->validarCreditoCliente($cliente, $request->total);
                $cliente->increment('saldo_actual', $request->total);
            }

            // --- AQUÍ EMPIEZA LA MAGIA DEL BLOQUEO ---
            foreach ($request->items as $item) {
                $producto = Producto::with(['impuestos', 'componentes'])->findOrFail($item['id']);
                $cantidadVenta = (float)$item['cantidad'];

                // 1. GESTIÓN DE INVENTARIO CON BLOQUEO (LockForUpdate)
                // Esto reemplaza tu antigua validación separada y la función descontarExistencia

                if ($producto->tipo_producto === 'Inventariable') {
                    // A. Buscamos y BLOQUEAMOS la fila del inventario
                    $pivotStock = DB::table('sucursal_productos')
                        ->where('sucursal_id', $turno->sucursale_id)
                        ->where('producto_id', $producto->id)
                        ->lockForUpdate() // <--- ESTO EVITA LA VENTA DOBLE
                        ->first();

                    $stockActual = $pivotStock ? $pivotStock->stock_actual : 0;

                    // B. Validamos (Nadie más puede modificar esto mientras estemos aquí)
                    if ($stockActual < $cantidadVenta) {
                        throw new \Exception("Stock insuficiente: '{$producto->nombre}'. Tienes: {$stockActual}, Intentas vender: {$cantidadVenta}");
                    }

                    // C. Descontamos y actualizamos
                    $nuevoStock = $stockActual - $cantidadVenta;
                    DB::table('sucursal_productos')
                        ->where('id', $pivotStock->id)
                        ->update(['stock_actual' => $nuevoStock]);

                    // D. Registramos Movimiento (Kardex)
                    InventarioMovimiento::create([
                        'producto_id'      => $producto->id,
                        'sucursal_id'      => $turno->sucursale_id,
                        'tipo_movimiento'  => 'SALIDA POR VENTA',
                        'observaciones'    => "VENTA DIRECTA. Folio: {$folio}",
                        'cantidad'         => $cantidadVenta,
                        'referencia_tipo'  => 'VENTA',
                        'stock_anterior'   => $stockActual,
                        'stock_nuevo'      => $nuevoStock,
                        'user_id'          => auth()->id()
                    ]);

                }
                elseif ($producto->tipo_producto === 'Compuesto') {
                    // Si es Kit, debemos bloquear CADA ingrediente
                    foreach ($producto->componentes as $hijo) {
                        $cantidadRequerida = $hijo->pivot->cantidad * $cantidadVenta;

                        // A. Bloqueamos al hijo
                        $pivotHijo = DB::table('sucursal_productos')
                            ->where('sucursal_id', $turno->sucursale_id)
                            ->where('producto_id', $hijo->id)
                            ->lockForUpdate() // <--- BLOQUEO
                            ->first();

                        $stockHijo = $pivotHijo ? $pivotHijo->stock_actual : 0;

                        // B. Validamos
                        if ($stockHijo < $cantidadRequerida) {
                            throw new \Exception("Ingredientes insuficientes ({$hijo->nombre}) para armar '{$producto->nombre}'.");
                        }

                        // C. Descontamos
                        $nuevoStockHijo = $stockHijo - $cantidadRequerida;
                        DB::table('sucursal_productos')
                            ->where('id', $pivotHijo->id)
                            ->update(['stock_actual' => $nuevoStockHijo]);

                        // D. Kardex del hijo
                        InventarioMovimiento::create([
                            'producto_id'      => $hijo->id,
                            'sucursal_id'      => $turno->sucursale_id,
                            'tipo_movimiento'  => 'SALIDA POR VENTA',
                            'observaciones'    => "VENTA KIT: {$producto->nombre}. Folio: {$folio}",
                            'cantidad'         => $cantidadRequerida,
                            'referencia_tipo'  => 'VENTA',
                            'stock_anterior'   => $stockHijo,
                            'stock_nuevo'      => $nuevoStockHijo,
                            'user_id'          => auth()->id()
                        ]);
                    }
                }

                // 2. CÁLCULOS FINANCIEROS (Se mantienen igual)
                $tasaTotal = $producto->impuestos->sum('porcentaje') / 100;
                $precioFinalConImpuesto = (float)$item['precio'];

                $precioBaseUnitario = $precioFinalConImpuesto / (1 + $tasaTotal);
                $impuestoUnitario = $precioFinalConImpuesto - $precioBaseUnitario;

                $subtotalLinea = $precioBaseUnitario * $cantidadVenta;
                $totalLinea = $precioFinalConImpuesto * $cantidadVenta;
                $impuestoLinea = $totalLinea - $subtotalLinea;

                $totalSubtotal += $subtotalLinea;
                $totalImpuestos += $impuestoLinea;

                $detallesParaInsertar[] = [
                    'producto_id' => $producto->id,
                    'cantidad' => $cantidadVenta,
                    'precio_unitario' => $precioBaseUnitario,
                    'impuesto_unitario' => $impuestoUnitario,
                    'subtotal' => $subtotalLinea,
                    'total' => $totalLinea,
                ];

                // NOTA: Ya NO llamamos a $this->descontarExistencia() aquí abajo
                // porque ya lo hicimos arriba con el bloqueo.
            }

            // 3. Creación de Venta y Pagos (Se mantiene igual)
            $venta = Venta::create([
                'folio' => $folio,
                'sucursale_id' => $turno->sucursale_id,
                'user_id' => auth()->id(),
                'caja_turno_id' => $turno->id,
                'subtotal' => $totalSubtotal,
                'impuestos' => $totalImpuestos,
                'total' => $request->total,
                'tipo_cambio' => $turno->tipo_cambio,
                'status' => 'Completada',
                'cliente_id' => $clienteId,
                'via_venta' => $request->via_venta ?? 'MOSTRADOR'
            ]);

            $venta->detalles()->createMany($detallesParaInsertar);
            $this->guardaCambiosDePrecio($request, $venta);

            if ($request->tipo_pago === 'Contado') {
                foreach ($request->pagos as $pago) {
                    $venta->pagos()->create([
                        'metodo_pago' => $pago['metodo_pago'],
                        'monto' => $pago['monto'],
                        'moneda' => $pago['moneda'] ?? 'MXN',
                        'monto_original' => $pago['monto_original'] ?? $pago['monto'],
                        'tipo_cambio_usado' => $pago['tc_aplicado'] ?? $turno->tipo_cambio,
                        'referencia_pago' => $pago['referencia_pago'] ?? null,
                        'tarjeta_ultimos_4' => $pago['tarjeta_ultimos_4'] ?? null,
                        'efectivo_recibido' => $pago['efectivo_recibido'] ?? null,
                        'cambio_entregado' => $pago['cambio_entregado'] ?? null,
                    ]);
                }
            }

            $configticket = Ticket::where('sucursale_id', $venta->sucursale_id)->first();
            $venta->load('cliente');

            return response()->json([
                'cliente' => $venta->cliente,
                'configticket' => $configticket,
                'message' => 'Venta finalizada',
                'folio' => $venta->folio,
                'id' => $venta->id,
                'via_venta' => $venta->via_venta,
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

    private function guardaCambiosDePrecio(Request $request, $venta)
    {
        foreach ($request->items as $item) {
            if (isset($item['motivo_cambio'])) {
                PrecioModificacion::create([
                    'venta_id'        => $venta->id,
                    'producto_id'     => $item['id'],
                    'user_id'         => auth()->id(),
                    'autorizado_por'  => $item['autorizado_por'],
                    'precio_original' => $item['precio_original'],
                    'precio_nuevo'    => $item['precio'],
                    'motivo'          => $item['motivo_cambio']
                ]);
            }
        }
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

    /**
     * Valida integralmente el estado crediticio de un cliente antes de procesar una venta.
     * * @param \App\Models\Cliente $cliente Instancia del cliente obtenida con lockForUpdate()
     * @param float $montoVenta Total de la transacción actual
     * @throws \Exception
     */
    private function validarCreditoCliente($cliente, $montoVenta)
    {
        // 1. Verificar si el cliente tiene habilitada la línea de crédito
        if ($cliente->limite_credito <= 0) {
            throw new \Exception("El cliente no tiene una línea de crédito autorizada en el sistema.");
        }

        // 2. Validar que el nuevo saldo no exceda el límite permitido
        $saldoProyectado = $cliente->saldo_actual + $montoVenta;
        if ($saldoProyectado > $cliente->limite_credito) {
            $disponible = $cliente->limite_credito - $cliente->saldo_actual;
            throw new \Exception(
                "Límite de crédito excedido. El saldo actual ($" . number_format($cliente->saldo_actual, 2) .
                ") más esta venta superan el límite de $" . number_format($cliente->limite_credito, 2) .
                ". Disponible: $" . number_format($disponible, 2)
            );
        }

        // 3. Validar morosidad si el cliente tiene restringida la venta con facturas vencidas
        if ($cliente->vender_vencido == 0) {
            $tieneVencidos = \App\Models\Venta::where('cliente_id', $cliente->id)
                ->where('tipo_pago', 'Crédito')
                ->where('status', 'Completada') // Solo ventas vigentes
                ->whereDate('fecha_vencimiento', '<', now()) // Que ya hayan vencido
                ->where(function ($query) {
                    // Filtramos solo aquellas que tengan un saldo pendiente mayor a 0.01
                    $query->whereRaw('total > (SELECT COALESCE(SUM(monto), 0) FROM venta_pagos WHERE venta_pagos.venta_id = ventas.id)');
                })
                ->exists();

            if ($tieneVencidos) {
                throw new \Exception(
                    "Operación rechazada: El cliente presenta facturas vencidas. " .
                    "Debe liquidar sus saldos atrasados antes de realizar nuevas compras a crédito."
                );
            }
        }
    }

    /**
     * Procesa el descuento físico y genera el movimiento de inventario.
     */
    private function descontarExistencia($producto, $cantidad, $sucursalId, $folio, $observacionExtra)
    {
        $stockSucursal = $producto->sucursales()
            ->where('sucursal_id', $sucursalId)
            ->first();

        if ($stockSucursal) {
            $nuevoStock = $stockSucursal->pivot->stock_actual - $cantidad;

            // Actualizamos tabla pivot de existencia
            $producto->sucursales()->updateExistingPivot($sucursalId, [
                'stock_actual' => $nuevoStock
            ]);

            // REGISTRAMOS EL MOVIMIENTO (Kardex)
            InventarioMovimiento::create([
                'producto_id'      => $producto->id,
                'sucursal_id'      => $sucursalId,
                'tipo_movimiento'  => 'SALIDA POR VENTA',
                'observaciones'    => "{$observacionExtra}. Folio: {$folio}",
                'cantidad'         => $cantidad,
                'referencia_tipo'  => 'VENTA',
                'stock_anterior'   => $stockSucursal->pivot->stock_actual,
                'stock_nuevo'      => $nuevoStock,
                'user_id'          => auth()->id()
            ]);
        }
    }
}
