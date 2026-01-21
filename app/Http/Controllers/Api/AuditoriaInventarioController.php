<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditoriaInventario;
use App\Models\InventarioMovimiento;
use App\Models\Producto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;

class AuditoriaInventarioController extends Controller
{
    public function index(Request $request)
    {
        $query = AuditoriaInventario::with(['user', 'sucursal'])
            ->orderBy('fecha', 'desc');

        if ($request->sucursal_id) {
            $query->where('sucursale_id', $request->sucursal_id);
        }
        return response()->json($query->paginate(15));
    }

    public function show($id)
    {
        $auditoria = AuditoriaInventario::with(['user', 'sucursal', 'detalles.producto'])
            ->findOrFail($id);

        return response()->json($auditoria);
    }

    public function obtenerProductosParaConteo($sucursal_id)
    {
        try {
            // Realizamos el leftJoin con el nombre correcto de la tabla: sucursal_productos
            $productos = Producto::leftJoin('sucursal_productos', function($join) use ($sucursal_id) {
                $join->on('productos.id', '=', 'sucursal_productos.producto_id')
                    // Usamos sucursal_id según tu estructura
                    ->where('sucursal_productos.sucursal_id', '=', $sucursal_id);
            })
                ->where('productos.status', true) // Solo productos activos
                    ->where('productos.tipo_producto', '!=', 'Compuesto')
                ->select(
                    'productos.id',
                    'productos.nombre',
                    'productos.codigo_barras',
                    // Seleccionamos stock_actual y usamos COALESCE para evitar nulos
                    DB::raw('COALESCE(sucursal_productos.stock_actual, 0) as stock_actual')
                )
                ->orderBy('productos.nombre', 'ASC')
                ->get();

            return response()->json($productos, 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al obtener los productos para el conteo.',
                'error' => $e.getMessage()
            ], 500);
        }
    }

    public function procesarConteo(Request $request)
    {
        $request->validate([
                'sucursal_id' => 'required|exists:sucursales,id',
                'productos' => 'required|array',
                'productos.*.id' => 'required|exists:productos,id',
                'productos.*.stock_fisico' => 'required|numeric|min:0'
            ]);

        return DB::transaction(function () use ($request) {
            // 1. Crear Cabecera de Auditoria
            $auditoria = AuditoriaInventario::create([
                'sucursale_id' => $request->sucursal_id,
                'user_id' => auth()->id(),
                'fecha' => now(),
                'observaciones' => $request->observaciones
            ]);

            foreach ($request->productos as $item) {
                $producto = Producto::find($item['id']);
                $stockSistema = $producto->stockEnSucursal($request->sucursal_id);
                $diferencia = $item['stock_fisico'] - $stockSistema;
                // 2. Registrar el Detalle
                $auditoria->detalles()->create([
                    'producto_id' => $item['id'],
                    'stock_sistema' => $stockSistema,
                    'stock_fisico' => $item['stock_fisico'],
                    'diferencia' => $diferencia
                ]);
                // 3. Si hay diferencia, generar Movimiento en Kardex y actualizar Stock
                if ($diferencia != 0) {
                    $tipoMov = $diferencia > 0 ? 'ENTRADA POR CONTEO FISICO' : 'SALIDA POR CONTEO FISICO';
                    InventarioMovimiento::create([
                        'producto_id' => $item['id'],
                        'sucursal_id' => $request->sucursal_id,
                        'tipo_movimiento' => $tipoMov,
                        'cantidad' => abs($diferencia),
                        'stock_anterior' => $stockSistema,
                        'stock_nuevo' => $item['stock_fisico'],
                        'observaciones' => "Ajuste por Auditoría #" . $auditoria->id,
                        'user_id' => auth()->id()
                    ]);
                    $producto->actualizarStockSucursal($request->sucursal_id, $item['stock_fisico']);
                }
            }
            return response()->json([
                'message' => 'Inventario cuadrado y Kardex actualizado correctamente.',
                'auditoria_id' => $auditoria->id
            ], 201);
        });
    }

    public function generaPDF($id)
    {
        $auditoria = AuditoriaInventario::with(['user', 'sucursal', 'detalles.producto'])->findOrFail($id);
        $pdf = Pdf::loadView('pdf.auditoria', compact('auditoria'));
        $pdf->setPaper('letter', 'portrait');
        return $pdf->stream("Reporte_Conteo_Fisico_{$auditoria->id}.pdf");
    }
}
