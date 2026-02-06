<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class RestOrdenDetalle extends Model
{
    use LogsActivity;

    protected $fillable = [
        'rest_orden_id',
        'producto_id',
        'impreso_cocina',
        'cantidad',
        'precio',
        'notas',
    ];
    protected $table = 'rest_orden_detalles';

    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }

    public function restOrden()
    {
        return $this->belongsTo(RestOrden::class);
    }
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->useLogName('rest_ordenes_detalle');
    }

}
