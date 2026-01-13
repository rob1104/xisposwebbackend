<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrecioModificacion extends Model
{
    //
    protected $fillable = [
        'venta_id',
        'producto_id',
        'user_id',
        'autorizado_por',
        'precio_original',
        'precio_nuevo',
        'motivo',
    ];
}
