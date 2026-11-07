<?php

declare(strict_types=1);

namespace App\Service\Ai;

use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * One client for every OpenAI-compatible chat endpoint: Mistral API, Ollama,
 * vLLM, LM Studio, LocalAI. Asks for a JSON object back. External endpoints
 * go through NoPrivateNetworkHttpClient (no DNS rebinding into the LAN).
 */
class OpenAiCompatibleClient
{
    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    /**
     * @return array{content: string, model: string}
     * @throws AiUnavailableException
     */
    public function chat(AiEndpoint $endpoint, string $system, string $user): array
    {
        $client = $endpoint->external ? new NoPrivateNetworkHttpClient($this->httpClient) : $this->httpClient;
        $headers = ['Content-Type' => 'application/json'];
        if ($endpoint->apiKey !== null && $endpoint->apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $endpoint->apiKey;
        }

        try {
            $response = $client->request('POST', $endpoint->chatUrl(), [
                'headers' => $headers,
                'timeout' => $endpoint->timeoutSeconds,
                'max_duration' => $endpoint->timeoutSeconds,
                'json' => [
                    'model' => $endpoint->model,
                    'temperature' => 0.2,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $user],
                    ],
                ],
            ]);
            $status = $response->getStatusCode();
            if ($status >= 400) {
                throw new AiUnavailableException(sprintf('HTTP %d from %s', $status, $endpoint->host()));
            }
            $data = $response->toArray(false);
        } catch (AiUnavailableException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new AiUnavailableException(sprintf('%s: %s', $endpoint->host(), $e->getMessage()), 0, $e);
        }

        $content = $data['choices'][0]['message']['content'] ?? null;
        if (!is_string($content) || trim($content) === '') {
            throw new AiUnavailableException('Empty answer from ' . $endpoint->host());
        }

        return ['content' => $content, 'model' => (string) ($data['model'] ?? $endpoint->model)];
    }
}
