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

        $ventas = Venta::with(['detalles.producto', 'pagos', 'cliente', 'sucursal', 'user'])
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
        $sucursal_id = $request->sucursal_id;

        // Aseguramos el formato de fecha para MySQL
        $fecha_inicio = Carbon::parse($request->fecha_inicio)->startOfDay();
        $fecha_fin = Carbon::parse($request->fecha_fin)->endOfDay();

        $ventas = Venta::with(['detalles.producto', 'pagos', 'cliente', 'sucursal'])
            ->whereBetween('created_at', [$fecha_inicio, $fecha_fin])
            ->when($sucursal_id, function($q) use ($sucursal_id) {
                return $q->where('sucursale_id', $sucursal_id);
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

    public function ventasPorProducto(Request $request) {
        $request->validate([
            'fecha_inicio' => 'required|date',
            'fecha_fin' => 'required|date',
            'sucursal_id' => 'nullable|exists:sucursales,id'
        ]);

        $user = auth()->user();
        $sucursal_id = $user->hasRole('Super Administrador') || $user->hasRole('Administrador') ? $request->sucursal_id : $user->sucursal_activa_id;

        $fecha_inicio = Carbon::parse($request->fecha_inicio)->startOfDay();
        $fecha_fin = Carbon::parse($request->fecha_fin)->endOfDay();

        $query = \Illuminate\Support\Facades\DB::table('venta_detalles')
            ->join('ventas', 'venta_detalles.venta_id', '=', 'ventas.id')
            ->join('productos', 'venta_detalles.producto_id', '=', 'productos.id')
            ->join('categorias', 'productos.categoria_id', '=', 'categorias.id')
            ->whereBetween('ventas.created_at', [$fecha_inicio, $fecha_fin])
            ->where('ventas.status', 'Completada');

        if ($sucursal_id) {
            $query->where('ventas.sucursale_id', $sucursal_id);
        }

        $reporte = $query->select(
            'productos.id',
            'productos.codigo_barras',
            'productos.nombre as producto_nombre',
            'categorias.nombre as categoria_nombre',
            \Illuminate\Support\Facades\DB::raw('SUM(venta_detalles.cantidad) as total_vendido'),
            \Illuminate\Support\Facades\DB::raw('SUM(venta_detalles.total) as total_ingresos')
        )
        ->groupBy('productos.id', 'productos.codigo_barras', 'productos.nombre', 'categorias.nombre')
        ->orderByDesc('total_vendido')
        ->get();

        $kpis = [
            'productos_distintos' => $reporte->count(),
            'cantidad_total' => $reporte->sum('total_vendido'),
            'ingresos_totales' => $reporte->sum('total_ingresos'),
            'producto_estrella' => $reporte->first() ? $reporte->first()->producto_nombre : 'N/A'
        ];

        return response()->json(compact('reporte', 'kpis'));
    }

    public function ventasPorProductoPdf(Request $request)
    {
        $sucursal_id = $request->sucursal_id;
        $user = auth()->user();
        $sucursal_id = $user->hasRole('Super Administrador') || $user->hasRole('Administrador') ? $request->sucursal_id : $user->sucursal_activa_id;

        $fecha_inicio = Carbon::parse($request->fecha_inicio)->startOfDay();
        $fecha_fin = Carbon::parse($request->fecha_fin)->endOfDay();

        $query = \Illuminate\Support\Facades\DB::table('venta_detalles')
            ->join('ventas', 'venta_detalles.venta_id', '=', 'ventas.id')
            ->join('productos', 'venta_detalles.producto_id', '=', 'productos.id')
            ->join('categorias', 'productos.categoria_id', '=', 'categorias.id')
            ->whereBetween('ventas.created_at', [$fecha_inicio, $fecha_fin])
            ->where('ventas.status', 'Completada');

        if ($sucursal_id) {
            $query->where('ventas.sucursale_id', $sucursal_id);
        }

        $reporte = $query->select(
            'productos.codigo_barras',
            'productos.nombre as producto_nombre',
            'categorias.nombre as categoria_nombre',
            \Illuminate\Support\Facades\DB::raw('SUM(venta_detalles.cantidad) as total_vendido'),
            \Illuminate\Support\Facades\DB::raw('SUM(venta_detalles.total) as total_ingresos')
        )
        ->groupBy('productos.codigo_barras', 'productos.nombre', 'categorias.nombre')
        ->orderByDesc('total_ingresos')
        ->get();

        if ($reporte->isEmpty()) {
            return response()->json(['message' => 'No hay datos'], 404);
        }

        $resumen = [
            'inicio' => $fecha_inicio->format('d/m/Y'),
            'fin'    => $fecha_fin->format('d/m/Y'),
            'total_ingresos' => $reporte->sum('total_ingresos'),
            'total_vendido' => $reporte->sum('total_vendido'),
        ];

        $pdf = Pdf::loadView('pdf.ventas_por_producto', compact('reporte', 'resumen'))
            ->setPaper('letter', 'portrait');

        return $pdf->stream('ventas_por_producto.pdf');
    }

    public function traspasos(Request $request) {
        $request->validate([
            'fecha_inicio' => 'required|date',
            'fecha_fin' => 'required|date',
            'sucursal_id' => 'nullable|exists:sucursales,id'
        ]);

        $user = auth()->user();
        $sucursal_id = $request->sucursal_id;

        $fecha_inicio = Carbon::parse($request->fecha_inicio)->startOfDay();
        $fecha_fin = Carbon::parse($request->fecha_fin)->endOfDay();

        $query = \App\Models\Transferencia::with(['sucursalOrigen', 'sucursalDestino', 'userEnvia', 'userRecibe', 'detalles.producto'])
            ->whereBetween('fecha_envio', [$fecha_inicio, $fecha_fin]);

        if ($user->hasRole('Administrador') || $user->hasRole('Super Administrador')) {
            if ($sucursal_id) {
                $query->where(function($q) use ($sucursal_id) {
                    $q->where('sucursal_origen_id', $sucursal_id)
                      ->orWhere('sucursal_destino_id', $sucursal_id);
                });
            }
        } else {
            $assignedIds = $user->sucursales()->pluck('sucursales.id')->toArray();
            if ($sucursal_id && in_array($sucursal_id, $assignedIds)) {
                $query->where(function($q) use ($sucursal_id) {
                    $q->where('sucursal_origen_id', $sucursal_id)
                      ->orWhere('sucursal_destino_id', $sucursal_id);
                });
            } else {
                // Si no mandan sucursal o no tienen permiso para la solicitada, mostrar todas las que tienen asignadas
                $query->where(function($q) use ($assignedIds) {
                    $q->whereIn('sucursal_origen_id', $assignedIds)
                      ->orWhereIn('sucursal_destino_id', $assignedIds);
                });
            }
        }

        $traspasos = $query->orderBy('fecha_envio', 'desc')->get();

        $kpis = [
            'total_traspasos' => $traspasos->count(),
            'cancelados' => $traspasos->where('estatus', 'Cancelado')->count(),
            'pendientes' => $traspasos->where('estatus', 'Enviado')->count(),
            'completados' => $traspasos->where('estatus', 'Recibido')->count(),
        ];

        return response()->json(compact('traspasos', 'kpis'));
    }

    public function traspasosPdf(Request $request) {
        $request->validate([
            'fecha_inicio' => 'required|date',
            'fecha_fin' => 'required|date',
            'sucursal_id' => 'nullable|exists:sucursales,id'
        ]);

        $user = auth()->user();
        $sucursal_id = $request->sucursal_id;

        $fecha_inicio = Carbon::parse($request->fecha_inicio)->startOfDay();
        $fecha_fin = Carbon::parse($request->fecha_fin)->endOfDay();

        $query = \App\Models\Transferencia::with(['sucursalOrigen', 'sucursalDestino', 'userEnvia', 'userRecibe', 'detalles.producto'])
            ->whereBetween('fecha_envio', [$fecha_inicio, $fecha_fin]);

        if ($user->hasRole('Administrador') || $user->hasRole('Super Administrador')) {
            if ($sucursal_id) {
                $query->where(function($q) use ($sucursal_id) {
                    $q->where('sucursal_origen_id', $sucursal_id)
                      ->orWhere('sucursal_destino_id', $sucursal_id);
                });
            }
        } else {
            $assignedIds = $user->sucursales()->pluck('sucursales.id')->toArray();
            if ($sucursal_id && in_array($sucursal_id, $assignedIds)) {
                $query->where(function($q) use ($sucursal_id) {
                    $q->where('sucursal_origen_id', $sucursal_id)
                      ->orWhere('sucursal_destino_id', $sucursal_id);
                });
            } else {
                // Si no mandan sucursal o no tienen permiso para la solicitada, mostrar todas las que tienen asignadas
                $query->where(function($q) use ($assignedIds) {
                    $q->whereIn('sucursal_origen_id', $assignedIds)
                      ->orWhereIn('sucursal_destino_id', $assignedIds);
                });
            }
        }

        $traspasos = $query->orderBy('fecha_envio', 'desc')->get();

        if ($traspasos->isEmpty()) {
            return response()->json(['message' => 'No hay datos'], 404);
        }

        $resumen = [
            'inicio' => $fecha_inicio->format('d/m/Y'),
            'fin'    => $fecha_fin->format('d/m/Y'),
            'total_traspasos' => $traspasos->count(),
            'cancelados' => $traspasos->where('estatus', 'Cancelado')->count(),
            'pendientes' => $traspasos->where('estatus', 'Enviado')->count(),
            'completados' => $traspasos->where('estatus', 'Recibido')->count(),
        ];

        $pdf = Pdf::loadView('pdf.reporte_traspasos', compact('traspasos', 'resumen'))
            ->setPaper('letter', 'landscape');

        return $pdf->stream('reporte_traspasos.pdf');
    }
}
