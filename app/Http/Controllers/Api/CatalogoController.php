<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Categoria;
use App\Models\ClaveProdServ;
use App\Models\Impuesto;
use App\Models\Medida;
use App\Models\Producto;
use App\Models\ProductoImpuesto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CatalogoController extends Controller
{
    // --  CLAVES SAT --
    public function buscarProductoSat($codigo)
    {
        $productoSat = ClaveProdServ::where('clave', $codigo)->first();

        if($productoSat) {
            return response()->json(['descripcion' => $productoSat->descripcion]);
        }
        return response()->json(['descripcion' => null], 404);
    }

    // --- C A T E G O R I A S  ---
    public function getCategorias()
    {
        return Categoria::orderBy('nombre')->get();
    }

    public function storeCategoria(Request $request) {
        $data = $request->validate([
                'nombre' => 'required|string|max:100',
                'descripcion' => 'nullable|string',
                'imagen_file' => 'nullable|image|max:5120'
            ]);

        if ($request->hasFile('imagen_file')) {
            $path = $request->file('imagen_file')->store('public/categorias');
            $data['imagen'] = asset(Storage::url($path));
        }

        return Categoria::create($data + ['status' => 1]);
    }

    public function updateCategoria(Request $request, $id) {
        $categoria = Categoria::findOrFail($id);

        // 1. Validar los datos
        $request->validate([
            'nombre' => 'required|string',
            'en_restaurante' => 'required',
            'imagen_file' => 'nullable|image|max:2048' // 2MB Max
        ]);

        $data = $request->only(['nombre', 'en_restaurante', 'icono']);

        // 2. Procesar la imagen si viene una nueva
        if ($request->hasFile('imagen_file')) {
            // Borrar la imagen anterior si existe para ahorrar espacio
            if ($categoria->imagen) {
                // Extraemos el nombre del archivo de la URL guardada
                $oldPath = str_replace('/storage/', '', $categoria->imagen);
                Storage::disk('public')->delete($oldPath);
            }

            // Guardar la nueva
            $path = $request->file('imagen_file')->store('categorias', 'public');
            $data['imagen'] = asset(Storage::url($path));
        }

        $categoria->update($data);

        return response()->json($categoria);
    }

    public function destroyCategoria($id) {
        $categoria = Categoria::findOrFail($id);

        // 1. Verificación de integridad (igual que antes)
        $enUso = Producto::where('categoria_id', $id)->exists();
        if ($enUso) {
            return response()->json(['error' => 'No se puede eliminar: existen productos asociados.'], 422);
        }

        // 2. Borrar la imagen del disco si tiene una
        if ($categoria->imagen) {
            $path = str_replace('/storage/', '', $categoria->imagen);
            Storage::disk('public')->delete($path);
        }

        // 3. Eliminar el registro
        $categoria->delete();

        return response()->json(['message' => 'Categoría e imagen eliminadas correctamente']);
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
