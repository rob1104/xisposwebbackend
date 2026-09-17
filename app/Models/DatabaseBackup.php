<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DatabaseBackup extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'filename',
        'original_name',
        'disk',
        'path',
        'size',
        'status',
        'database_name',
        'driver',
        'database_version',
        'started_at',
        'finished_at',
        'error_message',
    ];

    protected $casts = [
        'size' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
