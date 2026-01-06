<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Ticket extends Model
{
    use LogsActivity;

    protected $fillable = ['header_lines', 'footer_lines',
        'sucursale_id'
    ];

    protected $casts = [
        'header_lines' => 'array',
        'footer_lines' => 'array',
    ];

    public function sucursal()
    {
        return $this->belongsTo(Sucursal::class, 'sucursale_id', 'id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->useLogName('tickets');
    }
}
