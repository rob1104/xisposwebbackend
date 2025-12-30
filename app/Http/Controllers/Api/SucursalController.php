<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SucursalRequest;
use App\Models\Sucursal;
use Illuminate\Http\Request;

class SucursalController extends Controller
{
    public function index()
    {
        $sucursales = Sucursal::orderBy('nombre')->get();
        return response()->json($sucursales);
    }

    public function store(SucursalRequest $request)
    {
        $sucursal = Sucursal::create($request->validated());
        return response()->json([
            'message' => "Sucursal '{$sucursal->nombre}' registrada con éxito."
        ], 201);
    }

    public function update(SucursalRequest $request, Sucursal $sucursale)
    {
        $sucursale->update($request->validated());
        return response()->json([
            'message' => 'Los datos de la sucursal han sido actualizados.'
        ]);

    }

    public function destroy(Sucursal $sucursale) {
        // Verificar si hay usuarios asignados a esta sucursal
        if ($sucursale->users()->count() > 0) {
            return response()->json([
                'message' => 'No se puede eliminar la sucursal porque tiene usuarios asignados.'
            ], 422);
        }
        $sucursale->delete();
        return response()->json(['message' => 'Sucursal eliminada exitosamente.']);
    }
}
