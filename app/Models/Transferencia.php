<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Transferencia extends Model
{
    use LogsActivity;

    protected $fillable = [
        'sucursal_origen_id',
        'sucursal_destino_id',
        'user_envia_id',
        'estatus',
        'fecha_envio',
        'notas',
        'producto_id',
        'cantidad_enviada',
        'cantidad_recibida',
        'user_recibe_id',
        'fecha_recepcion',
    ];

    public function detalles() { return $this->hasMany(TransferenciaDetalle::class); }

    public function sucursalOrigen() { return $this->belongsTo(Sucursal::class, 'sucursal_origen_id'); }
    public function sucursalDestino() { return $this->belongsTo(Sucursal::class, 'sucursal_destino_id'); }
    public function userEnvia() { return $this->belongsTo(User::class, 'user_envia_id'); }
    public function userRecibe() { return $this->belongsTo(User::class, 'user_recibe_id'); }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->useLogName('transferencias');
    }
}
