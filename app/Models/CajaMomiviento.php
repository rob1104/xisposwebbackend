<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class CajaMomiviento extends Model
{
    use LogsActivity;
    protected $fillable = [
        'caja_turno_id',
        'usuario_id',
        'tipo',
        'monto',
        'concepto'
    ];

    public function turno() {
        return $this->belongsTo(CajaTurno::class, 'caja_turno_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->useLogName('caja_movimientos');
    }
}
