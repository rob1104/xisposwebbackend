<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class InventarioMovimiento extends Model
{
    use LogsActivity;
    protected $fillable = [
        'sucursal_id', 'producto_id', 'user_id', 'tipo_movimiento',
        'cantidad', 'stock_anterior', 'stock_nuevo', 'observaciones'
    ];

    public function producto() { return $this->belongsTo(Producto::class); }
    public function sucursal() { return $this->belongsTo(Sucursal::class); }
    public function user() { return $this->belongsTo(User::class); }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->useLogName('movimientos'); // Categoría del log
    }
}
