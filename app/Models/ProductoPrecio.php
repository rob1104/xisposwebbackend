<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ProductoPrecio extends Model
{
    use LogsActivity;

    protected $fillable = ['producto_id', 'nombre_lista', 'precio', 'utilidad_porcentaje'];

    protected $casts = [
        'precio' => 'decimal:6',
        'utilidad_porcentaje' => 'float',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->useLogName('Productos Precio');
    }
}
