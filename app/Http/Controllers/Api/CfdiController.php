<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cfdi;
use App\Models\Venta;
use App\Services\FinkokService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CfdiController extends Controller
{
    protected $finkok;

    public function __construct(FinkokService $finkok)
    {
        $this->finkok = $finkok;
    }

    public function store(Request $request)
    {
        // 1. Validar que las ventas seleccionadas existan y no estén facturadas (Candado)
        $ventas = Venta::whereIn('id', $request->ventas_ids)
            ->whereNull('cfdi_id')
            ->where('status', '!=', 'Cancelada')
            ->get();

        if ($ventas->count() !== count($request->ventas_ids)) {
            return response()->json(['message' => 'Una o más ventas ya han sido facturadas o están canceladas.'], 422);
        }

        return DB::transaction(function () use ($request, $ventas) {
            // 2. Obtener consecutivo de folio por sucursal
            $sucursal = auth()->user()->sucursal;
            $ultimoFolio = Cfdi::where('sucursal_id', $sucursal->id)
                ->where('serie', $sucursal->serie_prefijo)
                ->max('folio') ?? 0;

            // 3. Crear cabecera del CFDI
            $cfdi = Cfdi::create([
                'sucursal_id' => $sucursal->id,
                'cliente_id'  => $request->cliente_id,
                'user_id'     => auth()->id(),
                'serie'       => $sucursal->serie_prefijo,
                'folio'       => $ultimoFolio + 1,
                'forma_pago'  => $request->forma_pago,
                'metodo_pago' => $request->metodo_pago,
                'uso_cfdi'    => $request->uso_cfdi,
                'subtotal'    => $ventas->sum('subtotal'),
                'total'       => $ventas->sum('total'),
                'impuestos'   => $ventas->sum('impuestos'),
            ]);

            // 4. Crear detalles y asociar ventas
            $cfdi->generarDetallesDesdeVentas($ventas);
            Venta::whereIn('id', $request->ventas_ids)->update(['cfdi_id' => $cfdi->id]);

            // 5. Timbrar con el servicio
            $resultado = $this->finkok->timbrarFactura($cfdi);

            if ($resultado['success']) {
                $cfdi->update([
                    'uuid' => $resultado['uuid'],
                    'status' => 'Vigente',
                    'xml_path' => $this->guardarXml($resultado['xml'], $cfdi->id)
                ]);
                return response()->json(['message' => 'Factura timbrada con éxito', 'uuid' => $resultado['uuid']]);
            } else {
                // Si falla el timbrado, revertimos la transacción para no dejar basura
                throw new \Exception($resultado['message']);
            }
        });
    }

    private function guardarXml($xmlContent, $id) {
        $path = "cfdis/xml/factura_{$id}.xml";
        Storage::disk('public')->put($path, $xmlContent);
        return $path;
    }
}
