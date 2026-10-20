<?php

namespace Tests\Unit;

use App\Services\HtmlInspectorService;
use App\Services\ProductExtractorService;
use PHPUnit\Framework\TestCase;

class HtmlInspectorServiceTest extends TestCase
{
    public function test_preview_includes_safe_cookie_consent_automation(): void
    {
        $extractor = new class extends ProductExtractorService {
            public function __construct()
            {
            }

            public function fetchHtml(string $url): string
            {
                return <<<'HTML'
<html><head><title>Producto</title><script src="https://example.com/app.js"></script><script type="application/ld+json">{"@context":"https://schema.org","@type":"Product","offers":{"@type":"AggregateOffer","lowPrice":18.5,"priceCurrency":"USD"}}</script></head><body><div id="cookie-banner"><button>Aceptar todas</button></div></body></html>
HTML;
            }
        };

        $preview = (new HtmlInspectorService($extractor))->preparePreview('example.com/product');

        $this->assertSame('Producto', $preview['title']);
        $this->assertStringContainsString('isCookieConsentContext', $preview['html']);
        $this->assertStringContainsString('contextText.length <= 2500', $preview['html']);
        $this->assertStringContainsString('data-ag-cookie-auto-accept', $preview['html']);
        $this->assertStringContainsString('hideCookieConsentContainer', $preview['html']);
        $this->assertStringContainsString('id="__ag_detected_price"', $preview['html']);
        $this->assertStringContainsString('USD 18.50', $preview['html']);
        $this->assertStringContainsString('Aceptar todas', $preview['html']);
        $this->assertStringNotContainsString('https://example.com/app.js', $preview['html']);
        $this->assertSame('USD 18.50', (new HtmlInspectorService($extractor))->extractValueFromUrl('https://example.com/product', '#__ag_detected_price'));
        $this->assertSame(18.5, $extractor->extractFromUrl('https://example.com/product')['price']);
    }
}
