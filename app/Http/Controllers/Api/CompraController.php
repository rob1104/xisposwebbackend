<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Compra;
use App\Models\InventarioMovimiento;
use App\Models\Sucursal;
use App\Models\SucursalProducto;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CompraController extends Controller
{
    public function index(Request $request)
    {
        // 1. Obtenemos el usuario y la sucursal de la cabecera
        $user = Auth::user();
        $sucursal_id = $request->header('X-Sucursal-Id');
        // 2. Iniciamos la consulta con las relaciones necesarias
        $query = Compra::with(['provider', 'user', 'sucursal']);
        // 3. Aplicamos la lógica de visualización por rol
        if($user->roles[0] !== 'Administrador')
            $query->where('sucursale_id', $sucursal_id);
        // Si es admin, no entra al 'where' y trae todas las sucursales
        $compras = $query->orderBy('created_at', 'desc')->get();

        return response()->json($compras);
    }

    public function store(Request $request)
    {
        $request->validate([
            'provider_id' => 'required|exists:providers,id',
            'sucursale_id' => 'nullable|exists:sucursales,id',
            'metodo_pago' => 'required|in:CONTADO,CREDITO',
            'fecha' => 'required|date',
            'subtotal' => 'required|numeric',
            'iva' => 'required|numeric',
            'total' => 'required|numeric',
            'detalles' => 'required|array|min:1',
            'detalles.*.producto_id' => 'required|exists:productos,id',
            'detalles.*.cantidad' => 'required|numeric|min:0.000001',
            'detalles.*.costo_unitario' => 'required|numeric|min:0',
        ]);

        $sucursalId = $request->sucursale_id ?? $request->header('X-Sucursal-Id');

        if (!$sucursalId) {
            return response()->json(['error' => 'Debe especificar una sucursal para registrar la compra.'], 400);
        }

        return DB::transaction(function () use ($request, $sucursalId) {
            $fechaConHora = Carbon::parse($request->fecha)->setTimeFrom(now());
            // 2. Crear la cabecera de la compra
            $compra = Compra::create([
                'sucursale_id' => $sucursalId,
                'provider_id' => $request->provider_id,
                'user_id' => Auth::id(),
                'folio' => $this->generarFolioUnico($sucursalId),
                'referencia' => $request->referencia,
                'fecha' => $fechaConHora,
                'subtotal' => $request->subtotal,
                'iva' => $request->iva,
                'total' => $request->total,
                'metodo_pago' => $request->metodo_pago,
                'saldo' => $request->metodo_pago === 'CREDITO' ? $request->total : 0,
                'estatus' => $request->metodo_pago === 'CREDITO' ? 'PENDIENTE' : 'PAGADA',
                'fecha_vencimiento' => $request->fecha_vencimiento,
                'observaciones' => $request->observaciones
            ]);

            foreach ($request->detalles as $item) {

                // A. Guardar detalle de la compra
                $detalle = $compra->detalles()->create([
                    'producto_id' => $item['producto_id'],
                    'cantidad' => $item['cantidad'],
                    'costo_unitario' => $item['costo_unitario'],
                    'importe' => $item['cantidad'] * $item['costo_unitario']
                ]);

                // B. Actualizar stock en sucursal_productos
                $stock = SucursalProducto::firstOrCreate(
                    [
                        'sucursal_id' => $sucursalId,
                        'producto_id' => $item['producto_id']
                    ],
                    ['stock_actual' => 0]
                );

                $stockAnterior = $stock->stock_actual;
                $stockNuevo = $stockAnterior + $item['cantidad'];

                $stock->update([
                    'stock_actual' => $stockNuevo,
                    'costo_promedio' => $this->calcularCostoPromedio($stock, $item) // Opcional
                ]);

                // C. Registrar movimiento histórico
                InventarioMovimiento::create([
                    'sucursal_id' => $sucursalId,
                    'producto_id' => $item['producto_id'],
                    'user_id' => Auth::id(),
                    'tipo_movimiento' => 'ENTRADA POR COMPRA',
                    'cantidad' => $item['cantidad'],
                    'stock_anterior' => $stockAnterior,
                    'stock_nuevo' => $stockNuevo,
                    'referencia_tipo' => 'COMPRA',
                    'referencia_id' => $compra->id,
                    'observaciones' => "Compra registrada con folio: " . $compra->folio
                ]);
            }

            return response()->json([
                'message' => 'Compra registrada exitosamente e inventario actualizado',
                'compra' => $compra->load('detalles')
            ], 201);
        });
    }

    public function cancelar(Request $request, $id)
    {
        $request->validate([
            'motivo' => 'required|string|min:5'
        ]);

        return DB::transaction(function () use ($request, $id) {
            $compra = Compra::with('detalles')->findOrFail($id);

            if ($compra->estatus === 'CANCELADA') {
                return response()->json(['error' => 'Esta compra ya está cancelada'], 422);
            }

            // 1. Cambiar estatus y guardar motivo en observaciones
            $compra->update([
                'estatus' => 'CANCELADA',
                'observaciones' => $compra->observaciones . " | MOTIVO CANCELACIÓN: " . $request->motivo
            ]);

            foreach ($compra->detalles as $detalle) {
                // 2. Obtener stock actual en la sucursal
                $stock = SucursalProducto::where('sucursal_id', $compra->sucursale_id)
                    ->where('producto_id', $detalle->producto_id)
                    ->first();

                $stockAnterior = $stock->stock_actual;
                $stockNuevo = $stockAnterior - $detalle->cantidad;

                // 3. Revertir el stock
                $stock->update(['stock_actual' => $stockNuevo]);

                // 4. Registrar movimiento de SALIDA
                InventarioMovimiento::create([
                    'sucursal_id' => $compra->sucursale_id,
                    'producto_id' => $detalle->producto_id,
                    'user_id' => Auth::id(),
                    'tipo_movimiento' => 'SALIDA (CANCELACION DE COMPRA)',
                    'cantidad' => $detalle->cantidad,
                    'stock_anterior' => $stockAnterior,
                    'stock_nuevo' => $stockNuevo,
                    'referencia_tipo' => 'COMPRA_CANCELADA',
                    'referencia_id' => $compra->id,
                    'observaciones' => "Cancelación de compra folio: " . $compra->folio
                ]);
            }

            return response()->json(['message' => 'Compra cancelada e inventario actualizado']);
        });
    }

    private function generarFolioUnico($sucursalId)
    {
        $sucursal = Sucursal::find($sucursalId);
        $prefijo = $sucursal && $sucursal->prefijo ? strtoupper($sucursal->prefijo) : 'GEN';
        $consecutivo = Compra::where('sucursale_id', $sucursalId)->count() + 1;
        return sprintf("%s-%s", $prefijo, str_pad($consecutivo, 8, '0', STR_PAD_LEFT));
    }

    private function calcularCostoPromedio($stock, $nuevoItem)
    {
        // Esta es una fórmula básica de costo promedio ponderado
        $valorActual = $stock->stock_actual * ($stock->costo_promedio ?? $nuevoItem['costo_unitario']);
        $valorNuevo = $nuevoItem['cantidad'] * $nuevoItem['costo_unitario'];
        $stockTotal = $stock->stock_actual + $nuevoItem['cantidad'];

        return $stockTotal > 0 ? ($valorActual + $valorNuevo) / $stockTotal : $nuevoItem['costo_unitario'];
    }
}
