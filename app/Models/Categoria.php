<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Categoria extends Model
{
    use LogsActivity;
    protected $fillable = ['nombre', 'descripcion', 'status'];

    public function productos()
    {
        return $this->hasMany(Producto::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->useLogName('categorias'); // Categoría del log
    }
}
