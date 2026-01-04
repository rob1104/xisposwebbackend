<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Producto;
use Illuminate\Http\Request;

class PosController extends Controller
{
    /**
     * Busca un producto por código de barras para el POS
     */
    public function getByBarcode($codigo)
    {
        // Buscamos por código de barras o ID
        $producto = Producto::where('codigo_barras', $codigo)
            ->orWhere('id', $codigo)
            ->with(['precios', 'impuestos']) // Cargamos precios e impuestos para el cálculo
            ->first();

        if (!$producto) {
            return response()->json([
                'message' => 'Producto no encontrado'
            ], 404); // El 404 ahora será controlado
        }

        // Buscamos el "PRECIO PUBLICO" por defecto para el POS
        $precioPublico = $producto->precios->where('nombre_lista', 'PRECIO PUBLICO')->first();

        return response()->json([
            'id' => $producto->id,
            'nombre' => $producto->nombre,
            'codigo_barras' => $producto->codigo_barras,
            'precio_venda' => $precioPublico ? $precioPublico->precio : 0, // Campo usado en el frontend
            'impuestos' => $producto->impuestos
        ]);
    }


    /**
     * Búsqueda para el DIÁLOGO (Filtro por nombre o código)
     */
    public function searchByFilter(Request $request)
    {
        $query = $request->get('q'); // Término de búsqueda

        if (empty($query)) {
            return response()->json([]);
        }

        // Buscamos por nombre, marca o código de barras parcial
        $productos = Producto::with(['precios', 'impuestos', 'categoria'])->where(function($q) use ($query) {
            $q->where('nombre', 'LIKE', "%{$query}%")
                ->orWhere('codigo_barras', 'LIKE', "%{$query}%");
        })
            ->where('status', 1)
            ->get();

        return response()->json($productos);
    }
}
