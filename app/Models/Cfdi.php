<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Cfdi extends Model
{
    use LogsActivity;

    protected $fillable = [
        'serie',
        'folio',
        'forma_pago',
        'metodo_pago',
        'uso_cfdi',
        'subtotal',
        'total',
        'impuestos',
    ];

    public function detalles() { return $this->hasMany(CfdiDetalle::class) ;}

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->useLogName('cfdis'); // Categoría del log
    }
}
