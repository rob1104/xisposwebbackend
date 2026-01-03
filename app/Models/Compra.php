<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Compra extends Model
{
    use LogsActivity;
    //
    protected $fillable = [
        'sucursale_id',
        'provider_id',
        'user_id',
        'folio',
        'referencia',
        'fecha',
        'subtotal',
        'iva',
        'total',
        'saldo',
        'estatus',
        'fecha_vencimiento',
        'observaciones',
        'metodo_pago',
    ];


    public function sucursal() { return $this->belongsTo(Sucursal::class); }
    public function provider() { return $this->belongsTo(Provider::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function detalles() { return $this->hasMany(CompraDetalle::class); }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->useLogName('compras'); // Categoría del log
    }
}
