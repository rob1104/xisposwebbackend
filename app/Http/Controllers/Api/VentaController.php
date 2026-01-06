<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CajaTurno;
use App\Models\Cliente;
use App\Models\InventarioMovimiento;
use App\Models\Producto;
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
        $request->validate([
            'cliente_id' => 'nullable|exists:clientes,id',
            'items' => 'required|array',
            'pagos' => 'required_if:tipo_pago,Contado|array',
            'pagos.*.monto' => 'required_with:pagos|numeric',
            'pagos.*.metodo_pago' => 'required_with:pagos',
            'total' => 'required|numeric',
            'tipo_pago' => 'required|in:Contado,Credito'
        ]);

        // Venta a Crédito requiere cliente específico
        if ($request->tipo_pago === 'Credito' && (is_null($request->cliente_id) || $request->cliente_id == 1)) {
            return response()->json(['message' => 'No se puede realizar una venta a crédito al público general.'], 422);
        }

        // 0. Obtenemos el turno y generamos el folio por adelantado
        $turno = CajaTurno::where('user_id', auth()->id())
            ->where('status', 'Abierto')
            ->firstOrFail();
        $folio = $this->generarFolioUnico($turno->sucursale_id);

        return DB::transaction(function () use ($request, $turno, $folio) {
            $totalSubtotal = 0;
            $totalImpuestos = 0;
            $detallesParaInsertar = [];

            $fechaVencimiento = now();
            $clienteId = $request->cliente_id;

            if ($request->tipo_pago === 'Credito') {
                // Bloqueamos la fila del cliente para evitar colisiones de saldo
                $cliente = Cliente::lockForUpdate()->find($clienteId);

                // Validación de límites y morosidad
                $this->validarCreditoCliente($cliente, $request->total);

                // Calculamos vencimiento y actualizamos saldo
                $fechaVencimiento = now()->addDays($cliente->dias_credito);
                $cliente->increment('saldo_actual', $request->total);
            }

            foreach ($request->items as $item) {
                // 1. Cargamos el producto con sus impuestos y sus componentes (hijos)
                $producto = Producto::with('impuestos')->findOrFail($item['id']);
                $tasaTotal = $producto->impuestos->sum('porcentaje') / 100;
                $precioFinalConImpuesto = (float)$item['precio'];
                $cantidad = (float)$item['cantidad'];

                // 2. Cálculos financieros (siempre sobre el producto PADRE para el detalle de venta)
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

                // 3. LÓGICA DE INVENTARIO SEGÚN TIPO
                if ($producto->tipo_producto === 'Compuesto') {
                    // Si es Kit: Descontamos de los HIJOS
                    foreach ($producto->componentes as $hijo) {
                        // Cantidad a descontar = (Cantidad requerida por el kit) * (Cantidad de kits vendidos)
                        $cantidadTotalHijo = $hijo->pivot->cantidad * $cantidad;

                        $this->descontarExistencia(
                            $hijo,
                            $cantidadTotalHijo,
                            $turno->sucursale_id,
                            $folio,
                            "VENTA KIT: {$producto->nombre}"
                        );
                    }
                } elseif ($producto->tipo_producto === 'Inventariable') {
                    // Si es simple: Descontamos del PADRE
                    $this->descontarExistencia(
                        $producto,
                        $cantidad,
                        $turno->sucursale_id,
                        $folio,
                        "VENTA DIRECTA"
                    );
                }
            }

            // 4. Creación de Cabecera, Detalles y Pagos (Igual que antes)
            $venta = Venta::create([
                'folio' => $folio,
                'sucursale_id' => $turno->sucursale_id,
                'user_id' => auth()->id(),
                'caja_turno_id' => $turno->id,
                'subtotal' => $totalSubtotal,
                'impuestos' => $totalImpuestos,
                'total' => $request->total,
                'tipo_cambio' => $turno->tipo_cambio,
                'tipo_pago' => $request->tipo_pago,
                'status' => 'Completada',
                'cliente_id' => $clienteId
            ]);

            $venta->detalles()->createMany($detallesParaInsertar);

            if ($request->tipo_pago === 'Contado') {
                foreach ($request->pagos as $pago) {
                    $venta->pagos()->create([
                        'metodo_pago' => $pago['metodo_pago'],
                        'monto' => $pago['monto'],
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
