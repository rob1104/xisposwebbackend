<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClienteRequest;
use App\Models\Cliente;
use App\Models\Venta;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class ClientesController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:clientes.ver', only: ['index']),
            new Middleware('permission:clientes.crear', only: ['store']),
            new Middleware('permission:clientes.editar', only: ['update']),
            new Middleware('permission:clientes.borrar', only: ['destroy']),
        ];
    }
    public function index()
    {
        return response()->json(Cliente::orderBy('razon_social')->with('taxRegime')->get());
    }

    public function store(ClienteRequest $request)
    {
        $cliente = Cliente::create($request->validated());
        return response()->json($cliente, 211);
    }

    public function update(ClienteRequest $request, Cliente $cliente)
    {
        $cliente->update($request->validated());
        return response()->json($cliente);
    }

    public function show($id)
    {
        $cliente = Cliente::find($id);
        if (!$cliente) {
            return response()->json([
                'message' => 'El cliente solicitado no existe o ha sido eliminado.'
            ], 404);
        }
        return response()->json($cliente);
    }

    public function destroy(Cliente $cliente)
    {
        if ($cliente->id == 1) {
            return response()->json([
                'status' => 'error',
                'message' => 'Protección de Sistema: No se puede eliminar al cliente PUBLICO GENERAL.'
            ], 422);
        }

        if ($cliente->ventas()->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'No es posible eliminar este cliente porque ya cuenta con historial de ventas.'
            ], 422);
        }

        $cliente->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Cliente eliminado correctamente.'
        ]);
    }

    public function getAntiguedadSaldos($id)
    {
        $cliente = Cliente::findOrFail($id);

        // Obtenemos ventas con saldo pendiente
        $ventas = Venta::where('cliente_id', $id)
            ->where('status', 'Completada')
            ->withSum('pagos as pagado', 'monto') // Suma de pagos de la tabla venta_pago
            ->get()
            ->map(function ($venta) use ($cliente) {
                $venta->saldo = $venta->total - ($venta->pagado ?? 0);

                // Calculamos fecha de vencimiento basada en los días de crédito del cliente
                $fechaBase = $venta->created_at;
                $vencimiento = $fechaBase->addDays($cliente->dias_credito);
                $venta->fecha_vencimiento = $vencimiento->format('Y-m-d');

                // Días de atraso (si es negativo, aún no vence)
                $venta->dias_atraso = $vencimiento->diffInDays(now(), false);

                return $venta;
            })
            ->filter(fn($v) => $v->saldo > 0.01); // Solo las que tienen saldo real

        // Clasificación en intervalos
        $resumen = [
            'al_corriente' => $ventas->where('dias_atraso', '<=', 0)->sum('saldo'),
            'de_1_30'      => $ventas->whereBetween('dias_atraso', [1, 30])->sum('saldo'),
            'de_31_60'     => $ventas->whereBetween('dias_atraso', [31, 60])->sum('saldo'),
            'de_61_90'     => $ventas->whereBetween('dias_atraso', [61, 90])->sum('saldo'),
            'mas_de_90'    => $ventas->where('dias_atraso', '>', 90)->sum('saldo'),
            'total_deuda'  => $ventas->sum('saldo')
        ];

        return response()->json([
            'cliente' => $cliente,
            'resumen' => $resumen,
            'detalles' => $ventas->values()
        ]);
    }

    public function buscarPorNumeroGlobal($numero)
    {
        return Cliente::where('numero_global', $numero)->first();
    }
}
