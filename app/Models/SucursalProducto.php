<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SucursalProducto extends Model
{
    use LogsActivity;

    protected $table = 'sucursal_productos';

    protected $fillable = [
        'sucursal_id', 'producto_id', 'stock_actual',
        'stock_minimo', 'stock_maximo', 'costo_promedio'
    ];

    protected $casts = [
        'stock_actual' => 'decimal:6',
        'stock_minimo' => 'decimal:6',
        'stock_maximo' => 'decimal:6',
        'costo_promedio' => 'decimal:6',
    ];

    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }

    public function sucursal()
    {
        return $this->belongsTo(Sucursal::class);
    }
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->useLogName('inventario'); // Categoría del log
    }
}
