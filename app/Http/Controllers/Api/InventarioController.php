<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventarioMovimiento;
use App\Models\Producto;
use App\Models\Sucursal;
use App\Models\SucursalProducto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class InventarioController extends Controller
{

    public function index()
    {
        return InventarioMovimiento::with(['producto', 'sucursal', 'user'])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function buscarProducto(Request $request)
    {
        $query = $request->input('q'); // Término de búsqueda (nombre o código)

        if (!$query) return response()->json([]);

        // Buscamos productos que coincidan por nombre o SKU
        $productos = Producto::where('nombre', 'LIKE', "%{$query}%")
            ->orWhere('codigo_barras', 'LIKE', "%{$query}%")
            ->with('categoria')
            ->limit(10) // Limitamos para mejorar el rendimiento
            ->get();

        return response()->json($productos);
    }

    public function obtenerStockActual(Request $request)
    {
        $request->validate([
            'producto_id' => 'required',
            'sucursal_id' => 'required'
        ]);

        $stock = SucursalProducto::where('producto_id', $request->producto_id)
            ->where('sucursal_id', $request->sucursal_id)
            ->first();

        return response()->json([
            'stock_actual' => $stock ? $stock->stock_actual : 0
        ]);
    }

    public function reporteStock(Request $request)
    {
        // El ID viene del interceptor de axios.js
        $sucursalId = $request->header('X-Sucursal-Id');

        if (!$sucursalId) {
            return response()->json(['message' => 'Sucursal no seleccionada'], 400);
        }

        // Consultamos la tabla sucursal_productos
        $inventario = SucursalProducto::where('sucursal_id', $sucursalId)
            ->with(['producto.categoria']) // Traemos los datos del producto y su categoría
            ->get()
            ->map(function ($item) {
                // Formateamos la respuesta para que el frontend la lea fácilmente
                return [
                    'id' => $item->id,
                    'codigo_barras' => $item->producto->codigo_barras,
                    'nombre' => $item->producto->nombre,
                    'stock_actual' => $item->stock_actual,
                    'categoria' => $item->producto->categoria->nombre ?? 'Sin Categoría',
                    'stock_minimo' => $item->stock_minimo
                ];
            });

        return response()->json($inventario);
    }

    public function store(Request $request)
    {
        $sucursalId = $request->header('X-Sucursal-Id'); //

        $request->validate([
            'producto_id' => 'required|exists:productos,id',
            'cantidad' => 'required|numeric|min:0',
        ]);

        // Buscamos si ya existe el registro en esa sucursal o lo creamos
        $registro = SucursalProducto::updateOrCreate(
            ['sucursal_id' => $sucursalId, 'producto_id' => $request->producto_id],
            ['stock_actual' => \DB::raw("stock_actual + {$request->cantidad}")]
        );

        return response()->json(['message' => 'Stock actualizado', 'data' => $registro]);
    }

    public function registrarMovimiento(Request $request)
    {
        // 1. Validar los datos de entrada
        $request->validate([
            'sucursal_id' => 'required|exists:sucursales,id',
            'producto_id' => 'required|exists:productos,id',
            'tipo' => 'required|in:ENTRADA,SALIDA,AJUSTE',
            'cantidad' => 'required|numeric',
            'observaciones' => 'nullable|string'
        ]);

        return DB::transaction(function () use ($request) {
            // 2. Obtener o crear el registro de stock actual en la sucursal
            $stock = SucursalProducto::firstOrCreate(
                [
                    'sucursal_id' => $request->sucursal_id,
                    'producto_id' => $request->producto_id
                ],
                [
                    'stock_actual' => 0,
                    'cantidad' => 0 // Si usas este campo como duplicado del stock
                ]
            );

            $stockAnterior = $stock->stock_actual;
            $cantidadMovimiento = $request->cantidad;
            $tipoFinal = $request->tipo;
            $stockNuevo = 0;

            // 3. Lógica de cálculo según el tipo de movimiento
            if ($request->tipo === 'AJUSTE') {
                // En AJUSTE, $request->cantidad es el stock deseado
                $diferencia = $request->cantidad - $stockAnterior;
                $tipoFinal = $diferencia >= 0 ? 'ENTRADA (AJUSTE)' : 'SALIDA (AJUSTE)';
                $cantidadMovimiento = abs($diferencia);
                $stockNuevo = $request->cantidad;
            } elseif ($request->tipo === 'ENTRADA') {
                $stockNuevo = $stockAnterior + $request->cantidad;
            } elseif ($request->tipo === 'SALIDA') {
                $stockNuevo = $stockAnterior - $request->cantidad;
            }

            // 4. Actualizar la tabla sucursal_productos
            $stock->stock_actual = $stockNuevo;
            $stock->cantidad = $stockNuevo; // Mantener consistencia si usas ambos campos
            $stock->save();

            // 5. Crear el registro en inventario_movimientos
            $movimiento = InventarioMovimiento::create([
                'sucursal_id' => $request->sucursal_id,
                'producto_id' => $request->producto_id,
                'user_id' => Auth::id(), // El ID del administrador logueado
                'tipo_movimiento' => $tipoFinal,
                'cantidad' => $cantidadMovimiento,
                'stock_anterior' => $stockAnterior,
                'stock_nuevo' => $stockNuevo,
                'observaciones' => $request->observaciones
            ]);

            return response()->json([
                'message' => 'Movimiento registrado con éxito',
                'data' => $movimiento
            ], 201);
        });
    }

    public function getSucursales()
    {
        return response()->json(Sucursal::all(['id', 'nombre']));
    }

    /**
     * Consulta el stock de un producto en todas las sucursales.
     * Útil para la pestaña de "Existencias" en el catálogo de productos.
     */
    public function stockGlobal($producto_id)
    {
        $existencias = DB::table('sucursales as s')
            ->leftJoin('sucursal_productos as sp', function($join) use ($producto_id) {
                $join->on('s.id', '=', 'sp.sucursal_id')
                    ->where('sp.producto_id', '=', $producto_id);
            })
            ->select(
                's.id as sucursal_id',
                's.nombre as sucursal_nombre',
                // Si no hay registro, devolvemos 0 con precisión decimal
                DB::raw('COALESCE(sp.stock_actual, 0.000000) as stock_actual'),
                DB::raw('COALESCE(sp.stock_minimo, 0.000000) as stock_minimo'),
                DB::raw('COALESCE(sp.stock_maximo, 0.000000) as stock_maximo'),
                DB::raw('COALESCE(sp.costo_promedio, 0.000000) as costo_promedio')
            )
            ->get();

        return response()->json($existencias);
    }

    /**
     * Obtiene el historial detallado de movimientos (Kardex) de un producto.
     */
    public function kardexPorProducto($producto_id)
    {
        $movimientos = DB::table('inventario_movimientos as m')
            ->join('sucursales as s', 'm.sucursal_id', '=', 's.id')
            ->join('users as u', 'm.user_id', '=', 'u.id')
            ->where('m.producto_id', $producto_id)
            ->select(
                'm.id',
                's.nombre as sucursal',
                'u.name as usuario',
                'm.tipo_movimiento',
                'm.cantidad',
                'm.stock_anterior',
                'm.stock_nuevo',
                'm.referencia_tipo',
                'm.referencia_id',
                'm.observaciones',
                'm.created_at'
            )
            ->orderBy('m.created_at', 'desc')
            ->get();

        return response()->json($movimientos);
    }

    /**
     * Genera un resumen del valor del inventario por sucursal.
     */
    public function reporteValorizado($sucursal_id)
    {
        $reporte = DB::table('sucursal_productos as sp')
            ->join('productos as p', 'sp.producto_id', '=', 'p.id')
            ->where('sp.sucursal_id', $sucursal_id)
            ->where('sp.stock_actual', '>', 0)
            ->select(
                'p.nombre',
                'p.codigo_barras',
                'sp.stock_actual',
                'sp.costo_promedio',
                // Cálculo del valor de la inversión
                DB::raw('(sp.stock_actual * sp.costo_promedio) as valor_total')
            )
            ->orderBy('p.nombre')
            ->get();

        $totalInversion = $reporte->sum('valor_total');

        return response()->json([
            'productos' => $reporte,
            'total_inversion' => $totalInversion
        ]);
    }

    public function reporteConsolidado()
    {
        // Obtenemos todos los productos y sus existencias por sucursal
        $productos = Producto::with(['categoria', 'sucursalProductos'])->get();

        return $productos->map(function ($p) {
            $data = [
                'id' => $p->id,
                'nombre' => $p->nombre,
                'codigo_barras' => $p->codigo_barras,
                'categoria' => $p->categoria?->nombre,
                'total' => 0
            ];

            // Mapeamos cada sucursal a una llave dinámica 'sucursal_ID'
            foreach ($p->sucursalProductos as $sp) {
                $data["sucursal_{$sp->sucursal_id}"] = (float)$sp->stock_actual; // Precisión 14,6
                $data['total'] += (float)$sp->stock_actual;
            }

            return $data;
        });
    }

    public function getStock(Request $request) {
        $user = auth()->user();

        if ($user->hasRole('Administrador')) {
            // El administrador ve todo
            return SucursalProducto::all();
        }

        // El gerente solo ve su sucursal asignada
        return SucursalProducto::where('sucursal_id', $user->sucursal_id)->get();
    }

    public function stockPorSucursal()
    {
        $user = auth()->user();
        $sucursalId = config('app.current_sucursal_id');

        // 1. El Administrador puede ver todo (Administración Global)
        if ($user->role === 'Administrador' && !$sucursalId) {
            return SucursalProducto::with('producto')->get();
        }

        // 2. Si es Gerente o el Admin seleccionó una sucursal específica
        // Usamos la precisión 14,6 para los cálculos
        return SucursalProducto::where('sucursal_id', $sucursalId)
            ->with('producto')
            ->get();
    }
}
