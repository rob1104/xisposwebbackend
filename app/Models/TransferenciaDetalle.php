<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class TransferenciaDetalle extends Model
{
    use LogsActivity;

    protected  $fillable = ['producto_id', 'cantidad_enviada', 'cantidad_recibida', 'transferencia_id'];

    public function producto() { return $this->belongsTo(Producto::class); }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->useLogName('transferencia_detalles');
    }
}
