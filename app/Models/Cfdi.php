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
        'sucursale_id',
        'cliente_id',
        'user_id',
        'venta_id',
        'uuid',
        'status',
        'exportacion',
        'xml_path',
        'pdf_path',
        'motivo_cancelacion',
        'fecha_cancelacion',
        'acuse_path',
    ];

    public function cliente() { return $this->belongsTo(Cliente::class); }

    public function venta() { return $this->belongsTo(Venta::class); }

    public function sucursal() { return $this->belongsTo(Sucursal::class, 'sucursale_id', 'id'); }

    public function detalles() { return $this->hasMany(CfdiDetalle::class) ;}

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->useLogName('cfdis');
    }
}
