<?php

namespace App\Console\Commands;

use App\Models\CfdiDetalle;
use CfdiUtils\Certificado\Certificado;
use CfdiUtils\CfdiCreator40;
use Illuminate\Console\Command;
use App\Services\FinkokService;
use App\Models\Cfdi;
use App\Models\Sucursal;
use App\Models\SucursalEmisor;
use Illuminate\Support\Facades\Storage;

class TestFinkokStamp extends Command
{
    protected $signature = 'finkok:test-stamp';
    protected $description = 'Realiza una prueba de timbrado CFDI 4.0 con el RFC de prueba del SAT';

    public function handle(FinkokService $finkok)
    {
        $this->info('--- Iniciando Prueba de Timbrado Finkok ---');


        $emisor = SucursalEmisor::first();

        if (!$emisor) {
            return $this->error('No se encontró un emisor configurado en sucursal_emisores.');
        }

        $cfdi = new Cfdi([
            'serie' => 'TEST',
            'folio' => rand(1000, 9999),
            'forma_pago' => '01',
            'metodo_pago' => 'PUE',
            'subtotal' => 100.00,
            'total' => 116.00,
            'impuestos' => 16.00,
        ]);

        $sucursal = new \App\Models\Sucursal([
            'nombre' => 'SUCURSAL PRUEBA',
            'codigo_postal' => $emisor->codigo_postal
        ]);

        $cfdi->setRelation('sucursal', $sucursal);
        $sucursal->setRelation('emisor', $emisor);


        $detalles = collect([
            new CfdiDetalle([
                'clave_prod_serv' => '50192100',
                'clave_unidad'    => 'H87',
                'descripcion'     => 'PRODUCTO DE PRUEBA SISTEMA',
                'cantidad'        => 1,
                'valor_unitario'  => 100.00,
                'importe'         => 100.00,
                'impuesto_importe'=> 16.00
            ])
        ]);
        $cfdi->setRelation('detalles', $detalles);

        $this->comment("Enviando a procesar con CfdiUtils y Finkok...");

        $res = $finkok->crearYTimbrar($cfdi);

        if ($res['success']) {
            $this->info("✅ ¡ÉXITO! UUID: " . $res['uuid']);
            Storage::disk('private')->put('factura_test_final.xml', $res['xml']);
            $this->info("XML timbrado guardado en storage/app/private/factura_test_final.xml");
        } else {
            $this->error("❌ Fallo en el proceso:");
            $this->line($res['message']);
        }
    }
}
