<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class InventarioService
{
    public static function registrarMovimiento($productoId, $sucursalId, $cantidad, $tipoMovimiento, $refTipo = null, $refId = null, $observaciones = null)
    {
        DB::transaction(function () use ($productoId, $sucursalId, $cantidad, $tipoMovimiento, $refTipo, $refId, $observaciones) {

            // 1. Obtener o crear el registro de stock en la sucursal
            $sucursalProducto = DB::table('sucursal_productos')
                ->where('producto_id', $productoId)
                ->where('sucursal_id', $sucursalId)
                ->lockForUpdate() // Bloqueo para evitar colisiones de stock
                ->first();

            $stockAnterior = $sucursalProducto ? (float)$sucursalProducto->stock_actual : 0.000000;
            $stockNuevo = $stockAnterior + (float)$cantidad;

            // 2. Validar que no quede stock negativo si es una salida (Opcional según tu regla)
            if ($stockNuevo < 0 && !in_array($tipoMovimiento, ['Ajuste', 'Traspaso'])) {
                throw new Exception("Existencias insuficientes para realizar esta operación.");
            }

            // 3. Actualizar o Insertar en sucursal_productos
            DB::table('sucursal_productos')->updateOrInsert(
                ['producto_id' => $productoId, 'sucursal_id' => $sucursalId],
                [
                    'stock_actual' => $stockNuevo,
                    'updated_at' => now()
                ]
            );

            // 4. Crear el registro en el Kardex (inventario_movimientos)
            DB::table('inventario_movimientos')->insert([
                'sucursal_id'    => $sucursalId,
                'producto_id'    => $productoId,
                'user_id'        => Auth::id() ?? 1, // Fallback a sistema si no hay sesión
                'tipo_movimiento'=> $tipoMovimiento,
                'cantidad'       => $cantidad,
                'stock_anterior' => $stockAnterior,
                'stock_nuevo'    => $stockNuevo,
                'referencia_tipo'=> $refTipo,
                'referencia_id'  => $refId,
                'observaciones'  => $observaciones,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        });
    }
}
