<?php

use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\ClientesController;
use App\Http\Controllers\Api\ProveedorController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SucursalController;
use App\Http\Controllers\Api\UserController;
use App\Models\TaxRegime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->get('/user', function (Request $request) {
    $user = $request->user();
    return [
        'user' => $user,
        'roles' => $user->getRoleNames(),
        'sucursales' => $user->getAllowedBranches()
    ];
});

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('users', UserController::class);
    Route::apiResource('roles', RoleController::class);
    Route::apiResource('sucursales', SucursalController::class);
    Route::apiResource('providers', ProveedorController::class);
    Route::get('/roles-list', [UserController::class, 'getRoles'])->name('roles.list');
    Route::get('permissions-all', [RoleController::class, 'getAllPermissions']);
    Route::get('/clientes', [ClientesController::class, 'index'])->name('clientes.index');
    Route::post('/clientes', [ClientesController::class, 'store'])->name('clientes.store');
    Route::put('/clientes/{cliente}', [ClientesController::class, 'update'])->name('clientes.update');
    Route::delete('/clientes/{cliente}', [ClientesController::class, 'destroy'])->name('clientes.delete');
    Route::get('/tax-regimes', function() {
        return TaxRegime::all();
    });
    Route::get('/logs', [AuditController::class, 'index'])->name('logs.index');
});
