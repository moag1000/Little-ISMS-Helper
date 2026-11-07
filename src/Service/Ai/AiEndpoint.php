<?php

declare(strict_types=1);

namespace App\Service\Ai;

/**
 * Where a request goes: an OpenAI-compatible chat endpoint (Ollama, vLLM,
 * LM Studio, Mistral API, …). `external` = outside the operator's network;
 * only then is personal data replaced before sending (unless allowed).
 */
final readonly class AiEndpoint
{
    public function __construct(
        public string $baseUrl,
        public string $model,
        #[\SensitiveParameter] public ?string $apiKey,
        public bool $external,
        public bool $redactPersonalData,
        public int $timeoutSeconds = 60,
    ) {
    }

    public function host(): string
    {
        return (string) (parse_url($this->baseUrl, PHP_URL_HOST) ?: '');
    }

    public function chatUrl(): string
    {
        return rtrim($this->baseUrl, '/') . '/chat/completions';
    }
}
