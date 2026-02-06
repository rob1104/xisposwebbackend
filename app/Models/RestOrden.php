<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class RestOrden extends Model
{
    use LogsActivity;

    protected $fillable = [
        'sucursale_id',
        'mesa_id',
        'mesero_id',
        'nombre_cliente',
        'estatus',
        'total',
        'codigo_cobro',
    ];
    protected $table = 'rest_ordenes';

    public function detalles()
    {
        return $this->hasMany(RestOrdenDetalle::class);
    }

    public function mesa()
    {
        return $this->belongsTo(RestMesa::class, 'mesa_id');
    }

    public function mesero()
    {
        return $this->belongsTo(User::class, 'mesero_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->useLogName('rest_ordenes');
    }
}
