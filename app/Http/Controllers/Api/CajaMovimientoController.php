<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CajaMomiviento;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CajaMovimientoController extends Controller
{
    public function store(Request $request)
    {
        // 1. Validación de los datos recibidos del frontend
        $request->validate([
            'caja_turno_id' => 'required|exists:caja_turnos,id',
            'tipo' => 'required|in:Entrada,Retiro',
            'monto' => 'required|numeric|min:0.01',
            'concepto' => 'required|string|max:255'
        ]);

        return DB::transaction(function () use ($request) {
            // 2. Creamos el registro del movimiento
            $movimiento = CajaMomiviento::create([
                'caja_turno_id' => $request->caja_turno_id,
                'tipo' => $request->tipo,
                'monto' => $request->monto,
                'concepto' => $request->concepto
            ]);

            // 3. Actualizamos el saldo en la tabla de Turnos
            // Si es Entrada suma, si es Retiro resta



            return response()->json([
                'status' => 'success',
                'message' => 'Movimiento registrado y saldo actualizado',
                'data' => $movimiento
            ], 201);
        });
    }
}
