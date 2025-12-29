<?php

use App\Http\Controllers\Api\ClientesController;
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
    Route::get('/clientes', [ClientesController::class, 'index']);
    Route::post('/clientes', [ClientesController::class, 'store']);
    Route::get('/tax-regimes', function() {
        return TaxRegime::all();
    });
});
