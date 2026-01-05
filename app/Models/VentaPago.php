<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class VentaPago extends Model
{
    use LogsActivity;

    protected $fillable = [
        'venta_id',
        'metodo_pago',
        'monto',
        'efectivo_recibido',
        'cambio_entregado',
        'tarjeta_ultimos_4',
        'referencia_pago',
        'banco_emisor'
    ];

    public function venta() { return $this->belongsTo(Venta::class); }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->useLogName('ventas_pago');
    }
}
