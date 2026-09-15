<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ModificadorGrupo;
use App\Models\ModificadorOpcion;
use Illuminate\Http\Request;

class ModificadorController extends Controller
{
    public function index()
    {
        return response()->json(ModificadorGrupo::with('opciones')->get());
    }

    public function store(Request $request)
    {
        $request->validate([
            'nombre' => 'required|string|max:255',
            'tipo' => 'required|in:opcion_multiple,opcion_unica,mitad_y_mitad',
            'min_selecciones' => 'required|integer|min:0',
            'max_selecciones' => 'required|integer|min:0'
        ]);

        $grupo = ModificadorGrupo::create($request->all());
        return response()->json($grupo);
    }

    public function update(Request $request, $id)
    {
        $grupo = ModificadorGrupo::findOrFail($id);
        $grupo->update($request->all());
        return response()->json($grupo);
    }

    public function destroy($id)
    {
        ModificadorGrupo::destroy($id);
        return response()->json(['message' => 'Grupo eliminado']);
    }

    public function storeOpcion(Request $request, $grupoId)
    {
        $request->validate([
            'nombre' => 'required|string|max:255',
            'precio_adicional' => 'numeric|min:0',
            'cantidad_descuento' => 'numeric|min:0'
        ]);

        $grupo = ModificadorGrupo::findOrFail($grupoId);
        $opcion = $grupo->opciones()->create($request->all());
        return response()->json($opcion);
    }

    public function updateOpcion(Request $request, $id)
    {
        $opcion = ModificadorOpcion::findOrFail($id);
        $opcion->update($request->all());
        return response()->json($opcion);
    }

    public function destroyOpcion($id)
    {
        ModificadorOpcion::destroy($id);
        return response()->json(['message' => 'Opción eliminada']);
    }
}
