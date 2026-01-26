<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductoRequest;
use App\Models\Producto;
use App\Models\ProductoPrecio;
use App\Models\Sucursal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductoController extends Controller
{
    public function index()
    {
        return Producto::with(['categoria', 'impuestos', 'precios', 'componentes'])
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

            if ($request->has('impuestos')) {
                $producto->impuestos()->sync($request->impuestos);
            }

            if ($request->has('precios')) {
                $nuevosPrecios = collect($request->precios);

                // 1. OBTENER LISTAS VIGENTES
                // Extraemos los nombres de las listas que vienen en este guardado (ej. 'Mayoreo', 'Publico')
                $listasVigentes = $nuevosPrecios->pluck('nombre_lista')->filter();

                // 2. BORRAR LAS QUE SOBRAN (Limpieza)
                // Si tenías una lista "Liquidación" en la BD y ya no viene en el request, se borra.
                // Esto generará un log DELETED correcto.
                $producto->precios()
                    ->whereNotIn('nombre_lista', $listasVigentes)
                    ->delete();

                // 3. SINCRONIZAR (Update or Create)
                foreach ($nuevosPrecios as $datosPrecio) {
                    // Buscamos dentro de los precios de ESTE producto
                    $producto->precios()->updateOrCreate(
                    // A. CRITERIO DE BÚSQUEDA (El "Match")
                        [
                            'nombre_lista' => $datosPrecio['nombre_lista']
                        ],
                        // B. VALORES A GUARDAR (Si existe actualiza, si no crea)
                        [
                            'precio' => $datosPrecio['precio'],
                            // Agregamos utilidad_porcentaje ya que tu tabla lo requiere (No Nulo)
                            'utilidad_porcentaje' => $datosPrecio['utilidad_porcentaje'] ?? 0
                        ]
                    );
                }
            }

            if ($request->tipo_producto === 'Compuesto') {
                foreach ($request->componentes as $item) {
                    // Aquí es donde entra tu lógica de 'producto_padre_id', 'producto_hijo_id' y 'cantidad'
                    $producto->componentes()->attach($item['id'], [
                        'cantidad' => $item['cantidad']
                    ]);
                }
            }

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
                'clave_prod_serv', 'clave_unidad', 'status'
            ]));

            if ($request->has('impuestos')) {
                $producto->impuestos()->sync($request->impuestos);
            }

            if ($request->has('precios')) {
                $nuevosPrecios = collect($request->precios);

                // 1. OBTENER LISTAS VIGENTES
                // Extraemos los nombres de las listas que vienen en este guardado (ej. 'Mayoreo', 'Publico')
                $listasVigentes = $nuevosPrecios->pluck('nombre_lista')->filter();

                // 2. BORRAR LAS QUE SOBRAN (Limpieza)
                // Si tenías una lista "Liquidación" en la BD y ya no viene en el request, se borra.
                // Esto generará un log DELETED correcto.
                $producto->precios()
                    ->whereNotIn('nombre_lista', $listasVigentes)
                    ->delete();

                // 3. SINCRONIZAR (Update or Create)
                foreach ($nuevosPrecios as $datosPrecio) {
                    // Buscamos dentro de los precios de ESTE producto
                    $producto->precios()->updateOrCreate(
                    // A. CRITERIO DE BÚSQUEDA (El "Match")
                        [
                            'nombre_lista' => $datosPrecio['nombre_lista']
                        ],
                        // B. VALORES A GUARDAR (Si existe actualiza, si no crea)
                        [
                            'precio' => $datosPrecio['precio'],
                            // Agregamos utilidad_porcentaje ya que tu tabla lo requiere (No Nulo)
                            'utilidad_porcentaje' => $datosPrecio['utilidad_porcentaje'] ?? 0
                        ]
                    );
                }
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
                'message' => "No se puede eliminar el producto porque cuenta con {$causa}. Se recomienda marcarlo como 'Inactivo'."
            ], 422);
        }

        if ($estaEnKit) {
            return response()->json(['message' => 'No se puede eliminar: es componente de un producto compuesto'], 422);
        }

        $producto->delete();
        return response()->json(['message' => 'Producto eliminado correctamente']);
    }

    public function search(Request $request)
    {
        $query = $request->get('q');

        return Producto::where('status', true)
            ->where('tipo_producto', '!=', 'Compuesto')
            ->where('status', '!=', false)
            ->where(function($q) use ($query) {
                $q->where('nombre', 'LIKE', "%{$query}%")
                    ->orWhere('codigo_barras', 'LIKE', "%{$query}%");
            })
            ->get(['id', 'nombre', 'codigo_barras', 'tipo_producto']);
    }

    public function getPrecios($id)
    {
        $precios = ProductoPrecio::where('producto_id', $id)
            ->select('id', 'nombre_lista', 'precio')
            ->get();

        return response()->json($precios);
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
