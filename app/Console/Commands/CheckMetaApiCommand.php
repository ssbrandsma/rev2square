<?php

namespace App\Console\Commands;

use App\Services\AI\AIProviderException;
use App\Services\AI\AIRequest;
use App\Services\AI\MetaAIProvider;
use Illuminate\Console\Command;

/**
 * Safe production diagnostic for the Meta Model API integration.
 * Validates configuration and runs one minimal completion. The API key
 * is never printed — only its presence and expected shape are reported.
 */
class CheckMetaApiCommand extends Command
{
    protected $signature = 'ai:check-meta {--no-call : Validate configuration only, without calling the API}';

    protected $description = 'Verify Meta Model API configuration and run a minimal live completion.';

    public function handle(): int
    {
        $config = config('ai.meta');
        $baseUrl = rtrim((string) ($config['base_url'] ?? ''), '/');
        $model = (string) ($config['model'] ?? '');
        $key = (string) ($config['api_key'] ?? '');

        $this->line('Base URL: '.$baseUrl);
        $this->line('Model: '.$model);

        if ($baseUrl === '' || ! str_starts_with($baseUrl, 'https://')) {
            $this->error('META_API_BASE must be an https URL, e.g. https://api.meta.ai/v1.');

            return 1;
        }

        foreach (['/chat/completions', '/responses', '/messages'] as $suffix) {
            if (str_contains($baseUrl, $suffix)) {
                $this->error("META_API_BASE must be the API root without '{$suffix}'.");

                return 1;
            }
        }

        if ($key === '') {
            $this->error('MODEL_API_KEY is not set.');

            return 1;
        }

        $this->line('API key: set ('.strlen($key).' chars, '.(str_starts_with($key, 'LLM_') ? 'LLM_ prefix ok' : 'WARNING: expected LLM_ prefix').')');

        if ($model === '') {
            $this->error('META_MODEL is empty.');

            return 1;
        }

        $this->line("Request: POST {$baseUrl}/chat/completions with model '{$model}'.");

        if ($this->option('no-call')) {
            $this->info('Configuration looks valid (--no-call, no API request sent).');

            return 0;
        }

        try {
            $response = app(MetaAIProvider::class)->complete(new AIRequest(
                system: 'Reply with exactly: OK',
                user: 'ping',
                maxCompletionTokens: 16,
            ));
        } catch (AIProviderException $e) {
            $this->error('Meta Model API call failed: '.$e->getMessage());

            return 1;
        }

        $this->info('Meta Model API call succeeded.');
        $this->line('Model: '.$response->model);
        $this->line('Tokens: in='.($response->inputTokens ?? '?').' out='.($response->outputTokens ?? '?'));
        $this->line('Duration: '.$response->durationMs.' ms');
        $this->line('Completion bytes: '.strlen($response->content));

        return 0;
    }
}
