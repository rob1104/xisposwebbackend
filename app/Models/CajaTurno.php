<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class CajaTurno extends Model
{
    use LogsActivity;

    protected $fillable = [
        'user_id',
        'sucursale_id',
        'saldo_inicial',
        'tipo_cambio',
        'abierto_at',
        'cerrado_at',
        'status',
        'denominaciones_arqueo',
        'saldo_cierre',
        'diferencia'
    ];

    protected $casts = [
        'denominaciones_arqueo' => 'array', // Convierte el JSON de la DB en un arreglo de PHP
        'saldo_apertura' => 'decimal:2',
        'saldo_cierre' => 'decimal:2',
        'diferencia' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->useLogName('turnos'); // Categoría del log
    }
}
