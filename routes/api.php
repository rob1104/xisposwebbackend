<?php

use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\CategoriaController;
use App\Http\Controllers\Api\ClientesController;
use App\Http\Controllers\Api\ImpuestoController;
use App\Http\Controllers\Api\InventarioController;
use App\Http\Controllers\Api\ProductoController;
use App\Http\Controllers\Api\ProveedorController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SucursalController;
use App\Http\Controllers\Api\TransferenciaController;
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

    Route::get('/productos/search', [ProductoController::class, 'search']);

    Route::apiResource('users', UserController::class);
    Route::apiResource('roles', RoleController::class);
    Route::apiResource('sucursales', SucursalController::class);
    Route::apiResource('providers', ProveedorController::class);
    Route::apiResource('categorias', CategoriaController::class);
    Route::apiResource('impuestos', ImpuestoController::class);
    Route::apiResource('productos', ProductoController::class);

    Route::get('/roles-list', [UserController::class, 'getRoles'])->name('roles.list');
    Route::get('/permissions-all', [RoleController::class, 'getAllPermissions']);
    Route::get('/clientes', [ClientesController::class, 'index'])->name('clientes.index');
    Route::post('/clientes', [ClientesController::class, 'store'])->name('clientes.store');
    Route::put('/clientes/{cliente}', [ClientesController::class, 'update'])->name('clientes.update');
    Route::delete('/clientes/{cliente}', [ClientesController::class, 'destroy'])->name('clientes.delete');
    Route::get('/tax-regimes', function() {
        return TaxRegime::all();
    });
    Route::get('/logs', [AuditController::class, 'index'])->name('logs.index');

    // --- MÓDULO DE TRANSFERENCIAS ENTRE SUCURSALES ---
    Route::prefix('transferencias')->group(function () {
        // Listar envíos que vienen en camino hacia la sucursal
        Route::get('pendientes', [TransferenciaController::class, 'pendientes']);

        // Registrar la salida de mercancía (Envío)
        Route::post('enviar', [TransferenciaController::class, 'store']);

        // Registrar la entrada física de mercancía (Recepción)
        Route::post('recibir/{id}', [TransferenciaController::class, 'recibir']);

        // Historial general de transferencias para el administrador
        Route::get('historial', [TransferenciaController::class, 'index']);
    });

    // --- MÓDULO DE INVENTARIOS Y KARDEX ---
    Route::prefix('inventario')->group(function () {
        Route::get('/', [InventarioController::class, 'index'])->name('inventario.index');
        Route::get('/buscar-filtro', [InventarioController::class, 'buscarProducto'])->name('inventario.buscar');
        Route::get('/stock-especifico', [InventarioController::class, 'obtenerStockActual'])->name('inventario.obtener-stock-actual');
        Route::post('/movimiento', [InventarioController::class, 'registrarMovimiento']);
        //Todas las sucursales
        ROute::get(('reporte-consolidado'), [InventarioController::class, 'reporteConsolidado']);
        // Consulta de stock en todas las sucursales para un producto
        Route::get('stock-global/{producto_id}', [InventarioController::class, 'stockGlobal']);

        // El historial detallado de movimientos (Auditoría)
        Route::get('kardex/{producto_id}', [InventarioController::class, 'kardexPorProducto']);

        // Reporte de inventario valorizado por sucursal
        Route::get('valorizado/{sucursal_id}', [InventarioController::class, 'reporteValorizado']);
    });

    Route::get('/reportes/stock', [InventarioController::class, 'reporteStock']);
});
