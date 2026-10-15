<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
use Symfony\Component\CssSelector\CssSelectorConverter;

class HtmlInspectorService
{
    protected ProductExtractorService $extractorService;
    protected CssSelectorConverter $selectorConverter;

    public function __construct(ProductExtractorService $extractorService)
    {
        $this->extractorService = $extractorService;
        $this->selectorConverter = new CssSelectorConverter();
    }

    /**
     * Descarga el HTML de la URL y lo prepara para una previsualización interactiva en un iframe seguro.
     */
    public function preparePreview(string $url): array
    {
        $url = trim($url);
        if (!preg_match('/^https?:\/\//i', $url)) {
            $url = 'https://' . $url;
        }

        $html = $this->extractorService->fetchHtml($url);

        if (empty($html)) {
            throw new \Exception('No se pudo descargar el contenido de la URL.');
        }

        // Extraer título de la página
        $title = 'Página Web';
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
            $title = trim(strip_tags($m[1]));
        }

        // Extraer base URL para que estilos e imágenes relativas se carguen correctamente
        $parsed = parse_url($url);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $baseHref = "{$scheme}://{$host}{$port}";

        // Asegurar que exista <head>
        if (!preg_match('/<head\b[^>]*>/i', $html)) {
            $html = preg_replace('/<html\b[^>]*>/i', "$0<head></head>", $html, 1);
        }

        // Si no tiene <base href>, inyectarlo al inicio del <head>
        if (!preg_match('/<base\b[^>]*>/i', $html)) {
            $html = preg_replace('/(<head\b[^>]*>)/i', "$1\n<base href=\"{$baseHref}/\">\n", $html, 1);
        }

        // Neutralizar meta refreshes y Content-Security-Policy restrictivos en el preview
        $html = preg_replace('/<meta\b[^>]*http-equiv=["\']refresh["\'][^>]*>/i', '', $html);
        $html = preg_replace('/<meta\b[^>]*http-equiv=["\']Content-Security-Policy["\'][^>]*>/i', '', $html);

        // Neutralizar scripts que intentan romper iframes (framebusting)
        $html = preg_replace('/(top|parent)\.location/i', '/* framebuster neutralized */ window.location', $html);
        $html = preg_replace('/window\.top/i', 'window.self', $html);

        // Inyectar estilos auxiliares y script de selección interactiva dentro del HTML
        $inspectorStylesAndScript = <<<'HTML'
<style id="__antigravity_inspector_styles">
    * {
        cursor: crosshair !important;
    }
    #__ag_hover_box {
        position: fixed !important;
        pointer-events: none !important;
        z-index: 2147483640 !important;
        display: none;
        border: 2px solid #38bdf8 !important;
        background-color: rgba(56, 189, 248, 0.15) !important;
        box-shadow: 0 0 12px rgba(56, 189, 248, 0.4), inset 0 0 8px rgba(56, 189, 248, 0.2) !important;
        border-radius: 4px !important;
        transition: all 0.05s ease-out !important;
    }
    #__ag_hover_badge {
        position: absolute !important;
        top: -24px !important;
        left: 0 !important;
        background-color: #0284c7 !important;
        color: #ffffff !important;
        font-size: 11px !important;
        font-family: monospace !important;
        font-weight: bold !important;
        padding: 2px 8px !important;
        border-radius: 4px !important;
        white-space: nowrap !important;
        pointer-events: none !important;
    }
    #__ag_select_box {
        position: fixed !important;
        pointer-events: none !important;
        z-index: 2147483639 !important;
        display: none;
        border: 2px solid #10b981 !important;
        background-color: rgba(16, 185, 129, 0.18) !important;
        box-shadow: 0 0 20px rgba(16, 185, 129, 0.5) !important;
        border-radius: 4px !important;
    }
