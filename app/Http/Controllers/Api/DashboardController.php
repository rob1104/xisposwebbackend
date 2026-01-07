<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SucursalProducto;
use App\Models\InventarioMovimiento;
use App\Models\Transferencia;
use App\Models\Venta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function getSummary(Request $request)
    {
        $sucursalId = $request->query('sucursal_id');
        $diasAnalisis = 30; // Período para calcular el promedio

        // 1. CÁLCULO DE VENTAS DIARIAS PROMEDIO (Últimos 30 días)
        $queryVentas = Venta::where('status', 'Completada') // Filtramos canceladas
            ->where('created_at', '>=', now()->subDays($diasAnalisis));

        if ($sucursalId) {
            $queryVentas->where('sucursale_id', $sucursalId);
        }

        // Sumamos (cantidad * precio_venta)
        $totalIngresos = $queryVentas->sum('total'); // Suma de totales reales
        $promedioVentas = $totalIngresos / $diasAnalisis;

        // 2. Datos para la Gráfica (Últimos 7 días)
        $dias = collect(range(6, 0))->map(fn($i) => now()->subDays($i)->format('Y-m-d'));

        $movimientos = InventarioMovimiento::where('created_at', '>=', now()->subDays(7));
        if ($sucursalId) $movimientos->where('sucursal_id', $sucursalId);

        $dataMovs = $movimientos->get()
            ->groupBy(fn($m) => $m->created_at->format('Y-m-d'));

        $seriesEntradas = $dias->map(fn($dia) =>
            $dataMovs->get($dia)?->whereIn('tipo_movimiento', ['ENTRADA', 'ENTRADA POR COMPRA', 'ENTRADA POR TRASPASO'])->sum('cantidad') ?? 0
        );

        $seriesSalidas = $dias->map(fn($dia) =>
            $dataMovs->get($dia)?->whereIn('tipo_movimiento', ['SALIDA', 'SALIDA POR VENTA', 'SALIDA POR TRASPASO'])->sum('cantidad') ?? 0
        );

        // 2. OTROS KPIs OPERATIVOS
        $queryStock = SucursalProducto::query();
        if ($sucursalId) $queryStock->where('sucursal_id', $sucursalId);

        $skuActivos = $queryStock->where('stock_actual', '>', 0)->count();

        $traspasosPendientes = Transferencia::where('estatus', 'Enviado');
        if ($sucursalId) $traspasosPendientes->where('sucursal_destino_id', $sucursalId);
        $traspasosCount = $traspasosPendientes->count();

        $agotados = $queryStock->clone()->where('stock_actual', '<=', 0)->count();

        // 3. Stock Crítico (Top 5)
        $criticos = SucursalProducto::with(['producto', 'sucursal'])
            ->whereColumn('stock_actual', '<=', 'stock_minimo')
            ->where('stock_actual', '>', 0)
            ->orderBy('stock_actual', 'asc')
            ->limit(5)
            ->get();

        return response()->json([
            'kpis' => [
                ['title' => 'Ventas pormedio por día', 'value' => '$' . number_format($promedioVentas, 2), 'icon' => 'payments', 'color' => 'primary', 'progress' => 0.8],
                ['title' => 'Productos con Stock', 'value' => number_format($skuActivos), 'icon' => 'inventory_2', 'color' => 'secondary', 'progress' => 0.9],
                ['title' => 'En Tránsito', 'value' => $traspasosCount, 'icon' => 'local_shipping', 'color' => 'orange-9', 'progress' => 0.5],
                ['title' => 'Agotados', 'value' => $agotados, 'icon' => 'block', 'color' => 'red-9', 'progress' => 0.2],
            ],
            'chart' => [
                'categories' => $dias->map(fn($d) => date('D', strtotime($d))),
                'entradas' => $seriesEntradas,
                'salidas' => $seriesSalidas
            ],
            'criticos' => $criticos,
            'recientes' => InventarioMovimiento::with('producto')->latest()->limit(8)->get()
        ]);
    }
}
