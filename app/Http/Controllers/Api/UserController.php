<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index()
    {
        // Traemos usuarios con sus roles para la tabla de Quasar
        $users = User::with(['roles:name', 'sucursales'])->get()->map(function($user) {
            return [
                'id'     => $user->id,
                'name'   => $user->name,
                'email'  => $user->email,
                'role'   => $user->roles->first()?->name ?? 'Sin Rol',
                'status' => $user->status,
                'sucursales' => $user->sucursales
            ];
        });
        return response()->json($users);
    }

    public function getRoles()
    {
        return response()->json(Role::pluck('name'));
    }

    public function store(USerRequest $request)
    {
        return DB::transaction(function () use ($request) {
            $user = User::create($request->validated());
            $user->assignRole($request->role);

            if ($request->has('sucursales')) {
                $user->sucursales()->sync($request->sucursales);
            }

            return response()->json([
                'message' => 'Usuario creado correctamente',
            ], 200);
        });
    }

    public function update(UserRequest $request, User $user)
    {
        return DB::transaction(function () use ($request, $user) {
            $data = $request->validated();

            if (empty($data['password'])) {
                unset($data['password']);
            }

            $user->update($data);

            $user->syncRoles([$request->role]);
            $user->sucursales()->sync($request->sucursales);

            return response()->json([
                'message' => 'Usuario actualizado correctamente.'
            ]);
        });
    }

    public function destroy(User $user)
    {
        if ($user->id === auth()->id()) {
            return response()->json([
                'message' => 'Operación denegada: No puedes eliminar tu propia cuenta.'
            ], 403);
        }

        $user->delete();
        return response()->json([
            'message' => 'Usuario eliminado de los registros.'
        ]);
    }
}
