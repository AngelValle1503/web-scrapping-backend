<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Offer;
use App\Models\PriceHistory;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class WalmartScraperController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $payload = $request->all();

        if (empty($payload)) {
            return response()->json([
                'success' => false,
                'message' => 'El cuerpo de la solicitud no contiene datos.',
            ], 400);
        }

        // Permitir array de objetos o un solo objeto JSON
        $isBatch = isset($payload[0]) && is_array($payload[0]);
        $rawItems = $isBatch ? $payload : [$payload];

        $items = array_map([$this, 'sanitizePayloadItem'], $rawItems);

        $rules = [
            'name' => 'required|string|max:255',
            'brand' => 'nullable|string|max:100',
            'model' => 'nullable|string|max:100',
            'sku' => 'nullable|string|max:100',
            'gtin' => 'nullable|string|max:100',
            'price' => 'required|numeric|min:0',
            'old_price' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|size:3',
            'image_url' => 'nullable|url',
            'url' => 'required|url',
            'availability' => 'nullable|boolean',
            'store_name' => 'nullable|string|max:100',
            'store_slug' => 'nullable|string|max:100',
        ];

        $validatedItems = [];
        $errors = [];

        foreach ($items as $index => $item) {
            $validator = Validator::make($item, $rules);

            if ($validator->fails()) {
                $errors[$index] = $validator->errors();
            } else {
                $validatedItems[] = $validator->validated();
            }
        }

        if (!empty($errors) && empty($validatedItems)) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación en los datos recibidos.',
                'errors' => $errors,
            ], 422);
        }

        $processed = [];

        DB::transaction(function () use ($validatedItems, &$processed) {
            foreach ($validatedItems as $data) {
                // Determinar la tienda según datos enviados o dominio de la URL
                $storeSlug = $data['store_slug'] ?? null;
                $storeName = $data['store_name'] ?? null;

                if (!$storeSlug) {
                    $host = parse_url($data['url'], PHP_URL_HOST) ?? '';
                    if (str_contains($host, 'cyberpuerta')) {
                        $storeSlug = 'cyberpuerta';
                        $storeName = $storeName ?: 'Cyberpuerta';
                    } elseif (str_contains($host, 'mercadolibre')) {
                        $storeSlug = 'mercado-libre-mexico';
                        $storeName = $storeName ?: 'Mercado Libre México';
                    } elseif (str_contains($host, 'doto')) {
                        $storeSlug = 'doto';
                        $storeName = $storeName ?: 'Doto';
                    } elseif (str_contains($host, 'ddtech')) {
                        $storeSlug = 'ddtech';
                        $storeName = $storeName ?: 'DDTech';
                    } else {
                        $storeSlug = 'walmart-mexico';
                        $storeName = $storeName ?: 'Walmart México';
                    }
                }

                $store = Store::firstOrCreate(
                    ['slug' => $storeSlug],
                    [
                        'name' => $storeName ?: ucfirst($storeSlug),
                        'domain' => parse_url($data['url'], PHP_URL_HOST) ?? '',
                    ]
                );

                // Buscar producto por SKU, GTIN o Nombre + Marca
                $product = null;

                if (!empty($data['sku'])) {
                    $product = Product::where('sku', $data['sku'])->first();
                }

                if (!$product && !empty($data['gtin'])) {
                    $product = Product::where('gtin', $data['gtin'])->first();
                }

                if (!$product) {
                    $product = Product::where('name', $data['name'])
                        ->where('brand', $data['brand'] ?? null)
                        ->first();
                }

                if (!$product) {
                    $product = Product::create([
                        'name' => $data['name'],
                        'brand' => $data['brand'] ?? null,
                        'model' => $data['model'] ?? null,
                        'sku' => $data['sku'] ?? null,
                        'gtin' => $data['gtin'] ?? null,
                        'image_url' => $data['image_url'] ?? null,
                        'images' => !empty($data['images']) ? $data['images'] : (!empty($data['image_url']) ? [$data['image_url']] : null),
                    ]);
                } else {
                    // Actualizar información faltante del producto si llega de nueva cuenta
                    $product->update(array_filter([
                        'brand' => $product->brand ?: ($data['brand'] ?? null),
                        'model' => $product->model ?: ($data['model'] ?? null),
                        'sku' => $product->sku ?: ($data['sku'] ?? null),
                        'gtin' => $product->gtin ?: ($data['gtin'] ?? null),
                        'image_url' => $data['image_url'] ?? $product->image_url,
                        'images' => !empty($data['images']) ? $data['images'] : $product->images,
                    ]));
                }

                $scrapedAt = now();

                // Crear o actualizar la oferta de esta tienda
                $offer = Offer::updateOrCreate(
                    [
                        'product_id' => $product->id,
                        'store_id' => $store->id,
                    ],
                    [
                        'price' => $data['price'],
                        'old_price' => $data['old_price'] ?? null,
                        'currency' => strtoupper($data['currency'] ?? 'MXN'),
                        'url' => $data['url'],
                        'availability' => $data['availability'] ?? true,
                        'scraped_at' => $scrapedAt,
                    ]
                );

                // Registrar en el historial de precios
                $history = PriceHistory::create([
                    'offer_id' => $offer->id,
                    'price' => $data['price'],
                    'recorded_at' => $scrapedAt,
                ]);

                $processed[] = [
                    'product_id' => $product->id,
                    'store_id' => $store->id,
                    'offer_id' => $offer->id,
                    'history_id' => $history->id,
                    'name' => $product->name,
                    'brand' => $product->brand,
                    'price' => (float) $offer->price,
                    'old_price' => $offer->old_price ? (float) $offer->old_price : null,
                    'currency' => $offer->currency,
                    'store' => $store->name,
                    'scraped_at' => $scrapedAt->toDateTimeString(),
                ];
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Producto(s) procesado(s) exitosamente.',
            'count' => count($processed),
            'data' => ($isBatch || count($processed) > 1) ? $processed : $processed[0],
            'validation_errors' => !empty($errors) ? $errors : null,
        ], 200);
    }

    /**
     * Sanitiza y normaliza datos provenientes del scraper (ej: convierte "$24,999.00" a float).
     */
    private function sanitizePayloadItem(array $item): array
    {
        if (isset($item['price']) && is_string($item['price'])) {
            $cleaned = preg_replace('/[^\d.]/', '', str_replace(',', '', $item['price']));
            if (is_numeric($cleaned)) {
                $item['price'] = (float) $cleaned;
            }
        }

        if (isset($item['old_price']) && is_string($item['old_price'])) {
            $cleaned = preg_replace('/[^\d.]/', '', str_replace(',', '', $item['old_price']));
            if (is_numeric($cleaned)) {
                $item['old_price'] = (float) $cleaned;
            }
        }

        if (isset($item['availability']) && is_string($item['availability'])) {
            $lower = strtolower(trim($item['availability']));
            if (in_array($lower, ['agotado', 'out of stock', 'no disponible', 'false', '0'])) {
                $item['availability'] = false;
            } else {
                $item['availability'] = true;
            }
        }

        return $item;
    }
}
