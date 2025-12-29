<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class TaxRegimeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $regimenes = [
            ['code' => '601', 'name' => 'General de Ley Personas Morales'],
            ['code' => '603', 'name' => 'Personas Morales con Fines no Lucrativos'],
            ['code' => '605', 'name' => 'Sueldos y Salarios e Ingresos Asimilados a Salarios'],
            ['code' => '606', 'name' => 'Arrendamiento'],
            ['code' => '608', 'name' => 'Demás ingresos'],
            ['code' => '610', 'name' => 'Residentes en el Extranjero sin Establecimiento Permanente en México'],
            ['code' => '611', 'name' => 'Ingresos por Dividendos (socios y accionistas)'],
            ['code' => '612', 'name' => 'Personas Físicas con Actividades Empresariales y Profesionales'],
            ['code' => '614', 'name' => 'Ingresos por intereses'],
            ['code' => '616', 'name' => 'Sin obligaciones fiscales'],
            ['code' => '620', 'name' => 'Sociedades Cooperativas de Producción que optan por diferir sus ingresos'],
            ['code' => '621', 'name' => 'Incorporación Fiscal'], // RIF (ya no permite nuevos contribuyentes)
            ['code' => '622', 'name' => 'Actividades Agrícolas, Ganaderas, Silvícolas y Pesqueras'],
            ['code' => '623', 'name' => 'Opcional para Grupos de Sociedades'],
            ['code' => '624', 'name' => 'Coordinados'],
            ['code' => '625', 'name' => 'Régimen de las Actividades Empresariales con ingresos a través de Plataformas Tecnológicas'],
            ['code' => '626', 'name' => 'Régimen Simplificado de Confianza (RESICO)'],
            ['code' => '628', 'name' => 'Hidrocarburos'],
            ['code' => '629', 'name' => 'De los Regímenes Fiscales Preferentes y de las Empresas Multinacionales'],
            ['code' => '630', 'name' => 'Enajenación de acciones en bolsa de valores'],
        ];


        foreach ($regimenes as $r) {
            \App\Models\TaxRegime::create($r);
        }
    }
}
