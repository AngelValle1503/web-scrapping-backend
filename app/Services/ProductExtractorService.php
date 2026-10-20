<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ProductExtractorService
{
    protected MercadoLibreService $mercadoLibreService;

    public function __construct(MercadoLibreService $mercadoLibreService)
    {
        $this->mercadoLibreService = $mercadoLibreService;
    }

    /**
     * Extrae información de producto a partir de una URL dada.
     */
    public function extractFromUrl(string $url): array
    {
        $url = trim($url);
        // Normalizar dominio de DDTech si el usuario ingresó ddtech.com.mx
        $url = preg_replace('/^(https?:\/\/)?(www\.)?ddtech\.com\.mx/i', '$1$2ddtech.mx', $url);
        if (! preg_match('/^https?:\/\//i', $url)) {
            $url = 'https://'.$url;
        }

        // 0. Si es de Mercado Libre, intentar primero mediante su servicio especializado de API
        if (str_contains(strtolower($url), 'mercadolibre')) {
            $meliData = $this->mercadoLibreService->fetchByUrl($url);
            if ($meliData && ! empty($meliData['price'])) {
                return $meliData;
            }
        }

        $domainInfo = $this->determineStore($url);
        $html = $this->fetchHtml($url);

        $data = [
            'name' => null,
            'brand' => null,
            'model' => null,
            'sku' => null,
            'gtin' => null,
            'price' => null,
            'old_price' => null,
            'currency' => 'MXN',
            'image_url' => null,
            'images' => [],
            'url' => $url,
            'availability' => true,
            'store_name' => $domainInfo['name'],
            'store_slug' => $domainInfo['slug'],
        ];

        if (empty($html)) {
            return $data;
        }

        // 1. Extraer mediante JSON-LD (Schema.org Product)
        $jsonLdData = $this->extractJsonLd($html);
        if ($jsonLdData) {
            $data = array_merge($data, array_filter($jsonLdData, fn ($v) => ! is_null($v)));
        }

        // 2. Extraer mediante OpenGraph y Meta tags
        $metaData = $this->extractMetaTags($html);
        foreach ($metaData as $key => $val) {
            if (empty($data[$key]) && ! empty($val)) {
                $data[$key] = $val;
            }
        }

        // 3. Fallbacks específicos por tienda o heurística
        $heuristicData = $this->extractHeuristics($html, $domainInfo['slug']);
        foreach ($heuristicData as $key => $val) {
            if ($key === 'images' && ! empty($val)) {
                // Si la heurística de tienda encontró una galería completa (más imágenes que las genéricas de JSON-LD o metatags), priorizar la galería
                if (count($val) > count($data['images'] ?? [])) {
                    $data['images'] = $val;
                    $data['image_url'] = $val[0];
                }
            } elseif (empty($data[$key]) && ! empty($val)) {
                $data[$key] = $val;
            }
        }

        // Normalizar precio si aún es string
        if (isset($data['price']) && is_string($data['price'])) {
            $cleaned = preg_replace('/[^\d.]/', '', str_replace(',', '', $data['price']));
            $data['price'] = is_numeric($cleaned) ? (float) $cleaned : null;
        }

        if (isset($data['old_price']) && is_string($data['old_price'])) {
            $cleaned = preg_replace('/[^\d.]/', '', str_replace(',', '', $data['old_price']));
            $data['old_price'] = is_numeric($cleaned) ? (float) $cleaned : null;
        }

        // Si el nombre tiene sufijos de tienda (ej. " | Cyberpuerta.mx"), limpiarlo
        if (! empty($data['name'])) {
            $data['name'] = html_entity_decode($data['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $data['name'] = trim(preg_replace('/\s*\|\s*(Cyberpuerta|Walmart|Mercado Libre|Amazon|Doto|DDTech|DD Tech).*$/i', '', $data['name']));
            $data['name'] = trim(preg_replace('/\s*-\s*(Cyberpuerta|Walmart|Mercado Libre|Amazon|Doto|DDTech|DD Tech).*$/i', '', $data['name']));
        }

        // Asegurar consistencia entre image_url e images
        if (empty($data['images']) && ! empty($data['image_url'])) {
            $data['images'] = [$data['image_url']];
        }
        if (! empty($data['images']) && empty($data['image_url'])) {
            $data['image_url'] = $data['images'][0];
        }

        return $data;
    }

    /**
     * Descarga el HTML de una URL de forma robusta con fallback a FlareSolverr.
     */
    public function fetchHtml(string $url): string
    {
        $domainInfo = $this->determineStore($url);
        $alwaysFlare = in_array($domainInfo['slug'], ['ddtech', 'cyberpuerta', 'coppel']);

        $html = '';

        if ($alwaysFlare) {
            // Tiendas con Cloudflare Turnstile o protección estricta: ir directo a FlareSolverr
            $html = $this->solveWithFlareSolverr($url) ?: '';
        }

        if (empty($html)) {
            try {
                $response = Http::withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
                    'Accept-Language' => 'es-MX,es;q=0.9,en-US;q=0.8,en;q=0.7',
                    'Sec-Ch-Ua' => '"Chromium";v="128", "Not;A=Brand";v="24", "Google Chrome";v="128"',
                    'Sec-Ch-Ua-Mobile' => '?0',
                    'Sec-Ch-Ua-Platform' => '"Windows"',
                    'Sec-Fetch-Dest' => 'document',
                    'Sec-Fetch-Mode' => 'navigate',
                    'Sec-Fetch-Site' => 'none',
                    'Sec-Fetch-User' => '?1',
                    'Upgrade-Insecure-Requests' => '1',
                ])->timeout(10)->get($url);

                $html = $response->body();

                // Si detecta bloqueo de Cloudflare o anti-bot, delegar automáticamente a FlareSolverr
                if ($this->isBlockedHtml($html, $response->status())) {
                    $html = $this->solveWithFlareSolverr($url) ?: $html;
                }
            } catch (\Exception $e) {
                $html = $this->solveWithFlareSolverr($url) ?: '';
            }
        }

        return $html;
    }

    /**
     * Determina el slug y nombre de la tienda a partir del host de la URL.
     */
    private function determineStore(string $url): array
    {
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');

        if (str_contains($host, 'cyberpuerta')) {
            return ['slug' => 'cyberpuerta', 'name' => 'Cyberpuerta'];
        }
        if (str_contains($host, 'mercadolibre')) {
            return ['slug' => 'mercado-libre-mexico', 'name' => 'Mercado Libre México'];
        }
        if (str_contains($host, 'walmart')) {
            return ['slug' => 'walmart-mexico', 'name' => 'Walmart México'];
        }
        if (str_contains($host, 'amazon')) {
            return ['slug' => 'amazon-mexico', 'name' => 'Amazon México'];
        }
        if (str_contains($host, 'doto')) {
            return ['slug' => 'doto', 'name' => 'Doto'];
        }
        if (str_contains($host, 'ddtech')) {
            return ['slug' => 'ddtech', 'name' => 'DDTech'];
        }
        if (str_contains($host, 'liverpool')) {
            return ['slug' => 'liverpool', 'name' => 'Liverpool'];
        }
        if (str_contains($host, 'coppel')) {
            return ['slug' => 'coppel', 'name' => 'Coppel'];
        }

        $cleanHost = preg_replace('/^www\./', '', $host);
        $namePart = explode('.', $cleanHost)[0] ?? 'Tienda';

        return [
            'slug' => Str::slug($namePart) ?: 'tienda-online',
            'name' => ucfirst($namePart),
        ];
    }

    /**
     * Extrae datos de bloques JSON-LD Schema.org.
     */
    private function extractJsonLd(string $html): array
    {
        $extracted = [];

        if (preg_match_all('/<script\b[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches)) {
            foreach ($matches[1] as $jsonStr) {
                $json = json_decode(trim($jsonStr), true);
                if (! $json) {
                    continue;
                }

                $product = $this->findProductInJsonLd($json);
                if ($product) {
                    if (! empty($product['name'])) {
                        $extracted['name'] = is_string($product['name']) ? $product['name'] : null;
                    }
                    if (! empty($product['image'])) {
                        $imgs = [];
                        if (is_array($product['image'])) {
                            foreach ($product['image'] as $im) {
                                $u = is_array($im) ? ($im['url'] ?? null) : $im;
                                if (is_string($u) && ! empty($u)) {
                                    $imgs[] = $u;
                                }
                            }
                        } elseif (is_string($product['image'])) {
                            $imgs[] = $product['image'];
                        }
                        if (! empty($imgs)) {
                            $extracted['image_url'] = $imgs[0];
                            $extracted['images'] = array_values(array_unique($imgs));
                        }
                    }
                    if (! empty($product['sku'])) {
                        $extracted['sku'] = (string) $product['sku'];
                    }
                    if (! empty($product['gtin13']) || ! empty($product['gtin12']) || ! empty($product['gtin'])) {
                        $extracted['gtin'] = (string) ($product['gtin13'] ?? $product['gtin12'] ?? $product['gtin'] ?? null);
                    }
                    if (! empty($product['brand'])) {
                        $extracted['brand'] = is_array($product['brand']) ? ($product['brand']['name'] ?? null) : (string) $product['brand'];
                    }
                    if (! empty($product['model'])) {
                        $extracted['model'] = is_array($product['model']) ? ($product['model']['name'] ?? null) : (string) $product['model'];
                    }

                    // Offers
                    if (! empty($product['offers'])) {
                        $offers = is_array($product['offers']) && isset($product['offers'][0]) ? $product['offers'][0] : $product['offers'];
                        // AggregateOffer (used by marketplaces such as Eneba)
                        // publishes its current minimum as lowPrice instead of price.
                        if (isset($offers['price']) || isset($offers['lowPrice'])) {
                            $extracted['price'] = (float) ($offers['price'] ?? $offers['lowPrice']);
                        }
                        if (! empty($offers['priceCurrency'])) {
                            $extracted['currency'] = (string) $offers['priceCurrency'];
                        }
                        if (isset($offers['availability'])) {
                            $avail = (string) $offers['availability'];
                            $extracted['availability'] = str_contains($avail, 'InStock') || str_contains($avail, 'PreOrder');
                        }
                    }

                    if (! empty($extracted['name']) && ! empty($extracted['price'])) {
                        break;
                    }
                }
            }
        }

        return $extracted;
    }

    /**
     * Busca recursivamente un objeto con @type == 'Product'.
     */
    private function findProductInJsonLd(mixed $data): ?array
    {
        if (! is_array($data)) {
            return null;
        }

        if (isset($data['@type']) && (strtolower($data['@type']) === 'product' || (is_array($data['@type']) && in_array('Product', $data['@type'])))) {
            return $data;
        }

        if (isset($data['@graph']) && is_array($data['@graph'])) {
            foreach ($data['@graph'] as $node) {
                $found = $this->findProductInJsonLd($node);
                if ($found) {
                    return $found;
                }
            }
        }

        foreach ($data as $item) {
            if (is_array($item)) {
                $found = $this->findProductInJsonLd($item);
                if ($found) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * Extrae información de etiquetas OpenGraph y meta estándares.
     */
    private function extractMetaTags(string $html): array
    {
        $data = [];

        // og:title o twitter:title o <title>
        if (preg_match('/<meta\b[^>]*property=["\']og:title["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $m)) {
            $data['name'] = $m[1];
        } elseif (preg_match('/<meta\b[^>]*name=["\']twitter:title["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $m)) {
            $data['name'] = $m[1];
        } elseif (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
            $data['name'] = trim($m[1]);
        }

        // og:image
        if (preg_match('/<meta\b[^>]*property=["\']og:image["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $m)) {
            $data['image_url'] = $m[1];
        } elseif (preg_match('/<meta\b[^>]*name=["\']twitter:image["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $m)) {
            $data['image_url'] = $m[1];
        }

        // Precios en meta tags
        if (preg_match('/<meta\b[^>]*(?:property|name)=["\'](?:product:price:amount|og:price:amount)["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $m)) {
            $data['price'] = (float) $m[1];
        }

        if (preg_match('/<meta\b[^>]*(?:property|name)=["\'](?:product:price:currency|og:price:currency)["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $m)) {
            $data['currency'] = $m[1];
        }

        return $data;
    }

    /**
     * Heurísticas de extracción para páginas con renderizado HTML común.
     */
    private function extractHeuristics(string $html, string $storeSlug): array
    {
        $data = [];

        // Caso Cyberpuerta
        if ($storeSlug === 'cyberpuerta') {
            if (empty($data['price']) && preg_match('/class=["\'][^"\']*priceText[^"\']*["\'][^>]*>\s*\$?([\d,]+(?:\.\d{2})?)/i', $html, $m)) {
                $data['price'] = (float) str_replace(',', '', $m[1]);
            }
            if (empty($data['old_price']) && preg_match('/class=["\'][^"\']*oldPrice[^"\']*["\'][^>]*>\s*\$?([\d,]+(?:\.\d{2})?)/i', $html, $m)) {
                $data['old_price'] = (float) str_replace(',', '', $m[1]);
            }
            if (empty($data['name']) && preg_match('/<h1[^>]*class=["\'][^"\']*detailsInfo_right_title[^"\']*["\'][^>]*>(.*?)<\/h1>/is', $html, $m)) {
                $data['name'] = trim(strip_tags($m[1]));
            }
            if (empty($data['sku']) && preg_match('/Art[ií]culo:\s*<span>([^<]+)<\/span>/i', $html, $m)) {
                $data['sku'] = trim($m[1]);
            }
            // Galería en Cyberpuerta: extraer imágenes de cpd-section-pdp-gallery o img/product/
            $cpGallery = [];
            if (preg_match('/class=["\'][^"\']*cpd-section-pdp-gallery[^"\']*["\'][^>]*>(.*?)<\/section>/is', $html, $mSec)) {
                if (preg_match_all('/https:\/\/www\.cyberpuerta\.mx\/img\/product\/(?:L|S|XS)\/([^\s"\'<>]+\.(?:jpg|png|webp))/i', $mSec[1], $cpM)) {
                    foreach (array_unique($cpM[1]) as $filename) {
                        $cpGallery[] = "https://www.cyberpuerta.mx/img/product/L/{$filename}";
                    }
                }
            } elseif (preg_match_all('/https:\/\/www\.cyberpuerta\.mx\/img\/product\/(?:L|S|XS)\/([^\s"\'<>]+\.(?:jpg|png|webp))/i', $html, $cpM)) {
                foreach (array_unique($cpM[1]) as $filename) {
                    $cpGallery[] = "https://www.cyberpuerta.mx/img/product/L/{$filename}";
                }
            }

            if (! empty($cpGallery)) {
                $data['images'] = array_values(array_unique($cpGallery));
                if (empty($data['image_url'])) {
                    $data['image_url'] = $data['images'][0];
                }
            }
        }

        // Caso Mercado Libre
        if ($storeSlug === 'mercado-libre-mexico') {
            if (empty($data['price']) && preg_match('/class=["\'][^"\']*ui-pdp-price__second-line[^"\']*["\'].*?class=["\'][^"\']*andes-money-amount__fraction["\'][^>]*>([\d,]+)<\/span>/is', $html, $m)) {
                $cents = 0;
                if (preg_match('/class=["\'][^"\']*andes-money-amount__cents[^"\']*["\'][^>]*>(\d+)<\/span>/i', $html, $cm)) {
                    $cents = (int) $cm[1];
                }
                $cleanMain = (float) str_replace(',', '', $m[1]);
                $data['price'] = $cleanMain + ($cents / 100);
            }
            if (empty($data['name']) && preg_match('/<h1[^>]*class=["\'][^"\']*ui-pdp-title[^"\']*["\'][^>]*>(.*?)<\/h1>/is', $html, $m)) {
                $data['name'] = trim(strip_tags($m[1]));
            }
            if (preg_match_all('/https:\/\/http2\.mlstatic\.com\/D_NQ_NP_[^\s"\'<>]+\.(?:webp|jpg)/i', $html, $meliImgs)) {
                $highRes = [];
                foreach (array_unique($meliImgs[0]) as $mImg) {
                    $cleanImg = preg_replace('/-[A-Z]\.(webp|jpg)$/i', '-O.webp', $mImg);
                    $highRes[] = $cleanImg;
                }
                if (! empty($highRes)) {
                    $data['images'] = array_values(array_slice(array_unique($highRes), 0, 8));
                    if (empty($data['image_url'])) {
                        $data['image_url'] = $data['images'][0];
                    }
                }
            }
        }

        // Caso Coppel
        if ($storeSlug === 'coppel') {
            if (empty($data['price']) && preg_match('/class=["\'][^"\']*price[^"\']*["\'][^>]*>\s*\$?([\d,]+(?:\.\d{2})?)/i', $html, $m)) {
                $data['price'] = (float) str_replace(',', '', $m[1]);
            }
            if (empty($data['name']) && preg_match('/<h1[^>]*class=["\'][^"\']*pdp-product-name[^"\']*["\'][^>]*>(.*?)<\/h1>/is', $html, $m)) {
                $data['name'] = trim(strip_tags($m[1]));
            }
            if (empty($data['sku']) && preg_match('/(?:SKU|pm\/|id=)(\d{5,10})/i', $html, $m)) {
                $data['sku'] = trim($m[1]);
            }
            if (preg_match_all('/https:\/\/cdn[0-9]*\.coppel\.com\/pm\/[0-9]+-[0-9]+\.jpg/i', $html, $coppelImgs)) {
                $uniqueCoppel = array_values(array_unique($coppelImgs[0]));
                natsort($uniqueCoppel);
                $uniqueCoppel = array_values($uniqueCoppel);
                if (! empty($uniqueCoppel)) {
                    $data['images'] = $uniqueCoppel;
                    $data['image_url'] = $uniqueCoppel[0];
                }
            }
        }

        // Caso DDTech
        if ($storeSlug === 'ddtech') {
            // Precio actual
            if (empty($data['price'])) {
                if (preg_match('/class=["\'][^"\']*price-box[^"\']*["\'].*?class=["\'][^"\']*price[^"\']*["\'][^>]*>\s*\$?\s*([\d,]+(?:\.\d{2})?)/is', $html, $m)) {
                    $data['price'] = (float) str_replace(',', '', $m[1]);
                } elseif (preg_match('/class=["\'][^"\']*price[^"\']*["\'][^>]*>\s*\$?\s*([\d,]+(?:\.\d{2})?)/i', $html, $m)) {
                    $data['price'] = (float) str_replace(',', '', $m[1]);
                }
            }

            // Precio anterior / tachado
            if (empty($data['old_price']) && preg_match('/class=["\'][^"\']*price-strike[^"\']*["\'][^>]*>\s*\$?\s*([\d,]+(?:\.\d{2})?)/i', $html, $m)) {
                $data['old_price'] = (float) str_replace(',', '', $m[1]);
            }

            // Nombre del producto (evitar el header <h1>DD Tech</h1>)
            if (preg_match('/<div[^>]*class=["\'][^"\']*product-info[^"\']*["\'][^>]*>.*?<h1[^>]*>(.*?)<\/h1>/is', $html, $m)) {
                $data['name'] = trim(strip_tags($m[1]));
            } elseif (preg_match('/<h1[^>]*class=["\'][^"\']*name[^"\']*["\'][^>]*>(.*?)<\/h1>/is', $html, $m)) {
                $data['name'] = trim(strip_tags($m[1]));
            }

            // SKU / Código interno
            if (empty($data['sku'])) {
                if (preg_match('/const\s+internal_code\s*=\s*["\']([^"\']+)["\']/i', $html, $m)) {
                    $data['sku'] = trim($m[1]);
                } elseif (preg_match('/itemprop=["\']sku["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $m)) {
                    $data['sku'] = trim($m[1]);
                }
            }

            // Marca
            if (empty($data['brand'])) {
                if (preg_match('/const\s+brand\s*=\s*["\']([^"\']+)["\']/i', $html, $m) && ! empty($m[1])) {
                    $data['brand'] = trim($m[1]);
                }
            }

            // Disponibilidad / Stock
            if (preg_match('/class=["\'][^"\']*stock-box[^"\']*["\'].*?<span\s+class=["\']value["\']>(\d+)<\/span>/is', $html, $m)) {
                $data['availability'] = (int) $m[1] > 0;
            } elseif (preg_match('/Agotado/i', $html)) {
                $data['availability'] = false;
            }

            // Galería de imágenes exclusiva del producto en DDTech
            // Se aísla el contenedor de galería (#owl-single-product) para evitar anuncios de MSI o banners
            $galleryImgs = [];
            $galleryBlock = '';

            if (preg_match('/<div[^>]*id=["\']owl-single-product["\'][^>]*>(.*?)<\/div>\s*<\/div>\s*<div[^>]*class=["\'][^"\']*single-product-gallery-thumbs/is', $html, $mBlock)) {
                $galleryBlock = $mBlock[1];
            } elseif (preg_match('/class=["\'][^"\']*single-product-gallery[^"\']*["\'][^>]*>(.*?)<div[^>]*class=["\'][^"\']*product-info/is', $html, $mBlock)) {
                $galleryBlock = $mBlock[1];
            }

            if (! empty($galleryBlock)) {
                // Remover stickers de tags-inside ("Nuevo", "Meses sin intereses", etc.)
                $cleanGalleryBlock = preg_replace('/<div[^>]*class=["\'][^"\']*tags-inside[^"\']*["\'][^>]*>.*?<\/div>/is', '', $galleryBlock);
                if (preg_match_all('/(?:href|src|data-echo)=["\'](https:\/\/ddtech\.mx\/assets\/uploads\/[a-zA-Z0-9_\-]+\.(?:png|jpg|jpeg|webp))["\']/i', $cleanGalleryBlock, $allImgs)) {
                    foreach (array_unique($allImgs[1]) as $imgUrl) {
                        if (! str_contains($imgUrl, 'blank.gif') && ! str_contains($imgUrl, 'logo') && ! str_contains($imgUrl, 'banner')) {
                            $galleryImgs[] = $imgUrl;
                        }
                    }
                }
            }

            // Asegurar que la imagen principal (og:image) sea la primera si es válida
            if (! empty($data['image_url']) && ! in_array($data['image_url'], $galleryImgs)) {
                array_unshift($galleryImgs, $data['image_url']);
            }

            if (! empty($galleryImgs)) {
                $data['images'] = array_values(array_unique($galleryImgs));
                $data['image_url'] = $data['images'][0];
            }
        }

        // Búsqueda genérica de precio si aún no se encuentra
        if (empty($data['price'])) {
            if (preg_match('/(?:precio|price)[^>]*>\s*\$\s*([\d,]{1,10}(?:\.\d{2})?)/i', $html, $m)) {
                $data['price'] = (float) str_replace(',', '', $m[1]);
            } elseif (preg_match('/itemprop=["\']price["\'][^>]*content=["\']([\d.]+)["\']/i', $html, $m)) {
                $data['price'] = (float) $m[1];
            }
        }

        return $data;
    }

    /**
     * Determina si el HTML recibido corresponde a una pantalla de bloqueo de Cloudflare o anti-bot.
     */
    private function isBlockedHtml(string $html, int $statusCode): bool
    {
        if ($statusCode === 403 || $statusCode === 503) {
            return true;
        }

        $lower = strtolower($html);

        return str_contains($lower, 'just a moment...') ||
               str_contains($lower, 'cf-mitigated') ||
               str_contains($lower, 'challenge-platform') ||
               str_contains($lower, 'checking your browser') ||
               str_contains($lower, '/blocked?url=');
    }

    /**
     * Resuelve el desafío anti-bot utilizando el microservicio FlareSolverr en Docker (puerto 8191).
     */
    private function solveWithFlareSolverr(string $url): ?string
    {
        $flaresolverrUrl = rtrim((string) env('FLARESOLVERR_URL', ''), '/');

        if ($flaresolverrUrl === '') {
            return null;
        }

        if (! str_ends_with($flaresolverrUrl, '/v1')) {
            $flaresolverrUrl .= '/v1';
        }
        $sessionName = 'default_session';

        // 1. Asegurar sesión en FlareSolverr para reutilizar cookies de Cloudflare
        try {
            Http::timeout(5)->post($flaresolverrUrl, [
                'cmd' => 'sessions.create',
                'session' => $sessionName,
            ]);
        } catch (\Exception $e) {
            // Ignorar si ya existe
        }

        // 2. Ejecutar petición con sesión y timeout de 60 segundos
        try {
            $response = Http::timeout(65)->post($flaresolverrUrl, [
                'cmd' => 'request.get',
                'url' => $url,
                'session' => $sessionName,
                'maxTimeout' => 60000,
            ]);

            if ($response->successful()) {
                $json = $response->json();
                if (isset($json['solution']['response']) && ! empty($json['solution']['response'])) {
                    return $json['solution']['response'];
                }
            }
        } catch (\Exception $e) {
            // Continuar al intento de recuperación
        }

        // 3. Fallback sin sesión en caso de que la sesión esté bloqueada
        try {
            $response = Http::timeout(65)->post($flaresolverrUrl, [
                'cmd' => 'request.get',
                'url' => $url,
                'maxTimeout' => 60000,
            ]);

            if ($response->successful()) {
                $json = $response->json();
                if (isset($json['solution']['response']) && ! empty($json['solution']['response'])) {
                    return $json['solution']['response'];
                }
            }
        } catch (\Exception $e) {
            // Fallback falló
        }

        return null;
    }
}
