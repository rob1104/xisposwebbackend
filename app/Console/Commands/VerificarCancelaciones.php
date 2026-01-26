<?php

namespace App\Console\Commands;

use App\Services\FinkokService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class VerificarCancelaciones extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cfdi:verificar-cancelaciones';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Consulta al SAT el estatus de las facturas en proceso de cancelación';

    /**
     * Execute the console command.
     */
    public function handle(FinkokService $finkok)
    {
        $this->info('Iniciando verificación de cancelaciones pendientes...');

        // 1. Buscamos solo las que están esperando respuesta
        $pendientes = Cfdi::where('status', 'En Proceso Cancelacion')
            ->whereNotNull('uuid')
            ->get();

        if ($pendientes->isEmpty()) {
            $this->info('No hay facturas pendientes de verificación.');
            return;
        }

        foreach ($pendientes as $cfdi) {
            $this->info("Verificando UUID: {$cfdi->uuid}");

            try {
                // 2. Consultar al SAT
                $resultado = $finkok->consultarEstatusSat(
                    $cfdi->uuid,
                    $cfdi->total,
                    $cfdi->cliente->rfc
                );

                if (!$resultado['success']) {
                    $this->error("Error consultando UUID {$cfdi->uuid}: " . ($resultado['message'] ?? 'Desconocido'));
                    continue;
                }

                $estadoSat = $resultado['estado']; // 'Vigente' o 'Cancelado'
                $detalleCancelacion = $resultado['estatus_cancelacion']; // Ej: 'Solicitud rechazada'

                // CASO A: YA SE CANCELÓ (Aceptada o Plazo Vencido)
                if ($estadoSat === 'Cancelado') {
                    $cfdi->update([
                        'status' => 'Cancelada',
                        'fecha_cancelacion' => now() // Actualizamos fecha real
                    ]);
                    $this->info("--> FACTURA CANCELADA EXITOSAMENTE.");

                    // Opcional: Aquí podrías notificar al cliente por email
                }

                // CASO B: FUE RECHAZADA (El cliente dijo "No acepto la cancelación")
                // El SAT la regresa a 'Vigente' y en el detalle dice 'Solicitud rechazada'
                elseif ($estadoSat === 'Vigente' && str_contains(strtolower($detalleCancelacion), 'rechazada')) {
                    $cfdi->update([
                        'status' => 'Vigente', // Regresa a la vida
                        // Guardamos nota interna para saber qué pasó
                        'motivo_cancelacion' => $cfdi->motivo_cancelacion . ' (Rechazada por receptor)'
                    ]);
                    $this->warn("--> Cancelación RECHAZADA por el cliente. Vuelve a Vigente.");
                }

                // CASO C: SIGUE EN PROCESO
                else {
                    $this->line("--> Sigue en proceso: $detalleCancelacion");
                }

            } catch (\Exception $e) {
                Log::error("Error en comando cfdi:verificar-cancelaciones ID {$cfdi->id}: " . $e->getMessage());
                $this->error("Excepción: " . $e->getMessage());
            }
        }

        $this->info('Verificación completada.');
    }
}
