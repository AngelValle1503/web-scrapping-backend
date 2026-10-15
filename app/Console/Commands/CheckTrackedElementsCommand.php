<?php

namespace App\Console\Commands;

use App\Models\TrackedElement;
use App\Models\TrackedElementLog;
use App\Services\HtmlInspectorService;
use App\Services\ProductExtractorService;
use Illuminate\Console\Command;

class CheckTrackedElementsCommand extends Command
{
    protected $signature = 'elements:check';
    protected $description = 'Consulta periódicamente todos los elementos HTML monitoreados y registra los cambios';

    public function handle(HtmlInspectorService $inspector, ProductExtractorService $extractor): int
    {
        $elements = TrackedElement::where('status', 'active')->get();

        if ($elements->isEmpty()) {
            $this->info('No hay elementos activos para monitorear.');
            return Command::SUCCESS;
        }

        $this->info("Iniciando verificación de {$elements->count()} elementos...");

        $htmlCache = [];
        $checked = 0;
        $changed = 0;

        foreach ($elements as $element) {
            $url = $element->url;

            if (!isset($htmlCache[$url])) {
                $this->line("Descargando HTML: {$url}");
                $htmlCache[$url] = $extractor->fetchHtml($url);
            }

            $html = $htmlCache[$url];
            $newValue = $inspector->extractValueFromHtml($html, $element->selector, $element->attribute ?? 'text');

            $now = now();
            $oldValue = $element->current_value;
            $hasChanged = ($newValue !== null && $oldValue !== null && $oldValue !== $newValue);

            $element->update([
                'current_value' => $newValue ?? $oldValue,
                'last_checked_at' => $now,
                'status' => $newValue !== null ? 'active' : 'error',
                'error_message' => $newValue === null ? 'Elemento no encontrado.' : null,
            ]);

            TrackedElementLog::create([
                'tracked_element_id' => $element->id,
                'old_value' => $oldValue,
                'new_value' => $newValue,
                'has_changed' => $hasChanged,
                'status' => $newValue !== null ? 'success' : 'error',
                'error_message' => $newValue === null ? 'Elemento no encontrado.' : null,
                'checked_at' => $now,
            ]);

            $checked++;
            if ($hasChanged) {
                $changed++;
                $this->warn(" -> [CAMBIO DETECTADO] '{$element->name}': '{$oldValue}' => '{$newValue}'");
            } else {
                $this->info(" -> [OK] '{$element->name}': '{$newValue}'");
            }
        }

        $this->info("Verificación finalizada: {$checked} elementos revisados, {$changed} cambios detectados.");
        return Command::SUCCESS;
    }
}
