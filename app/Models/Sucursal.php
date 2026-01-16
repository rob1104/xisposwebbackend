<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Sucursal extends Model
{
    use LogsActivity;
    protected $table = 'sucursales';

    protected $fillable = [
        'nombre', 'direccion', 'telefono',
        'sucursale_id',
        'codigo_postal',
        'prefijo'
    ];
    public function users()
    {
        return $this->belongsToMany(User::class);
    }

    public function ticket()
    {
        return $this->hasOne(Ticket::class, "sucursale_id", "id");
    }

    public function emisor() { return $this->hasOne(SucursalEmisor::class, "sucursale_id", "id"); }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->useLogName('sucursales');
    }
}
