<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transferencia;
use App\Services\InventarioService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TransferenciaController extends Controller
{
    /**
     * Registra y envía una transferencia entre sucursales.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'sucursal_origen_id'  => 'required|exists:sucursales,id',
            'sucursal_destino_id' => 'required|exists:sucursales,id|different:sucursal_origen_id',
            'notas'               => 'nullable|string|max:255',
            'productos'           => 'required|array|min:1',
            'productos.*.id'       => 'required|exists:productos,id',
            'productos.*.cantidad' => 'required|numeric|min:0.000001', // Precisión 14,6
        ]);

        try {
            $transferencia = DB::transaction(function () use ($validated) {
                // 1. Crear la cabecera de la transferencia
                $transfer = Transferencia::create([
                    'sucursal_origen_id'  => $validated['sucursal_origen_id'],
                    'sucursal_destino_id' => $validated['sucursal_destino_id'],
                    'user_envia_id'       => Auth::id(),
                    'estatus'             => 'Enviado',
                    'fecha_envio'         => now(),
                    'notas'               => $validated['notas']
                ]);

                foreach ($validated['productos'] as $prod) {
                    // 2. Registrar el detalle de la transferencia
                    $transfer->detalles()->create([
                        'producto_id'      => $prod['id'],
                        'cantidad_enviada' => $prod['cantidad'],
                    ]);

                    // 3. Descontar stock de la sucursal ORIGEN usando el Servicio
                    // Se envía el valor en negativo para representar la salida
                    InventarioService::registrarMovimiento(
                        $prod['id'],
                        $validated['sucursal_origen_id'],
                        -$prod['cantidad'],
                        'Traspaso (Salida)',
                        'Transferencia',
                        $transfer->id,
                        "Envío a sucursal destino #{$validated['sucursal_destino_id']}"
                    );
                }

                return $transfer;
            });

            return response()->json([
                'message' => 'Transferencia enviada correctamente',
                'data' => $transferencia->load('detalles.producto')
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error en el envío: ' . $e->getMessage()
            ], 500);
        }
    }

    // app/Http/Controllers/Api/TransferenciaController.php

    /**
     * Lista transferencias enviadas hacia la sucursal del usuario
     */
    public function pendientes()
    {
        // En un sistema real, filtraríamos por la sucursal del usuario autenticado
        return Transferencia::with(['sucursalOrigen', 'userEnvia', 'detalles.producto'])
            ->where('estatus', 'Enviado')
            ->orderBy('fecha_envio', 'desc')
            ->get();
    }

    /**
     * Procesa la recepción física de la mercancía
     */
    public function recibir(Request $request, $id)
    {
        $transferencia = Transferencia::findOrFail($id);

        return DB::transaction(function () use ($request, $transferencia) {
            $transferencia->update([
                'estatus' => 'Recibido',
                'user_recibe_id' => Auth::id(),
                'fecha_recepcion' => now(),
            ]);

            foreach ($request->productos as $item) {
                // 1. Actualizar la cantidad realmente recibida en el detalle
                $detalle = $transferencia->detalles()->where('producto_id', $item['producto_id'])->first();
                $detalle->update(['cantidad_recibida' => $item['cantidad_recibida']]);

                // 2. Sumar stock a la sucursal DESTINO usando el Servicio
                // Usamos la precisión 14,6 definida en la tabla sucursal_productos
                InventarioService::registrarMovimiento(
                    $item['producto_id'],
                    $transferencia->sucursal_destino_id,
                    $item['cantidad_recibida'],
                    'Traspaso (Entrada)',
                    'Transferencia',
                    $transferencia->id,
                    "Recepción desde sucursal #{$transferencia->sucursal_origen_id}"
                );
            }

            return response()->json(['message' => 'Inventario actualizado correctamente']);
        });
    }
}
