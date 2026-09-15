<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ModificadorGrupo extends Model
{
    use HasFactory;
    
    protected $table = 'modificador_grupos';

    protected $fillable = [
        'nombre',
        'tipo',
        'min_selecciones',
        'max_selecciones',
        'estado'
    ];

    public function opciones()
    {
        return $this->hasMany(ModificadorOpcion::class, 'grupo_id');
    }

    public function productos()
    {
        return $this->belongsToMany(Producto::class, 'producto_modificadores', 'grupo_id', 'producto_id');
    }
}
