<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class AuthenticatedSessionController extends Controller
{
    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): JsonResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        activity('auth')
            ->performedOn($request->user())
            ->causedBy($request->user())
            ->withProperties(['ip' => $request->ip()])
            ->log('El usuario ha iniciado sesión');

        //Obtenemos el usuario autenticado y sucursales (tambien los Roles)
        $user = $request->user();
        $sucursales = $user->getAllowedBranches();
        $roles = $user->getRoleNames();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $roles,
                'sucursal_activa_id' => $user->sucursal_activa_id,
            ],
            'token' => $user->createToken('auth-token')->plainTextToken,
            'sucursales' => $sucursales,
            'message' => 'Login Exitoso'
        ]);
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): Response
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
