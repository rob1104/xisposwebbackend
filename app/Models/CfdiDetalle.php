<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class CfdiDetalle extends Model
{
    use LogsActivity;

    protected $fillable = [
        'clave_prod_serv',
        'clave_unidad',
        'descripcion',
        'cantidad',
        'valor_unitario',
        'importe',
        'objeto_imp',
        'impuesto_base',
        'impuesto_importe',
        'impuesto_tasa_cuota',
        'producto_id',
    ];

    public function cfdi() { return $this->belongsTo(Cfdi::class) ;}

    public function producto() { return $this->belongsTo(Producto::class); }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->useLogName('cfdis'); // Categoría del log
    }
}
