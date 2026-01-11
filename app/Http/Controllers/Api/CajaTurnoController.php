<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CajaTurno;

class CajaTurnoController extends Controller
{
    public function index()
    {
        return CajaTurno::with(['user', 'sucursal'])
            ->orderBy('id', 'desc')->get();
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
