<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateOrApiKey
{
    /**
     * Permite el acceso si la petición cuenta con una API Key válida
     * O si el usuario está autenticado mediante sesión / token de Sanctum.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expectedKey = (string) config('api.key');
        $validator = new ValidateApiKey();
        $providedKey = $validator->extractKeyFromRequest($request);

        // 1. Verificación por API Key
        if (trim($expectedKey) !== '' && !empty($providedKey) && hash_equals($expectedKey, (string) $providedKey)) {
            return $next($request);
        }

        // 2. Verificación por Sanctum (usuario logueado en la aplicación web)
        if (Auth::guard('sanctum')->check()) {
            Auth::shouldUse('sanctum');
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => 'Acceso no autorizado: debes iniciar sesión o proporcionar una API Key válida en el encabezado X-API-KEY.',
        ], Response::HTTP_UNAUTHORIZED);
    }
}
