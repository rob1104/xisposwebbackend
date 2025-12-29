<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use Illuminate\Http\Request;

class ClientesController extends Controller
{
    public function index()
    {
        return response()->json(Cliente::orderBy('razon_social')->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nombre_comercial' => 'required|string|max:100',
            'razon_social'     => 'nullable|string|max:100',
            'rfc'              => 'nullable|string|max:24',
            'telefono'         => 'nullable|string|max:30',
            'telefono2'        => 'nullable|string|max:30',
            'contacto'         => 'nullable|string|max:64',
            'calle'            => 'nullable|string|max:99',
            'no_exterior'      => 'nullable|string|max:8',
            'no_interior'      => 'nullable|string|max:8',
            'colonia'          => 'nullable|string|max:100',
            'codigo_postal'    => 'nullable|string|max:5',
            'ciudad'           => 'nullable|string|max:100',
            'estado'           => 'nullable|string|max:100',
            'pais'             => 'nullable|string|max:50',
            'limite_credito'   => 'nullable|numeric',
            'saldo_actual'     => 'nullable|numeric',
            'obs'              => 'nullable|string',
            'email'            => 'required|email|unique:clientes'
        ]);
        $validated['usuario_creador'] = auth()->user()->name;
        $cliente = Cliente::create($validated);
        return response()->json($cliente, 211);
    }
}
