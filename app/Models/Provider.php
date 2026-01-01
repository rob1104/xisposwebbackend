<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Provider extends Model
{
    use LogsActivity;

    protected $table = 'providers';
    protected $fillable = [
        'numero_global', 'nombre_comercial', 'razon_social', 'rfc', 'email',
        'telefono', 'telefono2', 'contacto', 'calle', 'no_exterior',
        'no_interior', 'colonia', 'codigo_postal', 'ciudad', 'estado',
        'pais', 'limite_credito', 'dias_credito', 'vender_vencido',
        'saldo_actual', 'tipo_pago', 'ultimo_pago', 'obs',
        'usuario_creador', 'tax_regime_id'
    ];

    protected static function boot()
    {
        parent::boot();

        // Antes de crear, generamos el número aleatorio
        static::creating(function ($customer) {
            $customer->numero_global = self::generateUniqueNumber();

            if (auth()->check()) {
                $customer->usuario_creador = auth()->user()->name;
            } else {
                $customer->usuario_creador = 'Sistema/Seeder';
            }

        });
    }

    private static function generateUniqueNumber()
    {
        do {
            // Genera un número aleatorio entre 10,000,000 y 99,999,999
            $number = 'PVD-' . random_int(10000000, 99999999);

            // Verificamos en la columna 'numero_global' si ya existe
        } while (self::where('numero_global', $number)->exists());

        return $number;
    }
    public function taxRegime()
    {
        return $this->belongsTo(TaxRegime::class);
    }
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->useLogName('proveedores'); // Categoría del log
    }

    // Casteamos los campos para Quasar
    protected $casts = [
        'limite_credito' => 'float',
        'saldo_actual'   => 'float',
        'vender_vencido' => 'integer',
        'dias_credito'   => 'integer',
        'ultimo_pago'    => 'date:Y-m-d',
    ];
}
