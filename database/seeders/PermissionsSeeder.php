<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class PermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $modulos = ['clientes', 'productos', 'proveedores', 'usuarios', 'roles', 'ventas', 'reportes'];
        $acciones = ['ver', 'crear', 'editar', 'borrar'];

        foreach ($modulos as $m) {
            foreach ($acciones as $a) {
                Permission::create(['name' => "$m.$a"]);
            }
        }

        // Permisos especiales para procesos específicos
        Permission::create(['name' => 'ventas.pos']); // Acceso al Punto de Venta
        Permission::create(['name' => 'ventas.cancelar']); // Cancelar tickets
    }
}
