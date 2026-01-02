<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Categoria;
use Illuminate\Http\Request;

class CategoriaController extends Controller
{
    public function index()
    {
        return Categoria::where('status', true)
            ->orderBy('nombre', 'asc')
            ->get(['id', 'nombre', 'descripcion']);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nombre'      => 'required|string|max:100|unique:categorias,nombre',
            'descripcion' => 'nullable|string|max:255'
        ]);
        $categoria = Categoria::create($validated + ['status' => true]);
        return response()->json($categoria, 201);
    }

    public function update(Request $request, Categoria $categoria)
    {
        $validated = $request->validate([
            'nombre'      => 'required|string|max:100|unique:categorias,nombre,' . $categoria->id,
            'descripcion' => 'nullable|string|max:255',
            'status'      => 'boolean'
        ]);
        $categoria->update($validated);
        return response()->json([
            'message' => 'Categoría actualizada correctamente',
            'data'    => $categoria
        ]);
    }

    public function destroy(Categoria $categoria)
    {
        $tieneProductos = $categoria->productos()->exists();
        if ($tieneProductos) {
            $categoria->update(['status' => false]);
            return response()->json(['message' => 'Categoría desactivada (contiene productos asociados)']);
        }
        $categoria->update(['status' => false]);
        return response()->json(['message' => 'Categoría dada de baja correctamente']);
    }
}
