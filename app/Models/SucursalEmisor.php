<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SucursalEmisor extends Model
{
    use LogsActivity;

    protected $fillable = [
        'sucursale_id',
        'rfc',
        'razon_social',
        'regimen_fiscal',
        'codigo_postal',
        'curp',
        'registro_patronal',
        'logo_path',
        'cer_path',
        'key_path',
        'password_csd'
    ];

    public function sucursale() { return $this->belongsTo(Sucursal::class, "sucursale_id", "id"); }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->useLogName('sucursales_emisor'); // Categoría del log
    }
}
