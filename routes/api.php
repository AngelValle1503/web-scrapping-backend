<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\TrackedElementController;
use App\Http\Controllers\Api\WalmartScraperController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->middleware('throttle:auth')->group(function (): void {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

// Rutas exclusivas para usuarios autenticados con Sanctum (sesión de usuario)
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/auth/user', [AuthController::class, 'user']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
});

// Ruta rápida para probar y verificar que tu API Key funciona
Route::middleware('api.key')->get('/api-key/verify', function () {
    return response()->json([
        'success' => true,
        'message' => '¡API Key válida y funcionando correctamente!',
        'timestamp' => now()->toIso8601String(),
    ]);
});

// Rutas accesibles tanto con API Key (X-API-KEY) como con sesión de usuario (Sanctum)
Route::middleware('auth.any')->group(function (): void {
    // Endpoints de recepción de datos desde scrapers (Maxun, scripts, webhooks)
    Route::post('/scraper/walmart/products', [WalmartScraperController::class, 'store']);
    Route::post('/scraper/products', [WalmartScraperController::class, 'store']);

    // Endpoints para productos
    Route::get('/products', [ProductController::class, 'index']);
    Route::post('/products/extract', [ProductController::class, 'extractAndStore']);
    Route::delete('/products/{id}', [ProductController::class, 'destroy']);
    Route::post('/offers/{id}/refresh', [ProductController::class, 'refreshOffer']);

    // Endpoints del Inspector Visual HTML y Monitoreo de Elementos
    Route::post('/inspector/preview', [TrackedElementController::class, 'preview']);
    Route::post('/inspector/test-selector', [TrackedElementController::class, 'testSelector']);

    Route::get('/tracked-elements', [TrackedElementController::class, 'index']);
    Route::post('/tracked-elements', [TrackedElementController::class, 'store']);
    Route::post('/tracked-elements/check-all', [TrackedElementController::class, 'checkAll']);
    Route::post('/tracked-elements/{id}/check', [TrackedElementController::class, 'check']);
    Route::get('/tracked-elements/{id}/logs', [TrackedElementController::class, 'logs']);
    Route::patch('/tracked-elements/{id}/toggle-status', [TrackedElementController::class, 'toggleStatus']);
    Route::delete('/tracked-elements/{id}', [TrackedElementController::class, 'destroy']);
});
