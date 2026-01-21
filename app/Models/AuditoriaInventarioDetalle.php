<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class AuditoriaInventarioDetalle extends Model
{
    use LogsActivity;

    protected $fillable = ['auditoria_inventario_id', 'producto_id', 'stock_sistema', 'stock_fisico', 'diferencia'];

    public function producto() { return $this->belongsTo(Producto::class); }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->useLogName('auditoria_inventario_detalle');
    }
}
