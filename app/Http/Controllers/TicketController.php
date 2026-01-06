<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    public function show($sucursal_id)
    {
        $config = Ticket::where('sucursale_id', $sucursal_id)->first();
        if (!$config) {
            return response()->json([
                'header_lines' => ['BIENVENIDO'],
                'footer_lines' => ['GRACIAS POR SU COMPRA']
            ]);
        }
        return response()->json($config);
    }

    public function store(Request $request, $sucursal_id)
    {
        $request->validate([
            'header_lines' => 'array',
            'footer_lines' => 'array',
        ]);

        $config = Ticket::updateOrCreate(
            ['sucursale_id' => $sucursal_id],
            [
                'header_lines' => $request->header_lines,
                'footer_lines' => $request->footer_lines
            ]
        );

        return response()->json([
            'message' => 'Configuración de ticket actualizada correctamente',
            'data' => $config
        ]);
    }
}
