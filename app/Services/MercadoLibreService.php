<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class MercadoLibreService
{
    protected string $apiBase = 'https://api.mercadolibre.com';

    /**
     * Extrae el ID del artículo (ej: MLM123456789) a partir de una URL de Mercado Libre.
     */
    public function extractItemId(string $url): ?string
    {
        // Caso 1: https://articulo.mercadolibre.com.mx/MLM-1234567890-titulo...
        if (preg_match('/(MLM)[-_]?(\d+)/i', $url, $matches)) {
            return strtoupper($matches[1]) . $matches[2];
        }

        // Caso 2: https://www.mercadolibre.com.mx/p/MLM12345678...
        if (preg_match('/\/p\/(MLM\d+)/i', $url, $matches)) {
            return strtoupper($matches[1]);
        }

        return null;
    }

    /**
     * Consulta la API de Mercado Libre para obtener los detalles de un producto.
     */
    public function fetchByUrl(string $url): ?array
    {
        $itemId = $this->extractItemId($url);

        if (!$itemId) {
            return null;
        }

        $token = env('MERCADOLIBRE_ACCESS_TOKEN');

        $headers = [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Accept' => 'application/json',
        ];

        if ($token) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        try {
            // 1. Intentar endpoint de items
            $response = Http::withHeaders($headers)
                ->timeout(10)
                ->get("{$this->apiBase}/items/{$itemId}");

            // Si es un producto de catálogo (/p/MLM...)
            if ($response->status() === 404 && str_starts_with($itemId, 'MLM')) {
                $response = Http::withHeaders($headers)
                    ->timeout(10)
                    ->get("{$this->apiBase}/products/{$itemId}");
            }

            if ($response->successful()) {
                $item = $response->json();
                return $this->formatApiResponse($item, $url);
            }
        } catch (\Exception $e) {
            // Continuar con fallback si falla la red
        }

        return null;
    }

    /**
     * Formatea la respuesta JSON de la API de Mercado Libre al formato interno de la app.
     */
    private function formatApiResponse(array $item, string $originalUrl): array
    {
        $brand = null;
        $model = null;
        $sku = $item['id'] ?? null;
        $gtin = null;

        if (!empty($item['attributes']) && is_array($item['attributes'])) {
            foreach ($item['attributes'] as $attr) {
                $attrId = strtoupper($attr['id'] ?? '');
                if ($attrId === 'BRAND' && empty($brand)) {
                    $brand = $attr['value_name'] ?? null;
                } elseif ($attrId === 'MODEL' && empty($model)) {
                    $model = $attr['value_name'] ?? null;
                } elseif (in_array($attrId, ['GTIN', 'EAN', 'UPC']) && empty($gtin)) {
                    $gtin = $attr['value_name'] ?? null;
                } elseif ($attrId === 'SELLER_SKU' && empty($sku)) {
                    $sku = $attr['value_name'] ?? $sku;
                }
            }
        }

        $images = [];
        if (!empty($item['pictures']) && is_array($item['pictures'])) {
            foreach ($item['pictures'] as $pic) {
                if (!empty($pic['secure_url'])) {
                    $images[] = $pic['secure_url'];
                } elseif (!empty($pic['url'])) {
                    $images[] = $pic['url'];
                }
            }
        }

        $image = !empty($images) ? $images[0] : null;
        if (!$image && !empty($item['thumbnail'])) {
            // Obtener versión de mayor resolución reemplazando -I por -O
            $image = str_replace('-I.jpg', '-O.jpg', $item['thumbnail']);
            $images[] = $image;
        }

        return [
            'name' => $item['title'] ?? ($item['name'] ?? 'Producto Mercado Libre'),
            'brand' => $brand,
            'model' => $model,
            'sku' => $sku,
            'gtin' => $gtin,
            'price' => (float) ($item['price'] ?? 0),
            'old_price' => isset($item['original_price']) && $item['original_price'] > 0 ? (float) $item['original_price'] : null,
            'currency' => $item['currency_id'] ?? 'MXN',
            'image_url' => $image,
            'images' => array_values(array_unique($images)),
            'url' => $item['permalink'] ?? $originalUrl,
            'availability' => ($item['status'] ?? 'active') === 'active',
            'store_name' => 'Mercado Libre México',
            'store_slug' => 'mercado-libre-mexico',
        ];
    }
}
