<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ModificadorOpcion extends Model
{
    use HasFactory;

    protected $table = 'modificador_opciones';

    protected $fillable = [
        'grupo_id',
        'nombre',
        'precio_adicional',
        'producto_receta_id',
        'ingrediente_id',
        'cantidad_descuento',
        'estado'
    ];

    public function grupo()
    {
        return $this->belongsTo(ModificadorGrupo::class, 'grupo_id');
    }

    public function productoReceta()
    {
        return $this->belongsTo(Producto::class, 'producto_receta_id');
    }

    public function ingrediente()
    {
        return $this->belongsTo(Producto::class, 'ingrediente_id');
    }
}
