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
                return '<html><head><title>Producto</title></head><body><div id="cookie-banner"><button>Aceptar todas</button></div></body></html>';
            }
        };

        $preview = (new HtmlInspectorService($extractor))->preparePreview('example.com/product');

        $this->assertSame('Producto', $preview['title']);
        $this->assertStringContainsString('isCookieConsentContext', $preview['html']);
        $this->assertStringContainsString('data-ag-cookie-auto-accept', $preview['html']);
        $this->assertStringContainsString('Aceptar todas', $preview['html']);
    }
}
