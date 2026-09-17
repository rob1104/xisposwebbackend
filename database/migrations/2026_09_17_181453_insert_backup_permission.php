<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permission = Permission::firstOrCreate([
            'name' => 'Respaldar base de datos',
            'guard_name' => 'web'
        ]);

        $role = Role::where('name', 'Super Administrador')->where('guard_name', 'web')->first();
        if ($role) {
            $role->givePermissionTo($permission);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permission = Permission::where('name', 'Respaldar base de datos')->where('guard_name', 'web')->first();
        if ($permission) {
            $permission->delete();
        }
    }
};
