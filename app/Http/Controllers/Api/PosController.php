<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CajaTurno;
use App\Models\Producto;
use App\Models\User;
use App\Models\Venta;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

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
            'sucursal_id' => 'required|exists:sucursales,id',
            'supervisor_email' => 'required|exists:users,email',
            'supervisor_password' => 'required'
        ]);

        $supervisor = User::where('email', $request->supervisor_email)->first();

        if(!Hash::check($request->supervisor_password, $supervisor->password)) {
            return response()->json(['message' => 'Contraseña de supervisor incorrecta'], 422);
        }

        $turno = CajaTurno::create([
            'user_id' => auth()->id(),
            'autorizado_por' => $supervisor->id,
            'sucursale_id' => $request->sucursal_id,
            'saldo_inicial' => $request->fondo_apertura,
            'tipo_cambio' => $request->tipo_cambio,
            'abierto_at' => Carbon::now(),
            'status' => 'Abierto'
        ]);

        return response()->json([
            'message' => 'Turno autorizado e iniciado correctamente',
            'turno' => $turno
        ]);
    }

    /**
     * Calcula cuánto efectivo debería haber en caja actualmente (Corte X)
     */
    public function balanceTurno($id)
    {
        $turno = CajaTurno::findOrFail($id);
        $fondoApertura = $turno->saldo_inicial;

        // 1. VENTAS EN EFECTIVO: (Monto pagado - Cambio entregado)
        $ventasEfectivo = DB::table('venta_pagos')
            ->join('ventas', 'venta_pagos.venta_id', '=', 'ventas.id')
            ->where('ventas.caja_turno_id', $id)
            ->where('ventas.status', 'Completada')
            ->where('venta_pagos.metodo_pago', 'Efectivo')
            ->selectRaw('SUM(monto - IFNULL(cambio_entregado, 0)) as total')
            ->value('total') ?? 0;

        // 2. VENTAS EN TARJETA
        $ventasTarjeta = DB::table('venta_pagos')
            ->join('ventas', 'venta_pagos.venta_id', '=', 'ventas.id')
            ->where('ventas.caja_turno_id', $id)
            ->where('ventas.status', 'Completada')
            ->where('venta_pagos.metodo_pago', 'Tarjeta')
            ->sum('monto') ?? 0;

        // 3. MOVIMIENTOS DETALLADOS (Para el desglose en el modal)
        $movimientosLista = DB::table('caja_momivientos')
            ->where('caja_turno_id', $id)
            ->orderBy('created_at', 'desc')
            ->get();

        // 4. SUMATORIA DE MOVIMIENTOS
        // Nota: Corregido 'Egreso' por 'Retiro' para coincidir con el ENUM de tu DB
        $sumas = DB::table('caja_momivientos')
            ->where('caja_turno_id', $id)
            ->select(DB::raw("
            SUM(CASE WHEN tipo = 'Entrada' THEN monto ELSE 0 END) as entradas,
            SUM(CASE WHEN tipo = 'Retiro' THEN monto ELSE 0 END) as retiros
        "))->first();

        // 5. CÁLCULO DE EFECTIVO ESPERADO
        $totalEntradas = $sumas->entradas ?? 0;
        $totalRetiros = $sumas->retiros ?? 0;

        // Fórmula: Fondo Inicial + Ventas Efectivo + Entradas - Retiros
        $efectivoEsperado = ($fondoApertura + $ventasEfectivo + $totalEntradas) - $totalRetiros;
        $totalGeneral = $efectivoEsperado + $ventasTarjeta;

        return response()->json([
            'efectivo_esperado' => round($efectivoEsperado, 2),
            'tarjeta_esperado' => round($ventasTarjeta, 2),
            'total_general' => round($totalGeneral, 2),
            'ventas_efectivo' => round($ventasEfectivo, 2),
            'total_entradas' => round($totalEntradas, 2),
            'total_retiros' => round($totalRetiros, 2),
            'movimientos' => $movimientosLista, // Enviamos la lista para el desglose
            'detalle' => [
                'fondo' => $fondoApertura,
                'ventas_tarjeta' => $ventasTarjeta,
            ]
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
            'denominaciones' => 'required|array',
            'tarjeta_esperado' => 'required|numeric',
            'tarjeta_contado' => 'required|numeric',
        ]);

        $turno = CajaTurno::findOrFail($request->turno_id);

        DB::transaction(function () use ($request, $turno) {
            $turno->update([
                'cerrado_at' => now(),
                'saldo_cierre' => $request->efectivo_contado,
                'tarjeta_esperado' => $request->tarjeta_esperado,
                'tarjeta_contado' => $request->tarjeta_contado,
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
        // Buscamos el turno abierto del usuario para saber su sucursal_id
        $turno = CajaTurno::where('user_id', auth()->id())
            ->where('status', 'Abierto')
            ->first();

        if (!$turno) {
            return response()->json(['message' => 'No tienes un turno abierto'], 403);
        }

        $sucursalId = $turno->sucursale_id;

        // Buscamos por código de barras o ID
        $producto = Producto::where('codigo_barras', $codigo)
            ->orWhere('id', $codigo)
            ->with(['precios', 'impuestos'])
            ->first();

        if (!$producto) {
            return response()->json([
                'message' => 'Producto no encontrado'
            ], 404);
        }

        $stockData = $producto->sucursales()->where('sucursal_id', $sucursalId)->first();
        $stockActual = $stockData ? $stockData->pivot->stock_actual : 0;
        

        // Buscamos el "PRECIO PUBLICO" por defecto para el POS
        $precioPublico = $producto->precios->where('nombre_lista', 'PRECIO PUBLICO')->first();

        return response()->json([
            'id' => $producto->id,
            'nombre' => $producto->nombre,
            'codigo_barras' => $producto->codigo_barras,
            'precio' => $precioPublico ? $precioPublico->precio : 0, // Campo usado en el frontend
            'impuestos' => $producto->impuestos,
            'status' => $producto->status,
            'stock_actual' => $stockActual
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
                    ->with(['detalles', 'cliente', 'detalles.producto','sucursal', 'sucursal.ticket', 'pagos'])
                    ->latest()->first();
    }

    public function obtenerSugerenciaApertura(Request $request)
    {
        $sucursal_id = $request->header('X-Sucursal-Id');

        // Buscamos el último turno cerrado de esta sucursal
        $ultimoTurno = CajaTurno::where('sucursale_id', $sucursal_id)
            ->where('status', 'Cerrado')
            ->orderBy('id', 'desc')
            ->first();

        return response()->json([
            // Si no hay turnos previos, sugerimos 1.00 por defecto
            'tipo_cambio' => $ultimoTurno ? $ultimoTurno->tipo_cambio : 18.00
        ]);
    }

    /**
     * Corte de caja en formato ticket
     */
    public function datosImpresionCorte($id)
    {
        $turno = CajaTurno::with(['user', 'sucursal', 'movimientos'])->findOrFail($id);

        // 1. AGRUPAR VENTAS POR PRODUCTO (Resumen de Artículos Vendidos)
        $productosVendidos = DB::table('venta_detalles')
            ->join('ventas', 'venta_detalles.venta_id', '=', 'ventas.id')
            ->join('productos', 'venta_detalles.producto_id', '=', 'productos.id')
            ->where('ventas.caja_turno_id', $id)
            ->where('ventas.status', 'Completada')
            ->select(
                'productos.nombre',
                DB::raw('SUM(venta_detalles.cantidad) as cantidad_total'),
                DB::raw('SUM(venta_detalles.total) as dinero_total')
            )
            ->groupBy('productos.id', 'productos.nombre')
            ->get();

        // 2. TOTALES FINANCIEROS
        $ventasEfectivo = DB::table('venta_pagos')
            ->join('ventas', 'venta_pagos.venta_id', '=', 'ventas.id')
            ->where('ventas.caja_turno_id', $id)
            ->where('ventas.status', 'Completada')
            ->where('venta_pagos.metodo_pago', 'Efectivo')
            ->selectRaw('SUM(monto - IFNULL(cambio_entregado, 0)) as total')
            ->value('total') ?? 0;

        $ventasTarjeta = DB::table('venta_pagos')
            ->join('ventas', 'venta_pagos.venta_id', '=', 'ventas.id')
            ->where('ventas.caja_turno_id', $id)
            ->where('ventas.status', 'Completada')
            ->where('venta_pagos.metodo_pago', 'Tarjeta')
            ->sum('monto') ?? 0;

        // 3. MOVIMIENTOS
        $entradas = $turno->movimientos->where('tipo', 'Ingreso')->sum('monto');
        $retiros = $turno->movimientos->where('tipo', 'Retiro')->sum('monto');
        $listaMovimientos = $turno->movimientos->map(function($m){
            return ['tipo' => $m->tipo, 'monto' => $m->monto, 'concepto' => $m->concepto];
        });

        // 4. DENOMINACIONES
        $denominaciones = is_string($turno->denominaciones_arqueo)
            ? json_decode($turno->denominaciones_arqueo, true)
            : $turno->denominaciones_arqueo;

        return response()->json([
            'folio' => $turno->id,
            'fecha' => $turno->created_at->format('d/m/Y H:i'),
            'cajero' => $turno->user->name,
            'sucursal' => $turno->sucursal->nombre,
            'productos' => $productosVendidos,
            'finanzas' => [
                'fondo_inicial' => $turno->saldo_inicial,
                'ventas_efectivo' => $ventasEfectivo,
                'ventas_tarjeta' => $ventasTarjeta,
                'entradas' => $entradas,
                'retiros' => $retiros,
                'efectivo_esperado' => ($turno->saldo_inicial + $ventasEfectivo + $entradas) - $retiros,
                'efectivo_contado' => $turno->saldo_cierre,
                'diferencia' => $turno->diferencia
            ],
            'movimientos' => $listaMovimientos,
            'denominaciones' => $denominaciones
        ]);
    }
}
