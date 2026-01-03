<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Medida extends Model
{
    use LogsActivity;

    protected $fillable = ['c_ClaveUnidad', 'nombre'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->useLogName('Umedidas'); // Categoría del log
    }
}
