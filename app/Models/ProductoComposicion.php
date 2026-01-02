<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ProductoComposicion extends Model
{
    use LogsActivity;

    protected $table = 'producto_composicion';

    protected $fillable = ['producto_padre_id', 'producto_hijo_id', 'cantidad'];

    protected $casts = [
        'cantidad' => 'decimal:6',
    ];

    public function productoHijo()
    {
        return $this->belongsTo(Producto::class, 'producto_hijo_id');
    }
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->useLogName('Producto Compuesto'); // Categoría del log
    }
}
