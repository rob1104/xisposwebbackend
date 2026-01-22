<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CajaTurno;
use Barryvdh\DomPDF\Facade\Pdf;
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
        // 1. Cargamos el turno con sus relaciones (incluyendo movimientos)
        $turno = CajaTurno::with(['user', 'sucursal', 'movimientos'])->findOrFail($id);

        // 2. CÁLCULO DE VENTAS EN EFECTIVO
        $ventasEfectivo = \DB::table('venta_pagos')
            ->join('ventas', 'venta_pagos.venta_id', '=', 'ventas.id')
            ->where('ventas.caja_turno_id', $id)
            ->where('ventas.status', 'Completada')
            ->where('venta_pagos.metodo_pago', 'Efectivo')
            ->selectRaw('SUM(monto - IFNULL(cambio_entregado, 0)) as total')
            ->value('total') ?? 0;

        // 3. CÁLCULOS DE MOVIMIENTOS (Entradas vs Retiros)
        $totalEntradas = $turno->movimientos->where('tipo', 'Entrada')->sum('monto');
        $totalRetiros  = $turno->movimientos->where('tipo', 'Retiro')->sum('monto');

        // 4. PREPARAR EL RESUMEN PARA LA VISTA
        $resumen = [
            'ventas_efectivo' => $ventasEfectivo,
            'total_entradas'  => $totalEntradas,
            'total_retiros'   => $totalRetiros
        ];

        // 5. PARSEO DE DENOMINACIONES Y MOVIMIENTOS
        $denominaciones = is_string($turno->denominaciones_arqueo)
            ? json_decode($turno->denominaciones_arqueo, true)
            : $turno->denominaciones_arqueo;
        $movimientos = $turno->movimientos;

        // 6. GENERACIÓN DEL PDF
        $pdf = PDF::loadView('pdf.reporte_turno', compact('turno', 'denominaciones', 'resumen', 'movimientos'));
        return $pdf->download("Reporte_Turno_{$turno->id}.pdf");
    }
}
