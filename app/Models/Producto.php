<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Producto extends Model
{
    use LogsActivity;

    protected $fillable = [
        'codigo_barras', 'nombre', 'categoria_id',
        'clave_prod_serv', 'clave_unidad', 'objeto_imp', 'tipo_producto',
        'ultimo_costo_compra', 'usuario_creador', 'status'
    ];

    protected $casts = [
        'ultimo_costo_compra' => 'decimal:6', // Forzamos 6 decimales
        'status' => 'boolean',
    ];

    public function categoria()
    {
        return $this->belongsTo(Categoria::class);
    }

    public function precios()
    {
        return $this->hasMany(ProductoPrecio::class);
    }

    public function impuestos()
    {
        return $this->belongsToMany(Impuesto::class, 'producto_impuestos', 'producto_id', 'impuesto_id');
    }

    public function umedida()
    {
        return $this->belongsTo(Medida::class, 'clave_unidad', 'c_ClaveUnidad');
    }

    public function sucursales()
    {
        return $this->belongsToMany(Sucursal::class, 'sucursal_productos')
            ->withPivot(['stock_actual', 'stock_minimo', 'stock_maximo', 'costo_promedio'])
            ->withTimestamps();
    }

    // Lógica de Producto Compuesto (Hijos que lo integran)
    public function componentes()
    {
        return $this->belongsToMany(Producto::class, 'producto_composicion', 'producto_padre_id', 'producto_hijo_id')
            ->withPivot('cantidad')
            ->withTimestamps();
    }

    public function sucursalProductos()
    {
        return $this->hasMany(SucursalProducto::class, 'producto_id', 'id');
    }

    public function ventas() { return $this->hasMany(VentaDetalle::class); }
    public function movimientos() { return $this->hasMany(InventarioMovimiento::class); }


    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->useLogName('productos'); // Categoría del log
    }

    public function actualizarStockSucursal(mixed $sucursale_id, mixed $stock_fisico)
    {
        return DB::table('sucursal_productos')->updateOrInsert(
            [
                'producto_id' => $this->id,
                'sucursal_id' => $sucursale_id
            ],
            [
                'stock_actual' => $stock_fisico,
                'updated_at'   => now()
            ]
        );
    }

    public function stockEnSucursal(mixed $sucursal_id)
    {
        $inventario = DB::table('sucursal_productos')
            ->where('producto_id', $this->id)
            ->where('sucursal_id', $sucursal_id)
            ->first();
        return $inventario ? (float) $inventario->stock_actual : 0;
    }
}
