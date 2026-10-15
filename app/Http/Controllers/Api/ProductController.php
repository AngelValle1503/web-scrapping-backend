<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Offer;
use App\Models\PriceHistory;
use App\Models\Product;
use App\Models\Store;
use App\Services\ProductExtractorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    /**
     * Retorna la lista completa de productos con sus ofertas, tiendas e historial de precios.
     */
    public function index(): JsonResponse
    {
        $products = Product::with([
            'offers.store',
            'offers.priceHistories' => function ($query) {
                $query->orderBy('recorded_at', 'desc');
            },
        ])
        ->latest('updated_at')
        ->get();

        return response()->json([
            'success' => true,
            'data' => $products,
        ]);
    }

    /**
     * Extrae automáticamente información de una URL pegada por el usuario y la guarda en la base de datos.
     */
    public function extractAndStore(Request $request, ProductExtractorService $extractor): JsonResponse
    {
        $validated = $request->validate([
            'url' => 'required|url',
        ]);

        $url = $validated['url'];
        $extracted = $extractor->extractFromUrl($url);

        // Si el scraper no pudo extraer el precio ni el nombre (ej. bloqueo de Cloudflare o URL inválida)
        if (empty($extracted['name']) || empty($extracted['price'])) {
            return response()->json([
                'success' => false,
                'message' => 'No se pudieron extraer automáticamente todos los datos requeridos (nombre o precio). Es posible que la página tenga protección anti-bot o requiera selección manual.',
                'extracted_preview' => $extracted,
            ], 422);
        }

        // Guardar o actualizar la tienda
        $store = Store::firstOrCreate(
            ['slug' => $extracted['store_slug']],
            [
                'name' => $extracted['store_name'] ?: ucfirst($extracted['store_slug']),
                'domain' => parse_url($url, PHP_URL_HOST) ?? '',
            ]
        );

        // Guardar o actualizar producto y oferta
        $result = DB::transaction(function () use ($extracted, $store, $url) {
            $product = null;

            if (!empty($extracted['sku'])) {
                $product = Product::where('sku', $extracted['sku'])->first();
            }

            if (!$product && !empty($extracted['gtin'])) {
                $product = Product::where('gtin', $extracted['gtin'])->first();
            }

            if (!$product) {
                $product = Product::where('name', $extracted['name'])->first();
            }

            if (!$product) {
                $product = Product::create([
                    'name' => $extracted['name'],
                    'brand' => $extracted['brand'] ?? null,
                    'model' => $extracted['model'] ?? null,
                    'sku' => $extracted['sku'] ?? null,
                    'gtin' => $extracted['gtin'] ?? null,
                    'image_url' => $extracted['image_url'] ?? null,
                    'images' => !empty($extracted['images']) ? $extracted['images'] : (!empty($extracted['image_url']) ? [$extracted['image_url']] : null),
                ]);
            } else {
                $product->update(array_filter([
                    'brand' => $product->brand ?: ($extracted['brand'] ?? null),
                    'model' => $product->model ?: ($extracted['model'] ?? null),
                    'sku' => $product->sku ?: ($extracted['sku'] ?? null),
                    'gtin' => $product->gtin ?: ($extracted['gtin'] ?? null),
                    'image_url' => $extracted['image_url'] ?? $product->image_url,
                    'images' => !empty($extracted['images']) ? $extracted['images'] : $product->images,
                ]));
            }

            $scrapedAt = now();

            $offer = Offer::updateOrCreate(
                [
                    'product_id' => $product->id,
                    'store_id' => $store->id,
                ],
                [
                    'price' => $extracted['price'],
                    'old_price' => $extracted['old_price'] ?? null,
                    'currency' => strtoupper($extracted['currency'] ?? 'MXN'),
                    'url' => $url,
                    'availability' => $extracted['availability'] ?? true,
                    'scraped_at' => $scrapedAt,
                ]
            );

            // Registrar historial si no existe ya un precio idéntico en las últimas 2 horas
            $lastHistory = PriceHistory::where('offer_id', $offer->id)
                ->latest('recorded_at')
                ->first();

            if (!$lastHistory || (float) $lastHistory->price !== (float) $extracted['price']) {
                PriceHistory::create([
                    'offer_id' => $offer->id,
                    'price' => $extracted['price'],
                    'recorded_at' => $scrapedAt,
                ]);
            }

            return $product->load(['offers.store', 'offers.priceHistories']);
        });

        return response()->json([
            'success' => true,
            'message' => '¡Producto extraído y guardado exitosamente!',
            'data' => $result,
        ], 201);
    }

    /**
     * Elimina un producto y sus ofertas asociadas.
     */
    public function destroy(int $id): JsonResponse
    {
        $product = Product::find($id);

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Producto no encontrado.',
            ], 404);
        }

        $product->delete();

        return response()->json([
            'success' => true,
            'message' => 'Producto eliminado correctamente.',
        ]);
    }

    /**
     * Actualiza / Re-raspa el precio de una oferta existente.
     */
    public function refreshOffer(int $offerId, ProductExtractorService $extractor): JsonResponse
    {
        $offer = Offer::with(['product', 'store'])->find($offerId);

        if (!$offer) {
            return response()->json([
                'success' => false,
                'message' => 'Oferta no encontrada.',
            ], 404);
        }

        $extracted = $extractor->extractFromUrl($offer->url);

        if (empty($extracted['price'])) {
            return response()->json([
                'success' => false,
                'message' => 'No se pudo obtener el nuevo precio en este momento.',
            ], 422);
        }

        $now = now();
        $offer->update([
            'price' => $extracted['price'],
            'old_price' => $extracted['old_price'] ?? $offer->old_price,
            'availability' => $extracted['availability'] ?? $offer->availability,
            'scraped_at' => $now,
        ]);

        if (!empty($extracted['images']) && $offer->product) {
            $offer->product->update([
                'images' => $extracted['images'],
                'image_url' => $extracted['image_url'] ?? $offer->product->image_url,
            ]);
        }

        PriceHistory::create([
            'offer_id' => $offer->id,
            'price' => $extracted['price'],
            'recorded_at' => $now,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Precio actualizado correctamente.',
            'data' => $offer->fresh(['product', 'store', 'priceHistories']),
        ]);
    }
}
