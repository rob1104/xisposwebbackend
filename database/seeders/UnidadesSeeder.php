<?php

namespace Database\Seeders;

use App\Models\Medida;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class UnidadesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $unidades = [
            ['c_ClaveUnidad' => '10' , 'nombre' => 'Grupos'],
            ['c_ClaveUnidad' => '11' , 'nombre' => 'Equipos'],
            ['c_ClaveUnidad' => 'A9' , 'nombre' => 'Tarifa'],
            ['c_ClaveUnidad' => 'AB' , 'nombre' => 'Paquete a granel'],
            ['c_ClaveUnidad' => 'ACT' , 'nombre' => 'Actividad'],
            ['c_ClaveUnidad' => 'AS' , 'nombre' => 'Variedad'],
            ['c_ClaveUnidad' => 'BB' , 'nombre' => 'Caja base'],
            ['c_ClaveUnidad' => 'DPC' , 'nombre' => 'Docenas de piezas'],
            ['c_ClaveUnidad' => 'EA' , 'nombre' => 'Elemento'],
            ['c_ClaveUnidad' => 'E48' , 'nombre' => 'Unidad de servicio'],
            ['c_ClaveUnidad' => 'E51' , 'nombre' => 'Trabajo'],
            ['c_ClaveUnidad' => 'E54' , 'nombre' => 'Viaje'],
            ['c_ClaveUnidad' => 'GRM' , 'nombre' => 'Gramo'],
            ['c_ClaveUnidad' => 'H87' , 'nombre' => 'Pieza'],
            ['c_ClaveUnidad' => 'HUR' , 'nombre' => 'Hora'],
            ['c_ClaveUnidad' => 'KGM' , 'nombre' => 'Kilogramo'],
            ['c_ClaveUnidad' => 'KT' , 'nombre' => 'Kit'],
            ['c_ClaveUnidad' => 'LTR' , 'nombre' => 'Litro'],
            ['c_ClaveUnidad' => 'MGM' , 'nombre' => 'Miligramo'],
            ['c_ClaveUnidad' => 'MLT' , 'nombre' => 'Mililitro'],
            ['c_ClaveUnidad' => 'MON' , 'nombre' => 'Mes'],
            ['c_ClaveUnidad' => 'MTK' , 'nombre' => 'Metro cuadrado'],
            ['c_ClaveUnidad' => 'MTR' , 'nombre' => 'Metro'],
            ['c_ClaveUnidad' => 'PR' , 'nombre' => 'Par'],
            ['c_ClaveUnidad' => 'SET' , 'nombre' => 'Conjunto'],
            ['c_ClaveUnidad' => 'XBX' , 'nombre' => 'Caja'],
            ['c_ClaveUnidad' => 'XPK' , 'nombre' => 'Paquete'],
            ['c_ClaveUnidad' => 'XKI' , 'nombre' => 'Kit (Conjunto de piezas)'],
            ['c_ClaveUnidad' => 'XLT' , 'nombre' => 'Lote'],
            ['c_ClaveUnidad' => 'xun' , 'nombre' => 'Unidad'],
            ['c_ClaveUnidad' => 'DAY' , 'nombre' => 'Día'],
        ];

        foreach ($unidades as $unidad) {
            Medida::updateOrCreate(
                ['c_ClaveUnidad' => $unidad['c_ClaveUnidad']],
                ['nombre' => $unidad['nombre']]
            );
        }
    }
}
