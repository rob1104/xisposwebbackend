<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Categoria;
use App\Models\Impuesto;
use App\Models\Medida;
use App\Models\Producto;
use App\Models\ProductoImpuesto;
use Illuminate\Http\Request;

class CatalogoController extends Controller
{
    // --- C A T E G O R I A S  ---
    public function getCategorias()
    {
        return Categoria::orderBy('nombre')->get();
    }

    public function storeCategoria(Request $request) {
        $data = $request->validate(['nombre' => 'required|string|max:100', 'descripcion' => 'nullable|string']);
        return Categoria::create($data + ['status' => 1]);
    }

    public function updateCategoria(Request $request, $id) {
        $categoria = Categoria::findOrFail($id);
        $categoria->update($request->all());
        return $categoria;
    }

    public function destroyCategoria($id) {
        $enUso = Producto::where('categoria_id', $id)->exists();
        if ($enUso) return response()->json(['error' => 'No se puede eliminar: existen productos asociados.'], 422);

        Categoria::destroy($id);
        return response()->json(['message' => 'Eliminado']);
    }

    // --- IMPUESTOS ---
    public function getImpuestos() {
        return Impuesto::orderBy('nombre')->get();
    }

    public function storeImpuesto(Request $request) {
        $data = $request->validate([
            'nombre' => 'required|string',
            'porcentaje' => 'required|numeric',
            'tipo' => 'required'
        ]);
        return Impuesto::create($data);
    }

    public function updateImpuesto(Request $request, $id)
    {
        $impuesto = Impuesto::findOrFail($id);

        // Validamos siguiendo la estructura de la tabla
        $data = $request->validate([
            'nombre'     => 'required|string|max:100',
            'porcentaje' => 'required|numeric|between:0,100', // Decimal(10,6)
            'tipo'       => 'required|string|max:64'          // Traslado o Retención
        ]);

        $impuesto->update($data);

        return response()->json([
            'message' => 'Impuesto actualizado correctamente',
            'data'    => $impuesto
        ]);
    }

    public function destroyImpuesto($id)
    {
        $impuesto = Impuesto::findOrFail($id);
        $enUso = ProductoImpuesto::where('impuesto_id', $id)->exists();
        if ($enUso) {
            return response()->json([
                'error' => 'No se puede eliminar: Este impuesto está asignado a productos activos.'
            ], 422);
        }
        $impuesto->delete();
        return response()->json([
            'message' => 'Impuesto eliminado con éxito'
        ]);
    }

    // --- UNIDADES DE MEDIDA ---
    public function getUnidades() {
        return Medida::orderBy('nombre')->get();
    }

    public function storeUnidad(Request $request) {
        $data = $request->validate([
            'c_ClaveUnidad' => 'required|string|max:5|unique:medidas',
            'nombre' => 'required|string|max:100'
        ]);
        return Medida::create($data);
    }

    public function updateUnidad(Request $request, $id) {
        $unidad = Medida::findOrFail($id);
        $data = $request->validate([
            'c_ClaveUnidad' => 'required|string|max:5|unique:medidas,c_ClaveUnidad,'.$id,
            'nombre' => 'required|string|max:100'
        ]);
        $unidad->update($data);
        return $unidad;
    }

    public function destroyUnidad($id) {
        $unidad = Medida::findOrFail($id);
        $enUso = Producto::where('clave_unidad', $unidad->c_ClaveUnidad)->exists();
        if ($enUso) {
            return response()->json(['error' => 'No se puede eliminar: Esta unidad está asignada a productos.'], 422);
        }
        Medida::destroy($id);
        return response()->json(['message' => 'Eliminado']);
    }
}
