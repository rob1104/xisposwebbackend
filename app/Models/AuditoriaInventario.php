<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class AuditoriaInventario extends Model
{
    use LogsActivity;

    protected $fillable = [
        'sucursale_id',
        'user_id',
        'fecha',
        'observaciones',
        'status'];

    public function detalles() { return $this->hasMany(AuditoriaInventarioDetalle::class); }

    public function sucursal() { return $this->belongsTo(Sucursal::class, "sucursale_id", "id"); }

    public function user() { return $this->belongsTo(User::class); }


    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->useLogName('auditoria_inventario');
    }
}
