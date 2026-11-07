<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\Tenant;
use App\Service\AuditLogger;
use App\Service\Suggestion\SuggestionText;
use Psr\Log\LoggerInterface;

/**
 * The one way into a language model (Paket 5). Returns a JSON object or null
 * (AI off for the organisation, use case switched off, endpoint not set up
 * or failing). The result is only ever turned into a queue suggestion — the
 * model never writes a record. Personal data is replaced before external
 * calls unless the organisation allowed it. Each call is audit-logged with
 * use case, model, host and whether data was replaced; never with prompt or
 * answer text.
 */
class AiAssistant
{
    public function __construct(
        private readonly AiSettings $settings,
        private readonly OpenAiCompatibleClient $client,
        private readonly PromptRedactor $redactor,
        private readonly AuditLogger $auditLogger,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isAvailable(Tenant $tenant, string $useCase): bool
    {
        return $this->settings->isUseCaseEnabled($tenant, $useCase) && $this->settings->endpointFor($tenant) !== null;
    }

    /**
     * @param string $instructions what to do and the JSON keys to return (the language line is added)
     * @return array{data: array<string, mixed>, model: string}|null
     */
    public function proposeJson(Tenant $tenant, string $useCase, string $instructions, string $input): ?array
    {
        if (!$this->settings->isUseCaseEnabled($tenant, $useCase)) {
            return null;
        }
        $endpoint = $this->settings->endpointFor($tenant);
        if (!$endpoint instanceof AiEndpoint) {
            return null;
        }
        $redacted = $endpoint->redactPersonalData;
        if ($redacted) {
            $input = $this->redactor->redact($input, $tenant);
        }
        $language = SuggestionText::locale($tenant) === 'en' ? 'English' : 'German';
        $system = $instructions
            . "\nYou support an information security management system (ISO/IEC 27001). "
            . "Answer in {$language}. Reply with one JSON object only, no prose around it. "
            . 'Include "confidence" (0..1) and "reasoning" (one or two sentences on which facts you used). '
            . 'Placeholders like [Person 1] stand for removed personal data; keep them as they are.';

        try {
            $answer = $this->client->chat($endpoint, $system, $input);
            $data = self::decode($answer['content']);
        } catch (AiUnavailableException $e) {
            $this->logger->warning('AI request failed', ['use_case' => $useCase, 'tenant' => $tenant->getId(), 'error' => $e->getMessage()]);
            $this->log($tenant, $useCase, $endpoint, $redacted, false);

            return null;
        }
        $this->log($tenant, $useCase, $endpoint, $redacted, true);

        return ['data' => $data, 'model' => $answer['model']];
    }

    /**
     * Sends a fixed test question to the configured endpoint ("test connection").
     *
     * @return array{ok: bool, model: ?string, error: ?string}
     */
    public function test(Tenant $tenant): array
    {
        $endpoint = $this->settings->endpointFor($tenant);
        if (!$endpoint instanceof AiEndpoint) {
            return ['ok' => false, 'model' => null, 'error' => 'not_configured'];
        }
        try {
            $answer = $this->client->chat($endpoint, 'Reply with the JSON object {"ok": true}.', 'ping');
            self::decode($answer['content']);

            return ['ok' => true, 'model' => $answer['model'], 'error' => null];
        } catch (AiUnavailableException $e) {
            return ['ok' => false, 'model' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Models wrap JSON in prose or code fences now and then; take the outermost object.
     *
     * @return array<string, mixed>
     */
    public static function decode(string $content): array
    {
        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start === false || $end === false || $end < $start) {
            throw new AiUnavailableException('Answer is not JSON.');
        }
        try {
            $data = json_decode(substr($content, $start, $end - $start + 1), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new AiUnavailableException('Answer is not JSON.', 0, $e);
        }
        if (!is_array($data)) {
            throw new AiUnavailableException('Answer is not a JSON object.');
        }

        return $data;
    }

    private function log(Tenant $tenant, string $useCase, AiEndpoint $endpoint, bool $redacted, bool $ok): void
    {
        $this->auditLogger->logCustom(
            'ai.request',
            'Tenant',
            $tenant->getId(),
            null,
            [
                'use_case' => $useCase,
                'model' => $endpoint->model,
                'host' => $endpoint->host(),
                'external' => $endpoint->external,
                'personal_data_replaced' => $redacted,
                'ok' => $ok,
            ],
            sprintf('AI request (%s) to %s', $useCase, $endpoint->host()),
        );
    }
}
