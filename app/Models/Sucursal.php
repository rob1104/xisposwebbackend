<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sucursal extends Model
{
    protected $table = 'sucursales';
    public function users()
    {
        return $this->belongsToMany(User::class);
    }
}
