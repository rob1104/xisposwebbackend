<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CajaTurno;
use Illuminate\Http\Request;

class CajaTurnoController extends Controller
{
    public function index(Request $request)
    {
        $query = CajaTurno::with(['user', 'sucursal']);

        // Si se envía un user_id (caso del cajero), filtramos solo sus turnos
        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        // Si se envía un sucursal_id (caso del admin/gerente), filtramos por sucursal.
        if ($request->has('sucursal_id')) {
            $query->where('sucursale_id', $request->sucursal_id);
        }

        return $query->orderBy('id', 'desc')->get();
    }

    public function downloadPdf($id) {
        $turno = CajaTurno::with(['user', 'sucursal', 'movimientos'])->findOrFail($id);

        // Parseamos el JSON de denominaciones para la vista
        $denominaciones = is_string($turno->denominaciones_arqueo)
            ? json_decode($turno->denominaciones_arqueo, true)
            : $turno->denominaciones_arqueo;

        $pdf = \PDF::loadView('pdf.reporte_turno', compact('turno', 'denominaciones'));
        return $pdf->download("Reporte_Turno_{$turno->id}.pdf");
    }
}
