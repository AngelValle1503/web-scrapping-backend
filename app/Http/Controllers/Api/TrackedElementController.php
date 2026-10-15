<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TrackedElement;
use App\Models\TrackedElementLog;
use App\Services\HtmlInspectorService;
use App\Services\ProductExtractorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrackedElementController extends Controller
{
    protected HtmlInspectorService $inspectorService;
    protected ProductExtractorService $extractorService;

    public function __construct(
        HtmlInspectorService $inspectorService,
        ProductExtractorService $extractorService
    ) {
        $this->inspectorService = $inspectorService;
        $this->extractorService = $extractorService;
    }

    /**
     * Lista todos los elementos monitoreados con su historial reciente.
     */
    public function index(): JsonResponse
    {
        $elements = TrackedElement::with(['logs' => function ($query) {
            $query->orderByDesc('checked_at')->take(20);
        }])
        ->orderByDesc('created_at')
        ->get();

        return response()->json([
            'success' => true,
            'data' => $elements,
        ]);
    }

    /**
     * Previsualiza el HTML de una URL para el inspector visual.
     */
    public function preview(Request $request): JsonResponse
    {
        $request->validate([
            'url' => 'required|string',
        ]);

        try {
            $result = $this->inspectorService->preparePreview($request->input('url'));
            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener la vista previa: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Prueba un selector sobre un HTML provisto.
     */
    public function testSelector(Request $request): JsonResponse
    {
        $request->validate([
            'selector' => 'required|string',
            'html' => 'nullable|string',
            'url' => 'nullable|string',
            'attribute' => 'nullable|string',
        ]);

        $attribute = $request->input('attribute', 'text');
        $selector = $request->input('selector');
        $html = $request->input('html');
        $url = $request->input('url');

        if (!empty($html)) {
            $value = $this->inspectorService->extractValueFromHtml($html, $selector, $attribute);
        } elseif (!empty($url)) {
            $value = $this->inspectorService->extractValueFromUrl($url, $selector, $attribute);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Se requiere proporcionar HTML o URL.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'selector' => $selector,
            'value' => $value,
        ]);
    }

    /**
     * Registra un nuevo elemento para monitoreo continuo.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'url' => 'required|string',
            'selector' => 'required|string|max:500',
            'attribute' => 'nullable|string|max:50',
            'initial_value' => 'nullable|string',
            'check_interval_minutes' => 'nullable|integer|min:1',
        ]);

        $url = trim($validated['url']);
        if (!preg_match('/^https?:\/\//i', $url)) {
            $url = 'https://' . $url;
        }

        $attribute = $validated['attribute'] ?? 'text';
        $currentValue = $validated['initial_value'] ?? null;

        // Si no se proporcionó valor inicial, extraerlo ahora mismo
        if ($currentValue === null) {
            $currentValue = $this->inspectorService->extractValueFromUrl($url, $validated['selector'], $attribute);
        }

        $element = TrackedElement::create([
            'name' => $validated['name'],
            'url' => $url,
            'selector' => $validated['selector'],
            'attribute' => $attribute,
            'current_value' => $currentValue,
            'status' => 'active',
            'check_interval_minutes' => $validated['check_interval_minutes'] ?? 5,
            'last_checked_at' => now(),
        ]);

        // Registrar registro inicial en el historial
        TrackedElementLog::create([
            'tracked_element_id' => $element->id,
            'old_value' => null,
            'new_value' => $currentValue,
            'has_changed' => false,
            'status' => 'success',
            'checked_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => '¡Elemento agregado al monitoreo exitosamente!',
            'data' => $element->fresh('logs'),
        ], 201);
    }

    /**
     * Consulta inmediatamente un elemento específico y registra cualquier cambio.
     */
    public function check(int $id): JsonResponse
    {
        $element = TrackedElement::find($id);

        if (!$element) {
            return response()->json([
                'success' => false,
                'message' => 'Elemento no encontrado.',
            ], 404);
        }

        $newValue = $this->inspectorService->extractValueFromUrl(
            $element->url,
            $element->selector,
            $element->attribute ?? 'text'
        );

        $now = now();
        $oldValue = $element->current_value;
        $hasChanged = ($newValue !== null && $oldValue !== null && $oldValue !== $newValue);

        $element->update([
            'current_value' => $newValue ?? $oldValue,
            'last_checked_at' => $now,
            'status' => $newValue !== null ? 'active' : 'error',
            'error_message' => $newValue === null ? 'No se pudo encontrar el elemento con el selector actual.' : null,
        ]);

        TrackedElementLog::create([
            'tracked_element_id' => $element->id,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'has_changed' => $hasChanged,
            'status' => $newValue !== null ? 'success' : 'error',
            'error_message' => $newValue === null ? 'Elemento no encontrado en la página.' : null,
            'checked_at' => $now,
        ]);

        return response()->json([
            'success' => true,
            'message' => $hasChanged ? '¡Cambio detectado y registrado!' : 'Información verificada (sin cambios).',
            'data' => $element->fresh(['logs' => fn($q) => $q->orderByDesc('checked_at')->take(20)]),
            'has_changed' => $hasChanged,
        ]);
    }

    /**
     * Consulta todos los elementos activos en lote (usado cada 5 minutos).
     */
    public function checkAll(): JsonResponse
    {
        $elements = TrackedElement::where('status', 'active')->get();
        $checkedCount = 0;
        $changedCount = 0;

        // Cache de HTML por URL para no descargar la misma página web múltiples veces en la misma ronda
        $htmlCache = [];

        foreach ($elements as $element) {
            $url = $element->url;

            if (!isset($htmlCache[$url])) {
                $htmlCache[$url] = $this->extractorService->fetchHtml($url);
            }

            $html = $htmlCache[$url];
            $newValue = $this->inspectorService->extractValueFromHtml(
                $html,
                $element->selector,
                $element->attribute ?? 'text'
            );

            $now = now();
            $oldValue = $element->current_value;
            $hasChanged = ($newValue !== null && $oldValue !== null && $oldValue !== $newValue);

            $element->update([
                'current_value' => $newValue ?? $oldValue,
                'last_checked_at' => $now,
                'status' => $newValue !== null ? 'active' : 'error',
                'error_message' => $newValue === null ? 'No se encontró el elemento con el selector.' : null,
            ]);

            TrackedElementLog::create([
                'tracked_element_id' => $element->id,
                'old_value' => $oldValue,
                'new_value' => $newValue,
                'has_changed' => $hasChanged,
                'status' => $newValue !== null ? 'success' : 'error',
                'error_message' => $newValue === null ? 'Elemento no encontrado en la página.' : null,
                'checked_at' => $now,
            ]);

            $checkedCount++;
            if ($hasChanged) {
                $changedCount++;
            }
        }

        return response()->json([
            'success' => true,
            'message' => "Consulta masiva completada: {$checkedCount} verificados, {$changedCount} cambios registrados.",
            'checked_count' => $checkedCount,
            'changed_count' => $changedCount,
        ]);
    }

    /**
     * Obtiene el historial completo de cambios de un elemento.
     */
    public function logs(int $id): JsonResponse
    {
        $element = TrackedElement::find($id);

        if (!$element) {
            return response()->json([
                'success' => false,
                'message' => 'Elemento no encontrado.',
            ], 404);
        }

        $logs = TrackedElementLog::where('tracked_element_id', $id)
            ->orderByDesc('checked_at')
            ->paginate(50);

        return response()->json([
            'success' => true,
            'element' => $element,
            'data' => $logs,
        ]);
    }

    /**
     * Pausa o reactiva el monitoreo de un elemento.
     */
    public function toggleStatus(int $id): JsonResponse
    {
        $element = TrackedElement::find($id);

        if (!$element) {
            return response()->json([
                'success' => false,
                'message' => 'Elemento no encontrado.',
            ], 404);
        }

        $newStatus = $element->status === 'active' ? 'paused' : 'active';
        $element->update(['status' => $newStatus]);

        return response()->json([
            'success' => true,
            'message' => $newStatus === 'active' ? 'Monitoreo reactivado.' : 'Monitoreo pausado.',
            'status' => $newStatus,
        ]);
    }

    /**
     * Elimina un elemento monitoreado y su historial.
     */
    public function destroy(int $id): JsonResponse
    {
        $element = TrackedElement::find($id);

        if (!$element) {
            return response()->json([
                'success' => false,
                'message' => 'Elemento no encontrado.',
            ], 404);
        }

        $element->delete();

        return response()->json([
            'success' => true,
            'message' => 'Elemento eliminado correctamente.',
        ]);
    }
}
