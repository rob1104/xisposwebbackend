<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetSucursalContext
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $sucursalId = $request->header("X-Sucursal-Id");
        if($sucursalId) {
            config(['app.current_sucursal_id' => $sucursalId]);
        }
        return $next($request);
    }
}
