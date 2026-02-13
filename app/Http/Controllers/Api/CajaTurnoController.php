<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CajaTurno;
use App\Models\Venta;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        // 4. LISTADO DE VENTAS DEL TURNO
        $listadoVentas = Venta::with('cliente')
            ->where('caja_turno_id', $id)
            ->where('status', 'Completada')
            ->orderBy('created_at', 'desc')
            ->get();

        // 5. PRODUCTOS VENDIDOS (AGRUPADOS)
        $productosVendidos = DB::table('venta_detalles')
            ->join('ventas', 'venta_detalles.venta_id', '=', 'ventas.id')
            ->join('productos', 'venta_detalles.producto_id', '=', 'productos.id')
            ->where('ventas.caja_turno_id', $id)
            ->where('ventas.status', 'Completada')
            ->select(
                'productos.nombre',
                'productos.codigo_barras',
                DB::raw('SUM(venta_detalles.cantidad) as cantidad_total'),
                DB::raw('SUM(venta_detalles.total) as dinero_total')
            )
            ->groupBy('productos.id', 'productos.nombre', 'productos.codigo_barras')
            ->orderByDesc('dinero_total')
            ->get();

        // 6. PREPARAR EL RESUMEN
        $resumen = [
            'ventas_efectivo' => $ventasEfectivo,
            'total_entradas'  => $totalEntradas,
            'total_retiros'   => $totalRetiros
        ];

        // 7. PARSEO DE DENOMINACIONES Y MOVIMIENTOS
        $denominaciones = is_string($turno->denominaciones_arqueo)
            ? json_decode($turno->denominaciones_arqueo, true)
            : $turno->denominaciones_arqueo;
        $movimientos = $turno->movimientos;

        // 8. GENERACIÓN DEL PDF
        $pdf = PDF::loadView('pdf.reporte_turno', compact(
            'turno',
            'denominaciones',
            'resumen',
            'movimientos',
            'listadoVentas',
            'productosVendidos'
        ));
        return $pdf->download("Reporte_Corte_Turno_{$turno->id}.pdf");
    }
}
