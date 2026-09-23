<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $perm = Permission::firstOrCreate(['name' => 'reportes.traspasos', 'guard_name' => 'web']);
        
        $role = Role::where('name', 'Administrador')->first();
        if ($role) {
            $role->givePermissionTo($perm);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $perm = Permission::where('name', 'reportes.traspasos')->first();
        if ($perm) {
            $perm->delete();
        }
    }
};
