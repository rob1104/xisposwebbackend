<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Categoria;
use App\Models\RestMesa;
use App\Models\RestOrden;
use Illuminate\Http\Request;

class RestAdminController extends Controller
{
    // --- GESTIÓN DE MESAS ---

    public function indexMesas(Request $request)
    {
        $sucursalId = $request->header('X-Sucursal-Id');
        return RestMesa::where('sucursale_id', $sucursalId)->get();
    }

    public function storeMesa(Request $request)
    {
        $request->validate(['nombre' => 'required']);

        $mesa = RestMesa::create([
            'sucursale_id' => $request->header('X-Sucursal-Id'),
            'nombre'       => $request->nombre,
            'zona'         => $request->zona, // Ej: "Terraza"
            'ocupada'      => false
        ]);
        return response()->json($mesa);
    }

    public function updateMesa(Request $request, $id)
    {
        $mesa = RestMesa::findOrFail($id);
        $mesa->update($request->only(['nombre', 'zona']));
        return response()->json($mesa);
    }

    public function destroyMesa($id)
    {
        $mesa = RestMesa::findOrFail($id);
        // Validar que no tenga orden abierta antes de borrar
        if ($mesa->ocupada) {
            return response()->json(['message' => 'No se puede borrar una mesa ocupada'], 400);
        }
        $mesa->delete();
        return response()->json(['message' => 'Mesa eliminada']);
    }

    // --- CONFIGURACIÓN DE MENÚ ---

    public function getCategoriasConfig()
    {
        // Retornamos ID, Nombre y el flag en_restaurante
        return Categoria::select('id', 'nombre', 'en_restaurante')->get();
    }

    public function toggleCategoria(Request $request, $id)
    {
        $cat = Categoria::findOrFail($id);
        $cat->update(['en_restaurante' => $request->en_restaurante]);
        return response()->json(['message' => 'Actualizado']);
    }

    // --- REPORTES Y KPIS ---

    public function getReporteGeneral(Request $request)
    {
        $sucursalId = $request->header('X-Sucursal-Id');

        // Filtro de Fechas (Por defecto HOY si no envían nada)
        $desde = $request->get('desde', date('Y-m-d 00:00:00'));
        $hasta = $request->get('hasta', date('Y-m-d 23:59:59'));

        // Base Query: Órdenes Cerradas o Pagadas (Ventas reales)
        $ordenesBase = RestOrden::where('sucursale_id', $sucursalId)
            ->whereIn('estatus', ['Cerrada', 'Pagada'])
            ->whereBetween('updated_at', [$desde, $hasta]);

        // 1. KPIs Principales
        $totalVentas = (clone $ordenesBase)->sum('total');
        $totalOrdenes = (clone $ordenesBase)->count();
        $ticketPromedio = $totalOrdenes > 0 ? $totalVentas / $totalOrdenes : 0;

        // 2. Ventas por Mesero (Top 5)
        $topMeseros = (clone $ordenesBase)
            ->selectRaw('mesero_id, count(*) as total_ordenes, sum(total) as total_dinero')
            ->groupBy('mesero_id')
            ->with('mesero:id,name') // Asumiendo relación
            ->orderByDesc('total_dinero')
            ->take(5)
            ->get();

        // 3. Top Productos Más Vendidos
        // Hacemos join con detalles
        $topProductos = \DB::table('rest_orden_detalles')
            ->join('rest_ordenes', 'rest_ordenes.id', '=', 'rest_orden_detalles.rest_orden_id')
            ->join('productos', 'productos.id', '=', 'rest_orden_detalles.producto_id')
            ->where('rest_ordenes.sucursale_id', $sucursalId)
            ->whereIn('rest_ordenes.estatus', ['Cerrada', 'Pagada'])
            ->whereBetween('rest_ordenes.updated_at', [$desde, $hasta])
            ->selectRaw('productos.nombre, sum(rest_orden_detalles.cantidad) as cantidad_vendida, sum(rest_orden_detalles.cantidad * rest_orden_detalles.precio) as total_generado')
            ->groupBy('productos.id', 'productos.nombre')
            ->orderByDesc('cantidad_vendida')
            ->take(7)
            ->get();

        $listaOrdenes = (clone $ordenesBase)
            ->with(['mesero:id,name', 'mesa:id,nombre', 'detalles.producto'])
            ->orderByDesc('updated_at')
            ->get();

        return response()->json([
            'kpis' => [
                'ventas' => $totalVentas,
                'ordenes' => $totalOrdenes,
                'ticket_promedio' => $ticketPromedio
            ],
            'meseros' => $topMeseros,
            'productos' => $topProductos,
            'ordenes' => $listaOrdenes
        ]);
    }
}
