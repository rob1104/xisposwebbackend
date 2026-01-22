<?php

use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\AuditoriaInventarioController;
use App\Http\Controllers\Api\CajaMovimientoController;
use App\Http\Controllers\Api\CajaTurnoController;
use App\Http\Controllers\Api\CatalogoController;
use App\Http\Controllers\Api\CfdiController;
use App\Http\Controllers\Api\ClientesController;
use App\Http\Controllers\Api\CompraController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\InventarioController;
use App\Http\Controllers\Api\PosController;
use App\Http\Controllers\Api\ProductoController;
use App\Http\Controllers\Api\ProveedorController;
use App\Http\Controllers\Api\ReportesController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SucursalController;
use App\Http\Controllers\Api\TransferenciaController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VentaController;
use App\Http\Controllers\ConfiguracionController;
use App\Http\Controllers\PerfilController;
use App\Http\Controllers\TicketController;
use App\Models\TaxRegime;
use App\Models\UsoCfdi;
use App\Services\FinkokService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('dashboard/summary', [DashboardController::class, 'getSummary']);
    Route::get('/config', [ConfiguracionController::class, 'index']);
    Route::post('config/update', [ConfiguracionController::class, 'update']);
    Route::post('/config/sincronizar-inventarios', [ConfiguracionController::class, 'sincronizarInventariosCero']);

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

    Route::get('/productos/search', [ProductoController::class, 'search'])->name('productos.buscar');
    Route::get('/productos/{id}/precios', [ProductoController::class, 'getPrecios'])->name('productos.precios');
    Route::get('/auth/gerentes', [UserController::class, 'getGerentes'])->name('auth.obtener-gerentes');
    Route::post('/auth/verificar-gerente', [UserController::class, 'verificarGerente'])->name('auth.verificar-gerente');

    Route::apiResource('users', UserController::class);
    Route::apiResource('roles', RoleController::class);
    Route::apiResource('clientes', ClientesController::class);
    Route::apiResource('providers', ProveedorController::class);
    Route::apiResource('productos', ProductoController::class);
    Route::apiResource('sucursales', SucursalController::class);
    Route::apiResource('compras', CompraController::class);
    Route::apiResource('ventas', VentaController::class);
    Route::apiResource('cfdis', CfdiController::class);
    Route::apiResource('auditoriainventario', AuditoriaInventarioController::class);

    Route::prefix('cfdis')->group(function () {
        Route::post('/timbrar', [CfdiController::class, 'timbrar'])->name('cfdis.timbrar');
        Route::get('/{id}/xml', [CfdiController::class, 'descargarXml']);
        Route::post('/{id}/reintentar', [CfdiController::class, 'reintentar']);
        Route::get('/{id}/pdf', [CfdiController::class, 'descargarPdf']);
        Route::post('/{id}/generar-pdf', [CfdiController::class, 'generarPdf']);
        Route::get('/ventas/pendientes', [CfdiController::class, 'ventasPendientes'])->name('cfdis.ventas.pendientes');
    });

    Route::post('/sucursales/{id}/emisor', [SucursalController::class, 'updateEmisor'])->name('sucursales.emisor.update');
    Route::get('/sucursales/{id}/emisor', [SucursalController::class, 'getEmisor'])->name('sucursales.emisor.get');


    Route::get('/auditoria/productos-sucursal/{sucursal_id}', [AuditoriaInventarioController::class, 'obtenerProductosParaConteo']);
    Route::post('/auditoria/procesar', [AuditoriaInventarioController::class, 'procesarConteo']);
    Route::get('/auditoria/reporte/pdf/{id}', [AuditoriaInventarioController::class, 'generaPDF']);

    Route::get('/compras/{id}/pdf', [CompraController::class, 'descargarPDF'])->name('compras.pdf');

    Route::post('/caja/movimientos', [CajaMovimientoController::class, 'store'])->name('caja.movimientos.store');

    Route::get('/clientes/{numero}/global', [ClientesController::class, 'buscarPorNumeroGlobal'])->name('clientes.global');

    Route::get('clientes/{îd}/antiguedad', [ClientesController::class, 'getAntiguedadSaldos'])->name('clientes.antiguedad');
    Route::get('proveedores/{îd}/antiguedad', [ProveedorController::class, 'getAntiguedadSaldos'])->name('proveedores.antiguedad');

    Route::put('/compras/{id}/cancelar', [CompraController::class, 'cancelar'])->name('compras.cancelar');
    Route::put('/ventas/{id}/cancelar', [VentaController::class, 'cancelar'])->name('ventas.cancelar');

    Route::get('/roles-list', [UserController::class, 'getRoles'])->name('roles.list');
    Route::get('/permissions-all', [RoleController::class, 'getAllPermissions'])->name('permissions.all');

    Route::get('/proveedores/buscar', [ProveedorController::class, 'buscar']);

    Route::get('/tax-regimes', function () {
        return TaxRegime::all();
    });
    Route::get('/usos-cfdi', function () {
        return UsoCfdi::all();
    });
    Route::get('/logs', [AuditController::class, 'index'])->name('logs.index');

    Route::prefix('transferencias')->group(function () {
        Route::get('pendientes', [TransferenciaController::class, 'pendientes']);
        Route::post('enviar', [TransferenciaController::class, 'store']);
        Route::post('recibir/{id}', [TransferenciaController::class, 'recibir']);
        Route::get('historial', [TransferenciaController::class, 'index']);
    });

    Route::prefix('inventario')->group(function () {
        Route::get('/', [InventarioController::class, 'index'])->name('inventario.index');
        Route::get('/buscar-filtro', [InventarioController::class, 'buscarProducto'])->name('inventario.buscar');
        Route::get('/stock-especifico', [InventarioController::class, 'obtenerStockActual'])->name('inventario.obtener-stock-actual');
        Route::post('/movimiento', [InventarioController::class, 'registrarMovimiento']);
        ROute::get(('/reporte-consolidado'), [InventarioController::class, 'reporteConsolidado']);
        // Consulta de stock en todas las sucursales para un producto
        Route::get('stock-global/{producto_id}', [InventarioController::class, 'stockGlobal']);

        // El historial detallado de movimientos (Kardex)
        Route::get('kardex/{producto_id}', [InventarioController::class, 'kardexPorProducto']);

        // Reporte de inventario valorizado por sucursal
        Route::get('valorizado/{sucursal_id}', [InventarioController::class, 'reporteValorizado']);
        Route::get('historico', [InventarioController::class, 'reporteHistorico'])->name('inventario.historico');
    });

    Route::get('productos/{id}/kardex', [InventarioController::class, 'getKardex']);

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
        Route::get('/verificar-turno', [PosController::class, 'verificarTurno']);
        Route::post('/abrir-turno', [PosController::class, 'abrirTurno']);

        /// Para el escáner (búsqueda exacta por código de barras)
        Route::get('/producto/{barcode}', [PosController::class, 'getByBarcode']);

        // Para el diálogo de búsqueda (filtro por nombre o código parcial)
        Route::get('/buscar-filtro', [PosController::class, 'searchByFilter']);

        Route::post('/finalizar-venta', [VentaController::class, 'store']);

        Route::get('/print-corte/{id}', [PosController::class, 'datosImpresionCorte']);

        Route::get('/balance-turno/{id}', [PosController::class, 'balanceTurno']);
        Route::post('/cerrar-turno', [PosController::class, 'cerrarTurno']);
        Route::get('/ultimo-ticket', [PosController::class, 'getUltimoTicket']);
        Route::get('/sugerencia-apertura', [PosController::class, 'obtenerSugerenciaApertura'])->name('pos.sugerencia-apertura');
    });

    Route::prefix('reportes')->group(function () {
        Route::get('/ventas-detalladas', [ReportesController::class, 'ventasDetalladas']);
        Route::get('/ventas-detalladas/pdf', [ReportesController::class, 'ventasDetalladasexportarPdf']);
        Route::get('/stock', [InventarioController::class, 'reporteStock']);
    });

    Route::get('/turnos', [CajaTurnoController::class, 'index'])->name('caja.turnos.index');
    Route::get('/turnos/pdf/{id}', [CajaTurnoController::class, 'downloadPdf'])->name('caja.turnos.downloadpdf');

    Route::post('perfil/update-name', [PerfilController::class, 'updateName']);
    Route::post('perfil/update-password', [PerfilController::class, 'updatePassword']);

    Route::get('sucursales/{id}/ticket-config', [TicketController::class, 'show']);
    Route::post('sucursales/{id}/ticket-config', [TicketController::class, 'store']);

});

Route::get('/config', [ConfiguracionController::class, 'index']);
