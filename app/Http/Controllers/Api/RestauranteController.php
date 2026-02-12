<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RestMesa;
use App\Models\RestOrden;
use App\Models\RestOrdenDetalle;
use App\Models\User;
use Illuminate\Http\Request;

class RestauranteController extends Controller
{
    public function indexMesas(Request $request)
    {
        $sucursalId = $request->header('X-Sucursal-Id');

        // 1. Cargamos las mesas CON su relación de orden activa (Eager Loading)
        $mesas = RestMesa::where('sucursale_id', $sucursalId)
            ->with('ordenActiva')
            ->get();

        // 2. Transformamos el resultado para facilitar la vida al Frontend
        $mesas->transform(function($mesa) {
            // Si hay orden activa, sacamos el total. Si no, es 0.
            $mesa->total_actual = $mesa->ordenActiva ? $mesa->ordenActiva->total : 0;

            // Opcional: Limpiamos el objeto orden para enviar menos datos,
            // ya que solo queríamos el total
            unset($mesa->ordenActiva);

            return $mesa;
        });

        return response()->json($mesas);
    }

    // Carga meseros
    public function obtenerMeseros(Request $request)
    {
        $sucursalId = $request->header('X-Sucursal-Id');
        if (!$sucursalId) {
            return response()->json(['message' => 'Sucursal no identificada'], 400);
        }
        // 2. Consulta con Spatie + Filtro de Sucursal
        $meseros = User::role('Mesero')
            ->where('status', 1)
            ->whereHas('sucursales', function($q) use ($sucursalId) {
                $q->where('sucursales.id', $sucursalId);
            })
            ->select('id', 'name', 'email')
            ->get();
        return response()->json($meseros);
    }

    public function obtenerOrden($id)
    {
        $orden = RestOrden::with([
            'detalles.producto',
            'mesa',
            'mesero'
        ])->findOrFail($id);

        return response()->json($orden);
    }

    // Abrir una mesa o crear pedido "Para Llevar"
    public function abrirOrden(Request $request)
    {
        $sucursalId = $request->header('X-Sucursal-Id');
        $mesaId = $request->mesa_id;

        // 1. Si es mesa (no para llevar), verificar si ya está ocupada
        if ($mesaId) {
            $ordenExistente = RestOrden::where('mesa_id', $mesaId)
                ->where('sucursale_id', $sucursalId)
                ->whereIn('estatus', ['Abierta', 'Cocina']) // Estatus activos
                ->first();

            if ($ordenExistente) {
                return response()->json($ordenExistente);
            }
        }

        // 2. Si no existe, creamos una nueva
        $orden = RestOrden::create([
            'sucursale_id' => $sucursalId,
            'mesa_id'      => $mesaId,
            'mesero_id'    => $request->mesero_id ?? auth()->id(),
            'nombre_cliente' => $request->nombre_cliente,
            'estatus'      => 'Abierta'
        ]);

        // 3. Marcar mesa como ocupada inmediatamente
        if ($mesaId) {
            RestMesa::where('id', $mesaId)->update(['ocupada' => true]);
        }

        return response()->json($orden);
    }

    public function actualizarOrden(Request $request, $id)
    {
        $orden = RestOrden::findOrFail($id);

        \DB::transaction(function() use ($orden, $request) {
            // 1. LIMPIEZA: Borramos SOLO los items que NO se han enviado a cocina (borradores)
            // Esto es crucial para que si eliminas un item en el iPad, se elimine de la BD
            RestOrdenDetalle::where('rest_orden_id', $orden->id)
                ->where('impreso_cocina', false)
                ->delete();

            // 2. REINSERCIÓN: Guardamos el estado actual del carrito "Por Enviar"
            foreach ($request->items as $item) {
                RestOrdenDetalle::create([
                    'rest_orden_id' => $orden->id,
                    'producto_id'   => $item['id'],
                    'cantidad'      => $item['cantidad'],
                    'precio'        => $item['precio'],
                    'notas'         => $item['notas'] ?? null,
                    'impreso_cocina'=> false
                ]);
            }

            // 3. Recalcular Total Global (Sumando enviados + nuevos)
            $total = $orden->detalles()->sum(\DB::raw('cantidad * precio'));
            $orden->update(['total' => $total]);
        });

        return response()->json(['message' => 'Orden sincronizada']);
    }

    // Enviar a Cocina (Imprimir)
    public function enviarCocina($id)
    {
        $orden = RestOrden::with(['detalles' => function($q){
            $q->where('impreso_cocina', false); // Solo lo nuevo
        }, 'detalles.producto', 'mesa'])->findOrFail($id);

        if ($orden->detalles->isEmpty()) {
            return response()->json(['message' => 'Nada nuevo para enviar a cocina']);
        }

        // --- AQUÍ LLAMAS A TU LIBRERÍA PYTHON ---
        // Ejemplo: PrintService::imprimirComandaCocina($orden);
        // ----------------------------------------

        // Marcar como impresos
        foreach ($orden->detalles as $det) {
            $det->update(['impreso_cocina' => true]);
        }

        $orden->update(['estatus' => 'Cocina']);

        return response()->json(['message' => 'Enviado a cocina correctamente']);
    }

    // Cerrar Cuenta (Imprimir Ticket de Cobro)
    public function cerrarCuenta($id)
    {
        $orden = RestOrden::with('detalles.producto')->findOrFail($id);


        $codigo = 'CMD-' . str_pad($orden->id, 6, '0', STR_PAD_LEFT);

        $orden->update([
            'estatus' => 'Cerrada',
            'codigo_cobro' => $codigo
        ]);


        if ($orden->mesa_id) {
            RestMesa::where('id', $orden->mesa_id)->update(['ocupada' => false]);
        }


        return response()->json(['codigo' => $codigo]);
    }

    // Metodo para que el POS recupere la orden al escanear
    public function buscarPorCodigo($codigo)
    {

        $orden = RestOrden::where('codigo_cobro', $codigo)->first();

        if (!$orden) {
            return response()->json(['message' => 'Código de orden no válido o no existe.'], 404);
        }

        if ($orden->estatus === 'Cobrada') {
            return response()->json([
                'message' => '⚠️ Esta orden YA FUE PAGADA anteriormente.',
                'error_code' => 'ALREADY_PAID'
            ], 422);
        }


        $orden->load('detalles.producto');

        return response()->json($orden);
    }


}
