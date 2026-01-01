<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

class AuditController extends Controller
{
    public function index()
    {
        return Activity::with('causer')
            ->orderBy('created_at', 'desc')
            ->limit(500)
            ->get()
            ->map(function($activity) {
                return [
                    'id' => $activity->id,
                    'fecha' => $activity->created_at->format('d/m/Y H:i:s'),
                    'modulo' => strtoupper($activity->log_name),
                    'descripcion' => $activity->description,
                    'usuario' => $activity->causer ? $activity->causer->name : 'Sistema/Automático',
                    'propiedades' => $activity->properties,
                    'ip' => $activity->getExtraProperty('ip') ?? 'N/A'
                ];
            });
    }
}
