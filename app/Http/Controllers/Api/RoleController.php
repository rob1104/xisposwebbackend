<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function index()
    {
        $roles = Role::withCount('permissions')->get();
        return response()->json($roles);
    }

    public function store(Request $request)
    {
        $role = Role::create(['name' => $request->name, 'guard_name' => 'web']);
        $role->syncPermissions($request->permissions);
        return response()->json([
            'message' => 'Rol creado correctamente'
        ]);
    }

    public function update(Request $request, Role $role)
    {
        $request->validate(['name' => 'required|unique:roles,name,' . $role->id]);

        $role->update(['name' => $request->name]);
        $role->syncPermissions($request->permissions);

        return response()->json(['message' => 'Rol actualizado correctamente']);
    }

    public function show(Role $role)
    {
        // Cargamos la relación de permisos para que el frontend
        // pueda marcar los checkboxes correspondientes
        $role->load('permissions:id,name');

        return response()->json($role);
    }

    public function getAllPermissions()
    {
        return response()->json(Permission::all());
    }

    public function destroy(Role $role)
    {
        // 1. Verificar si hay usuarios que dependen de este rol
        // Usamos el conteo de la relación 'users' proporcionada por Spatie
        $usersCount = $role->users()->count();

        if ($usersCount > 0) {
            return response()->json([
                'message' => "Acción denegada: El rol '{$role->name}' está asignado a {$usersCount} usuario(s). " .
                    "Debe reasignar a estos usuarios a otro perfil antes de eliminarlo."
            ], 422); // Usamos 422 para que el Notify del frontend lo capture como error de validación
        }

        // 2. Regla de Oro: Proteger el rol de Administrador
        if ($role->name === 'Administrador') {
            return response()->json([
                'message' => 'No es posible eliminar el rol maestro de Administrador.'
            ], 403);
        }

        // 3. Proceder con la eliminación
        $role->delete();

        return response()->json([
            'message' => "El rol '{$role->name}' ha sido eliminado exitosamente de los registros."
        ]);
    }
}
