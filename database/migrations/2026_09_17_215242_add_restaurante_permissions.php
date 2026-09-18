<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Limpiamos caché de permisos primero
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'restaurante.ver',
            'restaurante.config',
            'restaurante.ordenes'
        ];

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        // Asignar al rol "Administrador" (si existe) y "Super Administrador" (o equivalentes)
        $roles = Role::whereIn('name', ['Administrador', 'Super Administrador'])->get();
        foreach ($roles as $role) {
            $role->givePermissionTo($permissions);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        
        $permissions = [
            'restaurante.ver',
            'restaurante.config',
            'restaurante.ordenes'
        ];

        foreach ($permissions as $perm) {
            $p = Permission::where('name', $perm)->first();
            if ($p) {
                $p->delete();
            }
        }
    }
};
