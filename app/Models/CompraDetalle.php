<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class CompraDetalle extends Model
{
    use LogsActivity;

    public $fillable = ['compra_id', 'producto_id', 'cantidad', 'precio', 'costo_unitario', 'importe'];

    public function producto() { return $this->belongsTo(Producto::class); }
    public function compra() { return $this->belongsTo(Compra::class); }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->useLogName('compras_detalle'); // Categoría del log
    }
}
