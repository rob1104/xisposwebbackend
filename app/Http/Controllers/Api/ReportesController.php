<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Venta;
use Illuminate\Http\Request;

class ReportesController extends Controller
{
    public function ventasDetalladas(Request $request) {
        $query = Venta::with(['detalles.producto', 'cliente', 'usuario', 'sucursal'])
            ->whereBetween('fecha', [$request->desde, $request->hasta]);

        // Lógica de seguridad: El Gerente solo ve su sucursal
        if (!$request->user()->can('reportes.global')) {
            $query->where('sucursal_id', $request->sucursal_id);
        } elseif ($request->has('sucursal_id') && $request->sucursal_id != 'todas') {
            $query->where('sucursal_id', $request->sucursal_id);
        }

        $ventas = $query->orderBy('fecha', 'desc')->get();

        return response()->json($ventas);
    }
}
