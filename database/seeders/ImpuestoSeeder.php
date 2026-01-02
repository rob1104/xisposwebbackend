<?php

namespace Database\Seeders;

use App\Models\Impuesto;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ImpuestoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $impuestos = [
            ['nombre' => 'IVA 16%', 'porcentaje' => 16.00, 'tipo' => 'Traslado'],
            ['nombre' => 'IVA 8% (Frontera)', 'porcentaje' => 8.00, 'tipo' => 'Traslado'],
            ['nombre' => 'IVA 0%', 'porcentaje' => 0.00, 'tipo' => 'Traslado'],
            ['nombre' => 'Exento', 'porcentaje' => 0.00, 'tipo' => 'Traslado']
        ];
        foreach ($impuestos as $imp) {
            Impuesto::create($imp);
        }
    }
}
