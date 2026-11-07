<?php

declare(strict_types=1);

namespace App\Service\Suggestion\Kind;

use App\Entity\Tenant;
use App\Enum\SuggestionOrigin;
use App\Repository\SuggestionRepository;
use App\Service\Ai\AiAssistant;
use App\Service\Suggestion\AbstractSuggestionKind;
use App\Service\Suggestion\SuggestionDraft;

/**
 * Base of the AI kinds: ask the model once per record (a rejection is
 * final), at most MAX_PER_RUN records per organisation and run, only while
 * the organisation has AI and this use case switched on.
 */
abstract class AbstractAiKind extends AbstractSuggestionKind
{
    public const int MAX_PER_RUN = 10;

    public function __construct(
        protected readonly AiAssistant $ai,
        protected readonly SuggestionRepository $suggestions,
    ) {
    }

    public function origin(): SuggestionOrigin
    {
        return SuggestionOrigin::Ai;
    }

    /** Each AI text is read and decided on its own. */
    public function allowsBulk(): bool
    {
        return false;
    }

    /** @return iterable<object> records worth asking about, newest first */
    abstract protected function candidates(Tenant $tenant): iterable;

    /** Instruction for the model incl. the JSON keys it returns. */
    abstract protected function instructions(): string;

    /** Facts about the record, sent as the user message. */
    abstract protected function describe(object $record): string;

    /** @param array<string, mixed> $data the model's answer */
    abstract protected function toDraft(object $record, array $data, string $model, Tenant $tenant): ?SuggestionDraft;

    public function generate(Tenant $tenant): iterable
    {
        if (!$this->ai->isAvailable($tenant, $this->key())) {
            return;
        }
        $asked = $this->suggestions->subjectIdsWithKind($tenant, $this->key());
        $count = 0;
        foreach ($this->candidates($tenant) as $record) {
            if ($count >= self::MAX_PER_RUN) {
                break;
            }
            $id = method_exists($record, 'getId') ? $record->getId() : null;
            if (!is_int($id) || isset($asked[$id])) {
                continue;
            }
            $count++;
            $answer = $this->ai->proposeJson($tenant, $this->key(), $this->instructions(), $this->describe($record));
            if ($answer === null) {
                continue;
            }
            $draft = $this->toDraft($record, $answer['data'], $answer['model'], $tenant);
            if ($draft !== null) {
                yield $draft;
            }
        }
    }

    /** @param array<string, mixed> $data */
    protected static function text(array $data, string $key, int $max = 5000): string
    {
        $value = $data[$key] ?? '';

        return is_scalar($value) ? mb_substr(trim((string) $value), 0, $max) : '';
    }

    /** @param array<string, mixed> $data */
    protected static function scale(array $data, string $key): int
    {
        return max(1, min(5, (int) ($data[$key] ?? 3)));
    }

    /** @param array<string, mixed> $data */
    protected static function confidence(array $data): ?float
    {
        return is_numeric($data['confidence'] ?? null) ? (float) $data['confidence'] : null;
    }
}
