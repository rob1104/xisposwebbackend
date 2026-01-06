<?php

use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\CatalogoController;
use App\Http\Controllers\Api\ClientesController;
use App\Http\Controllers\Api\CompraController;
use App\Http\Controllers\Api\InventarioController;
use App\Http\Controllers\Api\PosController;
use App\Http\Controllers\Api\ProductoController;
use App\Http\Controllers\Api\ProveedorController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SucursalController;
use App\Http\Controllers\Api\TransferenciaController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VentaController;
use App\Http\Controllers\ConfiguracionController;
use App\Http\Controllers\PerfilController;
use App\Models\TaxRegime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;


Route::middleware('auth:sanctum')->group(function () {

    Route::get('/config', [ConfiguracionController::class, 'index']);
    Route::post('config/update', [ConfiguracionController::class, 'update']);
    Route::get('/user', function (Request $request) {
        $user = $request->user();
        return [
            'user' => $user,
            'roles' => $user->getRoleNames(),
            'sucursales' => $user->getAllowedBranches(),
            'sucursal_activa_id' => $user->sucursal_activa_id,
            'permissions' => $user->getAllPermissions()
        ];
    });

    Route::get('/clientes-search', function (Request $request) {
        $q = $request->q;
        return App\Models\Cliente::where('nombre_comercial', 'LIKE', "%$q%")
            ->orWhere('rfc', 'LIKE', "%$q%")
            ->limit(10)
            ->get(['id', 'nombre_comercial', 'rfc', 'limite_credito', 'saldo_actual', 'dias_credito', 'vender_vencido']);
    });

    Route::get('/productos/search', [ProductoController::class, 'search']);

    Route::apiResource('users', UserController::class);
    Route::apiResource('roles', RoleController::class);
    Route::apiResource('clientes', ClientesController::class);
    Route::apiResource('providers', ProveedorController::class);
    Route::apiResource('productos', ProductoController::class);
    Route::apiResource('sucursales', SucursalController::class);
    Route::apiResource('compras', CompraController::class);
    Route::apiResource('ventas', VentaController::class);

    Route::get('clientes/{îd}/antiguedad', [ClientesController::class, 'getAntiguedadSaldos'])->name('clientes.antiguedad');

    Route::put('/compras/{id}/cancelar', [CompraController::class, 'cancelar'])->name('compras.cancelar');
    Route::put('/ventas/{id}/cancelar', [VentaController::class, 'cancelar'])->name('ventas.cancelar');

    Route::get('/roles-list', [UserController::class, 'getRoles'])->name('roles.list');
    Route::get('/permissions-all', [RoleController::class, 'getAllPermissions']);


    Route::get('/proveedores/buscar', [ProveedorController::class, 'buscar']);

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
        ROute::get(('/reporte-consolidado'), [InventarioController::class, 'reporteConsolidado']);
        // Consulta de stock en todas las sucursales para un producto
        Route::get('stock-global/{producto_id}', [InventarioController::class, 'stockGlobal']);

        // El historial detallado de movimientos (Auditoría)
        Route::get('kardex/{producto_id}', [InventarioController::class, 'kardexPorProducto']);

        // Reporte de inventario valorizado por sucursal
        Route::get('valorizado/{sucursal_id}', [InventarioController::class, 'reporteValorizado']);
    });

    Route::prefix('catalogos')->group(function () {

        // Rutas para Categorías
        Route::get('categorias', [CatalogoController::class, 'getCategorias']);
        Route::post('categorias', [CatalogoController::class, 'storeCategoria']);
        Route::put('categorias/{id}', [CatalogoController::class, 'updateCategoria']);
        Route::delete('categoria/{id}', [CatalogoController::class, 'destroyCategoria']);

        // Rutas para Impuestos
        Route::get('impuestos', [CatalogoController::class, 'getImpuestos']);
        Route::post('impuestos', [CatalogoController::class, 'storeImpuesto']);
        Route::put('impuestos/{id}', [CatalogoController::class, 'updateImpuesto']); // Agregado para edición
        Route::delete('impuesto/{id}', [CatalogoController::class, 'destroyImpuesto']);

        // Rutas para Unidades de Medida
        Route::get('medidas', [CatalogoController::class, 'getUnidades']);
        Route::post('medidas', [CatalogoController::class, 'storeUnidad']);
        Route::put('medidas/{id}', [CatalogoController::class, 'updateUnidad']);
        Route::delete('medidas/{id}', [CatalogoController::class, 'destroyUnidad']);

    });

    Route::prefix('pos')->group(function () {
        Route::get('/verificar-turno', [PosController::class, 'verificarTurno']); //
        Route::post('/abrir-turno', [PosController::class, 'abrirTurno']); //
        // Para el escáner (búsqueda exacta por código de barras)
        Route::get('/producto/{barcode}', [PosController::class, 'getByBarcode']);

        // Para el diálogo de búsqueda (filtro por nombre o código parcial)
        Route::get('/buscar-filtro', [PosController::class, 'searchByFilter']);

        Route::post('/finalizar-venta', [VentaController::class, 'store']);

        Route::get('/balance-turno/{id}', [PosController::class, 'balanceTurno']);
        Route::post('/cerrar-turno', [PosController::class, 'cerrarTurno']);
    });

    Route::post('perfil/update-name', [PerfilController::class, 'updateName']);
    Route::post('perfil/update-password', [PerfilController::class, 'updatePassword']);

    Route::get('/reportes/stock', [InventarioController::class, 'reporteStock']);
});

Route::get('/config', [ConfiguracionController::class, 'index']);
