<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CajaTurno;
use App\Models\Producto;
use App\Models\Venta;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PosController extends Controller
{
    public function verificarTurno()
    {
        $turno = CajaTurno::where('user_id', auth()->user()->id)->where('status', 'Abierto')->first();
        return response()->json(['turno' => $turno]);
    }
    public function abrirTurno(Request $request)
    {
        $request->validate([
            'fondo_apertura' => 'required|numeric',
            'tipo_cambio' => 'required|numeric',
            'sucursal_id' => 'required|exists:sucursales,id' // ID de la sucursal seleccionada
        ]);

        $turno = CajaTurno::create([
            'user_id' => auth()->id(),
            'sucursale_id' => $request->sucursal_id,
            'saldo_inicial' => $request->fondo_apertura, // Mapeo de nombre
            'tipo_cambio' => $request->tipo_cambio,
            'abierto_at' => Carbon::now(), //
            'status' => 'Abierto'
        ]);

        return response()->json([
            'message' => 'Turno iniciado correctamente',
            'turno' => $turno
        ]);
    }

    /**
     * Calcula cuánto efectivo debería haber en caja actualmente (Corte X)
     */
    public function balanceTurno($id)
    {
        $turno = CajaTurno::findOrFail($id);

        // Sumamos ventas pagadas en efectivo neto (Monto - Cambio)
        $efectivoVentas = DB::table('venta_pagos')
            ->join('ventas', 'venta_pagos.venta_id', '=', 'ventas.id')
            ->where('ventas.caja_turno_id', $id)
            ->where('ventas.status', 'Completada')
            ->where('venta_pagos.metodo_pago', 'Efectivo')
            ->selectRaw('SUM(monto - IFNULL(cambio_entregado, 0)) as total')
            ->value('total') ?? 0;

        // Balance = Saldo Inicial + Ventas Efectivo + Entradas Caja - Salidas Caja
        $balanceEfectivo = (float)$turno->saldo_inicial + (float)$efectivoVentas;

        return response()->json([
            'balance_efectivo' => $balanceEfectivo,
            'ventas_efectivo' => $efectivoVentas,
            'fondo_inicial' => $turno->saldo_inicial
        ]);
    }

    /**
     * Cierra definitivamente el turno y guarda el arqueo
     */
    public function cerrarTurno(Request $request)
    {
        $request->validate([
            'turno_id' => 'required',
            'efectivo_contado' => 'required|numeric',
            'diferencia' => 'required|numeric',
            'denominaciones' => 'required|array'
        ]);

        $turno = CajaTurno::findOrFail($request->turno_id);

        DB::transaction(function () use ($request, $turno) {
            $turno->update([
                'cerrado_at' => now(),
                'saldo_cierre' => $request->efectivo_contado,
                'diferencia' => $request->diferencia,
                'status' => 'Cerrado',
                'denominaciones_arqueo' =>$request->denominaciones
            ]);
        });

        return response()->json(['message' => 'Turno cerrado con éxito']);
    }

    /**
     * Busca un producto por código de barras para el POS
     */
    public function getByBarcode($codigo)
    {
        // Buscamos por código de barras o ID
        $producto = Producto::where('codigo_barras', $codigo)
            ->orWhere('id', $codigo)
            ->with(['precios', 'impuestos']) // Cargamos precios e impuestos para el cálculo
            ->first();

        if (!$producto) {
            return response()->json([
                'message' => 'Producto no encontrado'
            ], 404); // El 404 ahora será controlado
        }

        // Buscamos el "PRECIO PUBLICO" por defecto para el POS
        $precioPublico = $producto->precios->where('nombre_lista', 'PRECIO PUBLICO')->first();

        return response()->json([
            'id' => $producto->id,
            'nombre' => $producto->nombre,
            'codigo_barras' => $producto->codigo_barras,
            'precio' => $precioPublico ? $precioPublico->precio : 0, // Campo usado en el frontend
            'impuestos' => $producto->impuestos
        ]);
    }


    /**
     * Búsqueda para el DIÁLOGO (Filtro por nombre o código)
     */
    public function searchByFilter(Request $request)
    {
        $query = $request->get('q');

        if (empty($query)) {
            return response()->json([]);
        }

        $turno = CajaTurno::where('user_id', auth()->user()->id)->where('status', 'Abierto')->first();

        if (!$turno) {
            return response()->json(['message' => 'Debe abrir turno para buscar productos'], 403);
        }

        $sucursalId = $turno->sucursale_id;

        $productos = Producto::with(['precios', 'impuestos', 'categoria'])->where(function($q) use ($query) {
            $q->where('nombre', 'LIKE', "%{$query}%")
                ->orWhere('codigo_barras', 'LIKE', "%{$query}%");
            })
            ->where('status', 1)
            ->get()
            ->map(function($producto) use ($sucursalId) {
                $stockSucursal = $producto->sucursales()
                    ->where('sucursal_id', $sucursalId)
                    ->first();

                $producto->stock_actual = $stockSucursal ? $stockSucursal->pivot->stock_actual : 0;
                return $producto;
            });

        return response()->json($productos);
    }

    public function getUltimoTicket(Request $request)
    {
        return Venta::where('sucursale_id', $request->user()->sucursal_activa_id)
                    ->where('user_id', $request->user()->id)
                    ->with(['detalles', 'cliente', 'detalles.producto','sucursal', 'sucursal.ticket'])
                    ->latest()->first();
    }

}
