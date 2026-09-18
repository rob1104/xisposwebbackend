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
        $inicio = $request->query('inicio');
        $fin = $request->query('fin');

        $query = Venta::with(['cliente', 'pagos', 'user'])
            ->orderBy('created_at', 'desc');
            
        if ($sucursalId) {
            $query->where('sucursale_id', $sucursalId);
        }

        if ($inicio && $fin) {
            // Include entire end day
            $finDate = \Carbon\Carbon::parse($fin)->endOfDay();
            $query->whereBetween('created_at', [$inicio, $finDate]);
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
            'via_venta' => 'nullable|string',
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
                    // Si es Kit, debemos bloquear CADA ingrediente, permitiendo kits dentro de kits
                    $this->deducirKitRecursivo($producto, $cantidadVenta, $turno->sucursale_id, $folio, "VENTA KIT: {$producto->nombre}");
                }

                // NUEVO: Lógica de Modificadores (Inventario)
                $modificadoresJson = null;
                if (isset($item['modificadores']) && is_array($item['modificadores'])) {
                    $modificadoresJson = json_encode($item['modificadores']);
                    foreach ($item['modificadores'] as $modOp) {
                        $opcion = \App\Models\ModificadorOpcion::with('grupo')->find($modOp['id']);
                        if (!$opcion) continue;

                        $isMitad = ($opcion->grupo->tipo === 'mitad_y_mitad');
                        $multiplicadorReceta = $isMitad ? 0.5 : 1.0;
                        
                        // A. Descuento de Receta Asociada (Ej. Mitad Peperoni -> receta de Pizza Peperoni / 2)
                        if ($opcion->producto_receta_id) {
                            $receta = Producto::with('componentes')->find($opcion->producto_receta_id);
                            if ($receta && $receta->tipo_producto === 'Compuesto') {
                                $this->deducirKitRecursivo($receta, $cantidadVenta * $multiplicadorReceta, $turno->sucursale_id, $folio, "Modificador: {$opcion->nombre}");
                            }
                        }
                        // B. Descuento de Ingrediente Directo (Ej. Extra Queso)
                        if ($opcion->ingrediente_id && $opcion->cantidad_descuento > 0) {
                            $ing = Producto::find($opcion->ingrediente_id);
                            if ($ing) {
                                $cantidadReq = $opcion->cantidad_descuento * $cantidadVenta;
                                $this->deducirHijoBloqueando($ing, $cantidadReq, $turno->sucursale_id, $folio, "Modificador Directo: {$opcion->nombre}");
                            }
                        }
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
                    'modificadores_json' => $modificadoresJson,
                ];
            }

            // 3. Creación de Venta y Pagos
            $uuidVenta = (string)Str::uuid();
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
                'uuid' => $uuidVenta,
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

            $qrData = json_encode([
                'u' => $uuidVenta,
                'f' => $venta->id,
                's' => $turno->sucursale_id,
                'c' => auth()->id(),
                'l' => $request->cliente_id ?? 0,
                't' => (float) $venta->total
            ]);

            return response()->json([
                'cliente' => $venta->cliente,
                'configticket' => $configticket,
                'message' => 'Venta finalizada',
                'folio' => $venta->folio,
                'id' => $venta->id,
                'via_venta' => $venta->via_venta,
                'qr_data' => $qrData
            ]);
        });
    }

    public function cancelar(Request $request, $id)
    {
        $request->validate(['motivo' => 'required|string|min:5']);

        return DB::transaction(function () use ($request, $id) {
            $venta = Venta::with(['detalles.producto.componentes'])->findOrFail($id);

            if ($venta->status === 'Cancelada') {
                return response()->json(['error' => 'Esta venta ya fue anulada anteriormente.'], 422);
            }

            foreach ($venta->detalles as $detalle) {
                $producto = $detalle->producto;

                if ($producto->tipo_producto === 'Inventariable') {
                    $this->restaurarHijoBloqueando($producto, $detalle->cantidad, $venta->sucursale_id, $venta->folio, "Cancelación de venta");
                } elseif ($producto->tipo_producto === 'Compuesto') {
                    $this->restaurarKitRecursivo($producto, $detalle->cantidad, $venta->sucursale_id, $venta->folio, "Devolución KIT: {$producto->nombre}");
                }

                if ($detalle->modificadores_json) {
                    $modificadores = json_decode($detalle->modificadores_json, true);
                    if (is_array($modificadores)) {
                        foreach ($modificadores as $modOp) {
                            $opcion = \App\Models\ModificadorOpcion::with('grupo')->find($modOp['id']);
                            if (!$opcion) continue;

                            $isMitad = ($opcion->grupo->tipo === 'mitad_y_mitad');
                            $multiplicadorReceta = $isMitad ? 0.5 : 1.0;

                            if ($opcion->producto_receta_id) {
                                $receta = Producto::with('componentes')->find($opcion->producto_receta_id);
                                if ($receta && $receta->tipo_producto === 'Compuesto') {
                                    $this->restaurarKitRecursivo($receta, $detalle->cantidad * $multiplicadorReceta, $venta->sucursale_id, $venta->folio, "Devolución Modificador: {$opcion->nombre}");
                                }
                            }

                            if ($opcion->ingrediente_id && $opcion->cantidad_descuento > 0) {
                                $ing = Producto::find($opcion->ingrediente_id);
                                if ($ing) {
                                    $cantidadReq = $opcion->cantidad_descuento * $detalle->cantidad;
                                    $this->restaurarHijoBloqueando($ing, $cantidadReq, $venta->sucursale_id, $venta->folio, "Devolución Modificador Directo: {$opcion->nombre}");
                                }
                            }
                        }
                    }
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



    private function deducirKitRecursivo($producto, $cantidadTotal, $sucursalId, $folio, $observacionBase)
    {
        if ($producto->tipo_producto === 'Inventariable') {
            $this->deducirHijoBloqueando($producto, $cantidadTotal, $sucursalId, $folio, $observacionBase);
        } elseif ($producto->tipo_producto === 'Compuesto') {
            // Load componentes if not loaded
            $producto->loadMissing('componentes');
            foreach ($producto->componentes as $hijo) {
                $cantidadReq = $hijo->pivot->cantidad * $cantidadTotal;
                $this->deducirKitRecursivo($hijo, $cantidadReq, $sucursalId, $folio, "{$observacionBase} -> {$hijo->nombre}");
            }
        }
    }

    private function deducirHijoBloqueando($hijo, $cantidadRequerida, $sucursalId, $folio, $observacion)
    {
        $pivotHijo = DB::table('sucursal_productos')
            ->where('sucursal_id', $sucursalId)
            ->where('producto_id', $hijo->id)
            ->lockForUpdate()
            ->first();

        $stockHijo = $pivotHijo ? $pivotHijo->stock_actual : 0;

        if ($stockHijo < $cantidadRequerida) {
            throw new \Exception("Stock insuficiente de ingrediente ({$hijo->nombre}) para $observacion. Stock: $stockHijo, Requerido: $cantidadRequerida");
        }

        $nuevoStockHijo = $stockHijo - $cantidadRequerida;
        DB::table('sucursal_productos')
            ->where('id', $pivotHijo->id)
            ->update(['stock_actual' => $nuevoStockHijo]);

        InventarioMovimiento::create([
            'producto_id'      => $hijo->id,
            'sucursal_id'      => $sucursalId,
            'tipo_movimiento'  => 'SALIDA POR VENTA',
            'observaciones'    => "{$observacion}. Folio: {$folio}",
            'cantidad'         => $cantidadRequerida,
            'referencia_tipo'  => 'VENTA',
            'stock_anterior'   => $stockHijo,
            'stock_nuevo'      => $nuevoStockHijo,
            'user_id'          => auth()->id()
        ]);
    }

    private function restaurarKitRecursivo($producto, $cantidadTotal, $sucursalId, $folio, $observacionBase)
    {
        if ($producto->tipo_producto === 'Inventariable') {
            $this->restaurarHijoBloqueando($producto, $cantidadTotal, $sucursalId, $folio, $observacionBase);
        } elseif ($producto->tipo_producto === 'Compuesto') {
            // Load componentes if not loaded
            $producto->loadMissing('componentes');
            foreach ($producto->componentes as $hijo) {
                $cantidadReq = $hijo->pivot->cantidad * $cantidadTotal;
                $this->restaurarKitRecursivo($hijo, $cantidadReq, $sucursalId, $folio, "{$observacionBase} -> {$hijo->nombre}");
            }
        }
    }

    private function restaurarHijoBloqueando($hijo, $cantidadRequerida, $sucursalId, $folio, $observacion)
    {
        $stockPivotHijo = DB::table('sucursal_productos')
            ->where('producto_id', $hijo->id)
            ->where('sucursal_id', $sucursalId)
            ->first();

        if ($stockPivotHijo) {
            $nuevoStock = $stockPivotHijo->stock_actual + $cantidadRequerida;
            DB::table('sucursal_productos')
                ->where('id', $stockPivotHijo->id)
                ->update(['stock_actual' => $nuevoStock]);

            InventarioMovimiento::create([
                'producto_id'  => $hijo->id,
                'sucursal_id' => $sucursalId,
                'tipo_movimiento' => 'ENTRADA (CANCELACION DE VENTA)',
                'observaciones' => "{$observacion} Folio: {$folio}",
                'cantidad' => $cantidadRequerida,
                'referencia_tipo' => 'CANCELACION',
                'stock_anterior' => $stockPivotHijo->stock_actual,
                'stock_nuevo' => $nuevoStock,
                'user_id' => auth()->id()
            ]);
        }
    }
}
