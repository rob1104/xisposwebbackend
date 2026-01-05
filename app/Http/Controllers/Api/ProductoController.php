<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductoRequest;
use App\Models\Producto;
use App\Models\Sucursal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductoController extends Controller
{
    public function index()
    {
        return Producto::with(['categoria', 'impuestos', 'precios', 'componentes'])
            ->where('status', true)
            ->get();
    }

    public function show(Producto $producto)
    {
        // Cargamos el producto con sus precios, impuestos y componentes del kit
        return $producto->load(['precios', 'impuestos', 'componentes']);
    }

    public function store(ProductoRequest $request)
    {
        return DB::transaction(function () use ($request) {
            $producto = Producto::create($request->validated() + [
                    'usuario_creador' => auth()->user()->name
                ]);
            $this->syncRelations($producto, $request);
            $sucursales = Sucursal::all();
            foreach ($sucursales as $sucursal) {
                $producto->sucursales()->attach($sucursal->id, [
                    'stock_actual'   => 0,
                    'costo_promedio' => $request->ultimo_costo_compra
                ]);
            }
            return response()->json(['message' => 'Producto registrado exitosamente', 'data' => $producto], 201);
        });
    }

    public function update(ProductoRequest $request, Producto $producto)
    {
        return DB::transaction(function () use ($request, $producto) {
            $producto->update($request->only([
                'nombre', 'codigo_barras', 'categoria_id', 'tipo_producto',
                'clave_prod_serv', 'clave_unidad'
            ]));

            if ($request->has('impuestos')) {
                $producto->impuestos()->sync($request->impuestos);
            }

            if ($request->has('precios')) {
                $producto->precios()->delete();
                $producto->precios()->createMany($request->precios);
            }

            if ($request->tipo_producto === 'Compuesto' && $request->has('componentes')) {
                $syncData = [];
                foreach ($request->componentes as $item) {
                    $syncData[$item['id']] = ['cantidad' => $item['cantidad']];
                }
                $producto->componentes()->sync($syncData);
            }
            return response()->json([
                'message' => 'Producto actualizado con éxito',
                'data' => $producto->load(['precios', 'impuestos', 'componentes'])
            ]);
        });
    }

    public function destroy(Producto $producto)
    {
        // Verificamos si es parte de un kit antes de borrar
        $estaEnKit = DB::table('producto_composicion')->where('producto_hijo_id', $producto->id)->exists();

        // 1. Validar ventas
        $tieneVentas = $producto->ventas()->exists();

        // 2. Validar movimientos de inventario (Entradas, Salidas, Ajustes)
        $tieneMovimientos = $producto->movimientos()->exists();

        if ($tieneVentas || $tieneMovimientos) {
            $causa = $tieneVentas ? 'historial de ventas' : 'movimientos de inventario';
            if ($tieneVentas && $tieneMovimientos) $causa = 'ventas y movimientos de inventario';

            return response()->json([
                'message' => "Restricción de Integridad: No se puede eliminar el producto porque cuenta con {$causa}. Se recomienda marcarlo como 'Inactivo'."
            ], 422);
        }

        if ($estaEnKit) {
            return response()->json(['message' => 'No se puede eliminar: es componente de un producto compuesto'], 422);
        }

        $producto->update(['status' => false]);
        return response()->json(['message' => 'Producto dado de baja correctamente']);
    }

    public function search(Request $request)
    {
        $query = $request->get('q');

        return Producto::where('status', true)
            ->where('tipo_producto', '!=', 'Compuesto') // <--- Bloqueo preventivo
            ->where(function($q) use ($query) {
                $q->where('nombre', 'LIKE', "%{$query}%")
                    ->orWhere('codigo_barras', 'LIKE', "%{$query}%");
            })
            ->get(['id', 'nombre', 'codigo_barras', 'tipo_producto']);
    }

    private function syncRelations(Producto $producto, $request)
    {
        // 1. Impuestos
        if ($request->has('impuestos')) {
            $producto->impuestos()->sync($request->impuestos);
        }

        // 2. Precios dinámicos
        foreach ($request->precios as $p) {
            $producto->precios()->create($p);
        }

        // 3. Composición (si es Kit)
        if ($request->tipo_producto === 'Compuesto') {
            foreach ($request->componentes as $c) {
                $producto->componentes()->create([
                    'producto_hijo_id' => $c['id'],
                    'cantidad'         => $c['cantidad']
                ]);
            }
        }
    }
}
