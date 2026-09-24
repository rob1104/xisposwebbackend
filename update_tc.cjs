const fs = require('fs');

const content = `<?php

namespace App\\Http\\Controllers;

use App\\Models\\Ticket;
use Illuminate\\Http\\Request;

class TicketController extends Controller
{
    public function show($sucursal_id)
    {
        $sucursal = \\App\\Models\\Sucursal::find($sucursal_id);
        $config = Ticket::where('sucursale_id', $sucursal_id)->first();
        
        return response()->json([
            'header_lines' => $config ? $config->header_lines : ['BIENVENIDO'],
            'footer_lines' => $config ? $config->footer_lines : ['GRACIAS POR SU COMPRA'],
            'impresora_general_url' => $sucursal->impresora_general_url ?? 'http://127.0.0.1:5000',
            'impresora_cocina_url' => $sucursal->impresora_cocina_url ?? 'http://127.0.0.1:5001'
        ]);
    }

    public function store(Request $request, $sucursal_id)
    {
        $request->validate([
            'header_lines' => 'array',
            'footer_lines' => 'array',
            'impresora_general_url' => 'nullable|string',
            'impresora_cocina_url' => 'nullable|string',
        ]);

        $sucursal = \\App\\Models\\Sucursal::find($sucursal_id);
        if ($sucursal) {
            $sucursal->update([
                'impresora_general_url' => $request->impresora_general_url,
                'impresora_cocina_url' => $request->impresora_cocina_url,
            ]);
        }

        $config = Ticket::updateOrCreate(
            ['sucursale_id' => $sucursal_id],
            [
                'header_lines' => $request->header_lines,
                'footer_lines' => $request->footer_lines
            ]
        );

        return response()->json([
            'message' => 'Configuración de ticket e impresoras actualizada',
            'data' => $config
        ]);
    }
}
`;

fs.writeFileSync('d:/Escritorio/XisPOS 3.0/xisposbackend/app/Http/Controllers/TicketController.php', content, 'utf8');
