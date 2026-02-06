<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class RestMesa extends Model
{
    use LogsActivity;

    protected $fillable = [
        'ocupada',
        'sucursale_id',
        'nombre',
        'zona',
    ];

    protected $table = 'rest_mesas';

    public function ordenActiva()
    {
        return $this->hasOne(RestOrden::class, 'mesa_id')
            ->whereIn('estatus', ['Abierta', 'Cocina'])
            ->latest();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->useLogName('rest_mesas');
    }
}
