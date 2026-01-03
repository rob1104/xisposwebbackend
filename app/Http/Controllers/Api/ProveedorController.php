<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProveedorRequest;
use App\Models\Provider;
use Illuminate\Http\Request;

class ProveedorController extends Controller
{
    public function index()
    {
        return response()->json(Provider::orderBy('razon_social')->get());
    }

    public function buscar(Request $request)
    {
        $q = $request->input('q');
        return Provider::where('nombre_comercial', 'LIKE', "%$q%")
            ->orWhere('razon_social', 'LIKE', "%$q%")
            ->orWhere('rfc', 'LIKE', "%$q%")
            ->get();
    }

    public function store(ProveedorRequest $request)
    {
        $data = $request->validated();

        // Asignamos el usuario que crea el registro
        $data['usuario_creador'] = auth()->user()->name;

        Provider::create($data);

        return response()->json(['message' => 'Proveedor guardado con éxito']);
    }

    public function update(ProveedorRequest $request, Provider $provider)
    {
        $data = $request->validated();
        unset($data['usuario_creador']);
        unset($data['numero_global']);
        $provider->update($data);
        return response()->json([
            'message' => 'Los datos de "' . $provider->nombre_comercial . '" se han actualizado correctamente.'
        ]);
    }

    public function destroy(Provider $provider)
    {
        $nombre = $provider->nombre_comercial;
        $provider->delete();

        return response()->json([
            'message' => 'El proveedor "' . $nombre . '" ha sido eliminado del sistema.'
        ]);
    }
}
