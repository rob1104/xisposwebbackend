<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Impuesto;
use Illuminate\Http\Request;

class ImpuestoController extends Controller
{
    public function index()
    {
        return Impuesto::orderBy('nombre', 'asc')
            ->get();
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nombre'      => 'required|string|max:50|unique:impuestos,nombre',
            'tasa_cuota'  => 'required|numeric|min:0|max:100',
            'tipo'        => 'required|in:Traslado,Retención',
            'impuesto'    => 'required|string|size:3', // Clave SAT (001, 002, 003)
            'tipo_factor' => 'required|in:Tasa,Cuota,Exento'
        ]);

        $impuesto = Impuesto::create($validated + ['status' => true]);

        return response()->json($impuesto, 201);
    }

    /**
     * Actualiza configuración fiscal
     */
    public function update(Request $request, Impuesto $impuesto)
    {
        $validated = $request->validate([
            'nombre'      => 'required|string|max:50|unique:impuestos,nombre,' . $impuesto->id,
            'tasa_cuota'  => 'required|numeric|min:0|max:100',
        ]);

        $impuesto->update($validated);
        return response()->json(['message' => 'Impuesto actualizado', 'data' => $impuesto]);
    }

    /**
     * Baja lógica (No se elimina si tiene productos asociados)
     */
    public function destroy(Impuesto $impuesto)
    {
        // Verificamos si algún producto lo está usando antes de desactivar
        $enUso = \DB::table('producto_impuesto')->where('impuesto_id', $impuesto->id)->exists();

        if ($enUso) {
            $impuesto->update(['status' => false]);
            return response()->json(['message' => 'Impuesto desactivado (está en uso por productos)']);
        }

        $impuesto->update(['status' => false]);
        return response()->json(['message' => 'Impuesto dado de baja correctamente']);
    }
}
