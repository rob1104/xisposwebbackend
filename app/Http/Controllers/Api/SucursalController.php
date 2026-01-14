<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SucursalRequest;
use App\Models\Sucursal;
use App\Models\SucursalEmisor;
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

    public function updateEmisor(Request $request, $sucursalId)
    {
        $request->validate([
            'rfc' => 'required|string',
            'razon_social' => 'required|string',
            'regimen_fiscal' => 'required|string|size:3',
            'codigo_postal' => 'required|string|size:5',
            'cer_file' => 'nullable|file|mimetypes:application/octet-stream,application/x-x509-ca-cert',
            'key_file' => 'nullable|file|mimetypes:application/octet-stream',
        ]);

        $sucursal = Sucursal::findOrFail($sucursalId);
        $data = $request->except(['cer_file', 'key_file']);
        $rfc = strtoupper(trim($request->rfc));

        // Procesar Archivo .CER
        if ($request->hasFile('cer_file')) {
            $nombreCer = "{$rfc}.cer";
            // storeAs permite definir la ruta y el nombre exacto
            $pathCer = $request->file('cer_file')->storeAs("csd/{$sucursalId}", $nombreCer, 'private');
            $data['cer_path'] = $pathCer;
        }

        // Procesar Archivo .KEY
        if ($request->hasFile('key_file')) {
            $nombreKey = "{$rfc}.key";
            $pathKey = $request->file('key_file')->storeAs("csd/{$sucursalId}", $nombreKey, 'private');
            $data['key_path'] = $pathKey;
        }

        $emisor = $sucursal->emisor()->updateOrCreate(
            ['sucursale_id' => $sucursal->id],
            $data
        );

        return response()->json($emisor);
    }

    public function getEmisor($id)
    {
        $emisor = SucursalEmisor::where('sucursale_id', $id)->first();
        return response()->json($emisor);
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
