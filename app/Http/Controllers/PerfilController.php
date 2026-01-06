<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class PerfilController extends Controller
{
    /**
     * Actualiza el nombre del usuario autenticado.
     */
    public function updateName(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|min:3',
        ]);

        $user = $request->user();
        $user->update(['name' => $request->name]);

        return response()->json([
            'message' => 'Nombre actualizado con éxito',
            'user' => $user
        ]);
    }

    /**
     * Actualiza la contraseña con validación de seguridad.
     */
    public function updatePassword(Request $request)
    {
        // 1. Validación de reglas de contraseña
        $request->validate([
            'current_password' => 'required',
            'password' => ['required', 'confirmed', Password::min(8)],
        ], [
            'password.confirmed' => 'La confirmación de la contraseña no coincide.',
            'password.min' => 'La nueva contraseña debe tener al menos 8 caracteres.'
        ]);

        $user = $request->user();

        // 2. Verificar que la contraseña actual sea correcta
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'message' => 'La contraseña actual es incorrecta.'
            ], 422);
        }

        // 3. Actualizar en la base de datos
        return DB::transaction(function () use ($user, $request) {
            $user->update([
                'password' => Hash::make($request->password)
            ]);

            return response()->json([
                'message' => 'Contraseña actualizada correctamente.'
            ]);
        });
    }
}
