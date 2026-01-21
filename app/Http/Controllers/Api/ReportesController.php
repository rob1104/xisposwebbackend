<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Venta;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ReportesController extends Controller
{
    public function ventasDetalladas(Request $request) {
        $request->validate([
            'fecha_inicio' => 'required|date',
            'fecha_fin' => 'required|date',
            'sucursal_id' => 'nullable|exists:sucursales,id'
        ]);

        $user = auth()->user();
        // Seguridad: Si no es admin, forzar su sucursal actual
        $sucursal_id = $user->hasRole('Administrador') ? $request->sucursal_id : $user->sucursal_activa_id;

        $ventas = Venta::with(['detalles.producto', 'pagos', 'cliente', 'sucursal'])
            ->whereBetween('created_at', [$request->fecha_inicio . ' 00:00:00', $request->fecha_fin . ' 23:59:59'])
            ->when($sucursal_id, fn($q) => $q->where('sucursale_id', $sucursal_id))
            ->where('status', 'Completada')
            ->orderBy('created_at', 'desc')
            ->get();

        // Resumen para KPIs Gerenciales
        $resumen = [
            'total_bruto' => $ventas->sum('total'),
            'total_impuestos' => $ventas->sum('impuestos'),
            'total_subtotal' => $ventas->sum('subtotal'),
            'conteo' => $ventas->count()
        ];

        return response()->json(compact('ventas', 'resumen'));
    }

    public function ventasDetalladasexportarPdf(Request $request)
    {
        $user = auth()->user();
        $sucursal_id = $user->hasRole('admin') ? $request->sucursal_id : $user->sucursale_id;

        // Aseguramos el formato de fecha para MySQL
        $fecha_inicio = Carbon::parse($request->fecha_inicio)->startOfDay();
        $fecha_fin = Carbon::parse($request->fecha_fin)->endOfDay();

        $ventas = Venta::with(['detalles.producto', 'pagos', 'cliente', 'sucursal'])
            ->whereBetween('created_at', [$fecha_inicio, $fecha_fin])
            ->when($sucursal_id, function($q) use ($sucursal_id) {
                return $q->where('sucursale_id', $sucursal_id); // Columna exacta de tu DB
            })
            ->where('status', 'Completada')
            ->get();

        // Si no hay ventas, abortamos con error para que el frontend lo cache
        if ($ventas->isEmpty()) {
            return response()->json(['message' => 'No hay datos'], 404);
        }

        $resumen = [
            'inicio' => $fecha_inicio->format('d/m/Y'),
            'fin'    => $fecha_fin->format('d/m/Y'),
            'total'  => $ventas->sum('total'),
            'taxes'  => $ventas->sum('impuestos'),
            'neto'   => $ventas->sum('subtotal'),
            'conteo' => $ventas->count()
        ];

        $pdf = Pdf::loadView('pdf.ventas_detalladas', compact('ventas', 'resumen'))
            ->setPaper('letter', 'landscape');

        return $pdf->stream();
    }
}