</style>
<script id="__antigravity_inspector_script">
(function() {
    var inspectorActive = true;
    window.addEventListener('message', function(e) {
        if (e.data && e.data.type === '__TOGGLE_INSPECTOR__') {
            inspectorActive = !!e.data.active;
            var h = document.getElementById('__ag_hover_box');
            if (h) h.style.display = 'none';
        }
    });

    function initInspector() {
        if (document.getElementById('__ag_hover_box')) return;
        var hoverBox = document.createElement('div');
        hoverBox.id = '__ag_hover_box';
        var hoverBadge = document.createElement('span');
        hoverBadge.id = '__ag_hover_badge';
        hoverBox.appendChild(hoverBadge);
        document.body.appendChild(hoverBox);

        var selectBox = document.createElement('div');
        selectBox.id = '__ag_select_box';
        document.body.appendChild(selectBox);

        function computeSelector(el) {
            if (el.id && !/\d{5,}/.test(el.id)) {
                return '#' + CSS.escape(el.id);
            }
            var path = [];
            var current = el;
            while (current && current.nodeType === Node.ELEMENT_NODE && current !== document.body && current !== document.documentElement) {
                var tag = current.tagName.toLowerCase();
                if (current.id && !/\d{5,}/.test(current.id)) {
                    path.unshift('#' + CSS.escape(current.id));
                    break;
                }
                if (current.className && typeof current.className === 'string') {
                    var classes = current.className.trim().split(/\s+/).filter(function(c) {
                        return !c.startsWith('__') && !c.includes(':') && !c.includes('/') && !/\d{5,}/.test(c);
                    });
                    if (classes.length > 0) {
                        tag += '.' + classes.slice(0, 2).map(function(c) { return CSS.escape(c); }).join('.');
                    }
                }
                var test = path.length > 0 ? tag + ' > ' + path.join(' > ') : tag;
                try {
                    if (document.querySelectorAll(test).length === 1) {
                        return test;
                    }
                } catch(err) {}
                path.unshift(tag);
                current = current.parentElement;
            }
            return path.length > 0 ? path.join(' > ') : el.tagName.toLowerCase();
        }

        document.addEventListener('mousemove', function(e) {
            if (!inspectorActive) return;
            var target = e.target;
            if (!target || target === hoverBox || target === hoverBadge || target === selectBox) return;
            var rect = target.getBoundingClientRect();
            hoverBox.style.display = 'block';
            hoverBox.style.top = rect.top + 'px';
            hoverBox.style.left = rect.left + 'px';
            hoverBox.style.width = rect.width + 'px';
            hoverBox.style.height = rect.height + 'px';
            var tagText = target.tagName.toLowerCase();
            var classText = (typeof target.className === 'string' && target.className.trim()) ? '.' + target.className.trim().split(/\s+/)[0] : '';
            hoverBadge.textContent = tagText + classText + ' (' + Math.round(rect.width) + 'x' + Math.round(rect.height) + ')';
        }, true);

        document.addEventListener('click', function(e) {
            if (!inspectorActive) return;
            e.preventDefault();
            e.stopPropagation();
            var target = e.target;
            if (!target || target === hoverBox || target === selectBox) return;
            var rect = target.getBoundingClientRect();
            selectBox.style.display = 'block';
            selectBox.style.top = rect.top + 'px';
            selectBox.style.left = rect.left + 'px';
            selectBox.style.width = rect.width + 'px';
            selectBox.style.height = rect.height + 'px';

            var selector = computeSelector(target);
            var tagName = target.tagName.toUpperCase();
            var text = (target.innerText || target.textContent || '').trim();
            var htmlSnippet = target.outerHTML ? target.outerHTML.slice(0, 300) : '';

            var defaultAttr = 'text';
            if (tagName === 'IMG' && target.hasAttribute('src')) defaultAttr = 'src';
            if (tagName === 'A' && target.hasAttribute('href')) defaultAttr = 'href';

            var attrVal = text;
            if (defaultAttr !== 'text' && target.hasAttribute(defaultAttr)) {
                attrVal = target.getAttribute(defaultAttr) || text;
            }

            window.parent.postMessage({
                type: '__ANTIGRAVITY_ELEMENT_SELECTED__',
                selector: selector,
                tagName: tagName,
                text: attrVal,
                htmlSnippet: htmlSnippet,
                attribute: defaultAttr
            }, '*');
        }, true);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initInspector);
    } else {
        initInspector();
    }
})();
</script>
HTML;

        $html = preg_replace('/(<\/head>)/i', $inspectorStylesAndScript . "\n$1", $html, 1);

        return [
            'url' => $url,
            'title' => $title,
            'html' => $html,
            'base_url' => $baseHref,
        ];
    }

    /**
     * Extrae el valor de un selector CSS sobre un HTML dado.
     */
    public function extractValueFromHtml(string $html, string $selector, string $attribute = 'text'): ?string
    {
        if (empty(trim($html)) || empty(trim($selector))) {
            return null;
        }

        try {
            $xpathQuery = $this->selectorConverter->toXPath(trim($selector));
        } catch (\Exception $e) {
            // Selector CSS inválido
            return null;
        }

        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        // UTF-8 encoding hint
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new DOMXPath($doc);
        $nodes = $xpath->query($xpathQuery);

        if (!$nodes || $nodes->length === 0) {
            return null;
        }

        $node = $nodes->item(0);

        if ($attribute === 'text' || empty($attribute)) {
            $text = $node->textContent ?? '';
            // Normalizar espacios en blanco y saltos de línea repetidos
            return trim(preg_replace('/\s+/', ' ', $text));
        }

        if ($attribute === 'innerHTML') {
            $inner = '';
            foreach ($node->childNodes as $child) {
                $inner .= $doc->saveHTML($child);
            }
            return trim($inner);
        }

        if ($attribute === 'outerHTML') {
            return trim($doc->saveHTML($node));
        }

        if ($node instanceof \DOMElement && $node->hasAttribute($attribute)) {
            return trim($node->getAttribute($attribute));
        }

        return trim($node->textContent ?? '');
    }

    /**
     * Descarga la URL en vivo y extrae el valor del selector CSS.
     */
    public function extractValueFromUrl(string $url, string $selector, string $attribute = 'text'): ?string
    {
        $html = $this->extractorService->fetchHtml($url);
        if (empty($html)) {
            return null;
        }

        return $this->extractValueFromHtml($html, $selector, $attribute);
    }
}
