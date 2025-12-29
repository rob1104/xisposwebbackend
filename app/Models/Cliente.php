<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Cliente extends Model
{
    protected $fillable = [
        'numero_global', 'nombre_comercial', 'razon_social', 'rfc', 'email',
        'telefono', 'telefono2', 'contacto', 'calle', 'no_exterior', 'no_interior',
        'colonia', 'codigo_postal', 'ciudad', 'estado', 'pais', 'limite_credito',
        'saldo_actual', 'ultimo_pago', 'obs', 'usuario_creador'
    ];

    protected static function boot()
    {
        parent::boot();

        // Antes de crear, generamos el número aleatorio
        static::creating(function ($customer) {
            $customer->numero_global = self::generateUniqueNumber();
        });
    }

    private static function generateUniqueNumber()
    {
        do {
            // Genera algo como CTE-482931
            $number = 'CTE-' . strtoupper(Str::random(6));
        } while (self::where('numero_global', $number)->exists());

        return $number;
    }

    public function taxRegime()
    {
        return $this->belongsTo(TaxRegime::class);
    }
}
