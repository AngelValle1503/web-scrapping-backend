<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class GenerateApiKeyCommand extends Command
{
    /**
     * El nombre y la firma del comando de consola.
     */
    protected $signature = 'api-key:generate {--show : Solo muestra la clave actual sin generar una nueva}';

    /**
     * La descripción del comando.
     */
    protected $description = 'Genera y guarda una API Key segura para consumir la API de scraping';

    public function handle(): int
    {
        $envPath = base_path('.env');

        if (!file_exists($envPath)) {
            $this->error('No se encontró el archivo .env en el backend.');
            return Command::FAILURE;
        }

        $envContent = file_get_contents($envPath);

        // Si solo se solicitó ver la clave actual
        if ($this->option('show')) {
            if (preg_match('/^API_KEY=(.*)$/m', $envContent, $matches)) {
                $currentKey = trim($matches[1]);
                $this->info("API Key actual: {$currentKey}");
            } else {
                $this->warn('No hay ninguna API_KEY configurada en .env.');
            }
            return Command::SUCCESS;
        }

        // Generar una clave segura con prefijo wsk_live_ (Web Scrapping Key)
        $newKey = 'wsk_live_' . Str::random(40);

        if (preg_match('/^API_KEY=/m', $envContent)) {
            // Reemplazar la existente
            $envContent = preg_replace('/^API_KEY=.*$/m', "API_KEY={$newKey}", $envContent);
        } else {
            // Añadir al final
            $envContent .= "\n# Clave secreta para consumir la API externamente (X-API-KEY)\nAPI_KEY={$newKey}\n";
        }

        file_put_contents($envPath, $envContent);

        $this->newLine();
        $this->info('======================================================');
        $this->info('   ¡API Key generada y guardada exitosamente en .env! ');
        $this->info('======================================================');
        $this->line(" <fg=green;options=bold>{$newKey}</>");
        $this->newLine();
        $this->comment('Cómo usarla en tus peticiones:');
        $this->line('  • Cabecera HTTP: <info>X-API-KEY: ' . $newKey . '</info>');
        $this->line('  • cURL prueba:   <info>curl -H "X-API-KEY: ' . $newKey . '" http://localhost:8000/api/api-key/verify</info>');
        $this->line('  • cURL productos:<info>curl -H "X-API-KEY: ' . $newKey . '" http://localhost:8000/api/products</info>');
        $this->newLine();

        return Command::SUCCESS;
    }
}
