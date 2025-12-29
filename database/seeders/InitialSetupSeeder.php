<?php

namespace Database\Seeders;

use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class InitialSetupSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $sucursal1 = Sucursal::create(['nombre' => 'Sucursal Norte']);
        $sucursal2 = Sucursal::create(['nombre' => 'Sucursal Sur']);

        $adminRole = Role::create(['name' => 'Administrador']);
        $gerenteRole = Role::create(['name' => 'Gerente']);
        $cajeroRole = Role::create(['name' => 'Cajero']);
        $auxiliarRole = Role::create(['name' => 'Auxiliar']);

        $admin = User::create([
            'name' => 'Admin Global',
            'email' => 'admin@pos.com',
            'password' => bcrypt('password'),
        ]);
        $admin->assignRole($adminRole);

        $cajero = User::create([
            'name' => 'Juan Cajero',
            'email' => 'juan@pos.com',
            'password' => bcrypt('password'),
        ]);
        $cajero->assignRole($cajeroRole);
        $cajero->sucursales()->attach($sucursal1->id);

    }
}
