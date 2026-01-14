<?php

namespace App\Console\Commands;

use App\Models\CfdiDetalle;
use Illuminate\Console\Command;
use App\Services\FinkokService;
use App\Models\Cfdi;
use App\Models\Sucursal;
use App\Models\SucursalEmisor;

class TestFinkokStamp extends Command
{
    protected $signature = 'finkok:test-stamp';
    protected $description = 'Realiza una prueba de timbrado CFDI 4.0 con el RFC de prueba del SAT';

    public function handle(FinkokService $finkok)
    {
        $this->info('--- Iniciando Prueba de Timbrado Finkok ---');

        $emisor = SucursalEmisor::first();

        if (!$emisor) {
            $this->error('Error: No se encontró ningún emisor en la tabla sucursal_emisores.');
            $this->info('Por favor, configure los datos fiscales de al menos una sucursal antes de correr esta prueba.');
            return;
        }

        // 1. Verificar Conexión Básica
        $this->comment('Verificando saldo de créditos...');
        $testConn = $finkok->testConnection();

        if (!$testConn['success']) {
            $this->error('Error de conexión: ' . $testConn['message']);
            return;
        }
        $this->info("Conexión exitosa. Créditos disponibles: " . $testConn['credits']);

        // 2. Crear Objeto CFDI ficticio para la prueba
        // Usamos datos genéricos de prueba del SAT
        $cfdi = new Cfdi([
            'serie' => 'TEST',
            'folio' => rand(1000, 9999),
            'forma_pago' => '01',
            'metodo_pago' => 'PUE',
            'uso_cfdi' => 'G03',
            'subtotal' => 100.00,
            'total' => 116.00,
            'impuestos' => 16.00,
        ]);

        // Simulamos la sucursal y vinculamos el emisor real de tu DB
        $sucursal = new \App\Models\Sucursal([
            'nombre' => 'SUCURSAL PRUEBA',
            'codigo_postal' => $emisor->codigo_postal
        ]);

        // 3. Intentar Timbrar
        $this->comment('Generando XML y solicitando timbre a Finkok...');

        $cfdi->setRelation('sucursal', $sucursal);
        $sucursal->setRelation('emisor', $emisor);

        // 2b. AGREGAR DETALLES (Conceptos) - Importante para el XML
        $detalles = collect([
            new CfdiDetalle([
                'clave_prod_serv' => '50192100', // Clave genérica
                'clave_unidad'    => 'H87',      // Pieza
                'descripcion'     => 'PRODUCTO DE PRUEBA SISTEMA',
                'cantidad'        => 1,
                'valor_unitario'  => 100.00,
                'importe'         => 100.00,
                'objeto_imp'      => '02',       // 02 = Sí objeto de impuesto
                'impuesto_base'   => 100.00,
                'impuesto_importe'=> 16.00,
                'impuesto_tasa_cuota' => '0.160000'
            ])
        ]);
        $cfdi->setRelation('detalles', $detalles);



        // Nota: El método timbrarFactura en FinkokService debe estar
        // preparado para manejar objetos CFDI sin UUID aún.
        $res = $finkok->timbrarFactura($cfdi);

        if ($res['success']) {
            $this->info('¡ÉXITO! Factura timbrada.');
            $this->line('UUID: ' . $res['uuid']);

            // Opcional: Guardar el XML de prueba para revisarlo
            $filename = "test_stamped_" . $res['uuid'] . ".xml";
            \Storage::disk('local')->put($filename, $res['xml']);
            $this->info("XML timbrado guardado en: storage/app/{$filename}");
        } else {
            $this->error('Fallo en el timbrado:');
            $this->line($res['message']);

            // Si hay incidencias técnicas del SAT, Finkok las devuelve aquí
            if (isset($res['detalle'])) {
                $this->table(['Código', 'Mensaje'], [$res['detalle']]);
            }
        }
    }
}
