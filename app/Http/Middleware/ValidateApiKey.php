<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateApiKey
{
    /**
     * Valida que la solicitud entrante contenga una API Key válida.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expectedKey = (string) config('api.key');

        if (trim($expectedKey) === '') {
            return response()->json([
                'success' => false,
                'message' => 'Error de configuración: API_KEY no está configurada en el servidor (.env).',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $providedKey = $this->extractKeyFromRequest($request);

        if (!$providedKey || !hash_equals($expectedKey, $providedKey)) {
            return response()->json([
                'success' => false,
                'message' => 'Acceso no autorizado: la API Key es inválida o no fue proporcionada.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }

    /**
     * Extrae la API Key desde encabezados, bearer token o query string.
     */
    public function extractKeyFromRequest(Request $request): ?string
    {
        // 1. Cabecera estándar X-API-KEY / x-api-key
        $headerKey = $request->header('X-API-KEY') ?? $request->header('x-api-key');
        if (!empty($headerKey)) {
            return (string) $headerKey;
        }

        // 2. Parámetro en URL (?api_key=...)
        $queryKey = $request->query('api_key');
        if (!empty($queryKey) && is_string($queryKey)) {
            return $queryKey;
        }

        // 3. Cabecera Authorization: Bearer <key>
        $bearerToken = $request->bearerToken();
        if (!empty($bearerToken)) {
            return $bearerToken;
        }

        return null;
    }
}
