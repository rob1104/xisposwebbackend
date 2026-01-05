<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class VentaDetalle extends Model
{
    use LogsActivity;

    protected $fillable = [
        'venta_id',
        'producto_id',
        'cantidad',
        'precio_unitario',
        'impuesto_unitario',
        'subtotal',
        'total'
    ];

    public function producto() { return $this->belongsTo(Producto::class); }
    public function venta() { return $this->belongsTo(Venta::class); }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->useLogName('ventas_detalle');
    }
}
