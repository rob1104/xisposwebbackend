<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sucursal;
use App\Models\Transferencia;
use App\Models\SucursalProducto;
use App\Models\InventarioMovimiento;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TransferenciaController extends Controller
{
    /**
     * PASO 1: Envío de Mercancía (Sucursal Origen)
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'sucursal_origen_id'  => 'required|exists:sucursales,id',
            'sucursal_destino_id' => 'required|exists:sucursales,id|different:sucursal_origen_id',
            'productos'           => 'required|array|min:1',
            'productos.*.id'      => 'required|exists:productos,id',
            'productos.*.cantidad'=> 'required|numeric|min:0.000001',
        ]);

        return DB::transaction(function () use ($validated, $request) {
            // 1. Crear cabecera
            $transfer = Transferencia::create([
                'sucursal_origen_id'  => $validated['sucursal_origen_id'],
                'sucursal_destino_id' => $validated['sucursal_destino_id'],
                'user_envia_id'       => Auth::id(),
                'estatus'             => 'Enviado',
                'fecha_envio'         => now(),
                'notas'               => $request->notas
            ]);

            foreach ($validated['productos'] as $prod) {
                // 2. Detalle
                $transfer->detalles()->create([
                    'producto_id'      => $prod['id'],
                    'cantidad_enviada' => $prod['cantidad'],
                ]);

                // 3. Afectar Stock Origen
                $stockOrigen = SucursalProducto::where('sucursal_id', $validated['sucursal_origen_id'])
                    ->where('producto_id', $prod['id'])
                    ->firstOrFail();

                $stockAnterior = (float) $stockOrigen->stock_actual;
                $stockNuevo = $stockAnterior - (float) $prod['cantidad'];

                $stockOrigen->update(['stock_actual' => $stockNuevo]);

                $sucursal = Sucursal::findOrFail($validated['sucursal_destino_id']);

                // 4. KARDEX - SALIDA POR TRASPASO
                InventarioMovimiento::create([
                    'sucursal_id'     => $validated['sucursal_origen_id'],
                    'producto_id'     => $prod['id'],
                    'user_id'         => Auth::id(),
                    'tipo_movimiento' => 'SALIDA POR TRASPASO',
                    'cantidad'        => $prod['cantidad'],
                    'stock_anterior'  => $stockAnterior,
                    'stock_nuevo'     => $stockNuevo,
                    'referencia_tipo' => 'Transferencia',
                    'referencia_id'   => $transfer->id,
                    'observaciones'   => "Envío a sucursal destino: {$sucursal->nombre}"
                ]);
            }

            return response()->json(['message' => 'Transferencia enviada y stock de origen descontado']);
        });
    }

    public function show($id)
    {
        return Transferencia::with(['sucursalOrigen', 'sucursalDestino', 'userEnvia', 'detalles.producto'])
            ->findOrFail($id);
    }

    public function pendientes()
    {
        $user = auth()->user();
        $query = Transferencia::with(['sucursalOrigen', 'sucursalDestino', 'userEnvia', 'detalles.producto'])
            ->where('estatus', 'Enviado');

        $roles = $user->roles->pluck('name');

        // Si NO es administrador, filtrar solo lo que va hacia SU sucursal
        if ($roles[0] !== 'Administrador') {
            $query->where('sucursal_destino_id', $user->sucursal_id);
        }

        return $query->orderBy('fecha_envio', 'desc')->get();
    }

    public function recibir(Request $request, $id)
    {
        $transferencia = Transferencia::findOrFail($id);

        return DB::transaction(function () use ($request, $transferencia) {
            foreach ($request->productos as $item) {
                // 1. Actualizar detalle
                $detalle = $transferencia->detalles()->where('producto_id', $item['producto_id'])->first();
                $detalle->update(['cantidad_recibida' => $item['cantidad_recibida']]);

                // 2. Afectar Stock Destino (Update or Create)
                $stockDestino = SucursalProducto::firstOrCreate(
                    ['sucursal_id' => $transferencia->sucursal_destino_id, 'producto_id' => $item['producto_id']],
                    ['stock_actual' => 0]
                );

                $stockAnterior = (float) $stockDestino->stock_actual;
                $stockNuevo = $stockAnterior + (float) $item['cantidad_recibida'];

                $stockDestino->update(['stock_actual' => $stockNuevo]);

                $sucursal = Sucursal::findOrFail($transferencia->sucursal_destino_id);

                // 3. KARDEX - ENTRADA POR TRASPASO
                InventarioMovimiento::create([
                    'sucursal_id'     => $transferencia->sucursal_destino_id,
                    'producto_id'     => $item['producto_id'],
                    'user_id'         => Auth::id(),
                    'tipo_movimiento' => 'ENTRADA POR TRASPASO',
                    'cantidad'        => $item['cantidad_recibida'],
                    'stock_anterior'  => $stockAnterior,
                    'stock_nuevo'     => $stockNuevo,
                    'referencia_tipo' => 'Transferencia',
                    'referencia_id'   => $transferencia->id,
                    'observaciones'   => "Recepción desde sucursal origen #{$sucursal->nombre}"
                ]);
            }

            $transferencia->update([
                'estatus' => 'Recibido',
                'user_recibe_id' => Auth::id(),
                'fecha_recepcion' => now(),
            ]);

            return response()->json(['message' => 'Inventario actualizado y recepción completada']);
        });
    }
}
