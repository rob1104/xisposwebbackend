<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Venta extends Model
{
    use LogsActivity;
    //
    protected $fillable = [
        'folio',
        'sucursale_id',
        'user_id',
        'caja_turno_id',
        'cliente_id',
        'subtotal',
        'impuestos',
        'total',
        'tipo_cambio',
        'status',
        'notas',
        'metodo_pago',
        'monto',
        'referencia_pago',
        'tarjeta_ultimos_4',
        'efectivo_recibido',
        'cambio_entregado',
        'cfdi_id',
        'facturado',
        'via_venta'
    ];

    public function detalles()
    {
        return $this->hasMany(VentaDetalle::class);
    }

    public function pagos()
    {
        return $this->hasMany(VentaPago::class);
    }

    public function cfdi()
    {
        return $this->hasOne(Cfdi::class, 'venta_id', 'id')->withDefault(['folio' => 'Sin CFDI']);
    }

    public function cliente()
    {
        return $this->belongsTo(Cliente::class);
    }

    public function turno()
    {
        return $this->belongsTo(CajaTurno::class);
    }

    public function sucursal()
    {
        return $this->belongsTo(Sucursal::class, 'sucursale_id', 'id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopePendientes($query)
    {
        return $query->where('facturado', false)->where('status', 'Completado');
    }

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->useLogName('ventas');
    }
}
