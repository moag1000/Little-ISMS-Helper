<?php

declare(strict_types=1);

namespace App\Service\Suggestion\Kind;

use App\Entity\Tenant;
use App\Enum\SuggestionOrigin;
use App\Repository\SuggestionRepository;
use App\Service\Ai\AiAssistant;
use App\Service\Suggestion\AbstractSuggestionKind;
use App\Service\Suggestion\RecordDraftKind;
use App\Service\Suggestion\SuggestionDraft;

/**
 * Base of the AI kinds: ask the model once per record (a rejection is
 * final), at most MAX_PER_RUN records per organisation and run, only while
 * the organisation has AI and this use case switched on.
 *
 * On a detail page the same kind drafts for one record on request
 * ("Entwurf vorschlagen", RecordDraftKind): `subjectClasses()` names the
 * pages, `isCandidate()` decides whether the button shows, `recordFor()`
 * maps the page's record to the one the model reads (default: itself).
 */
abstract class AbstractAiKind extends AbstractSuggestionKind implements RecordDraftKind
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

    /** Does the record still need this draft (same test as the nightly candidates)? */
    abstract protected function isCandidate(object $record): bool;

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
            if ($this->alreadyAsked($tenant, $record, $asked)) {
                continue;
            }
            $count++;
            $draft = $this->ask($tenant, $record);
            if ($draft !== null) {
                yield $draft;
            }
        }
    }

    public function needsModel(): bool
    {
        return true;
    }

    public function supportsRecord(object $record): bool
    {
        $target = $this->recordFor($record);

        return $target !== null && $this->isCandidate($target);
    }

    public function draftFor(Tenant $tenant, object $record): ?SuggestionDraft
    {
        if (!$this->ai->isAvailable($tenant, $this->key())) {
            return null;
        }
        $target = $this->recordFor($record);

        return $target !== null ? $this->ask($tenant, $target) : null;
    }

    /** The record the model reads for a detail page's record (null = nothing to draft). */
    protected function recordFor(object $record): ?object
    {
        foreach ($this->subjectClasses() as $class) {
            if ($record instanceof $class) {
                return $record;
            }
        }

        return null;
    }

    /**
     * Nightly runs ask once per record. Default: by subject id; kinds whose
     * candidate is not the subject override this.
     *
     * @param array<int, true> $askedSubjectIds
     */
    protected function alreadyAsked(Tenant $tenant, object $record, array $askedSubjectIds): bool
    {
        $id = method_exists($record, 'getId') ? $record->getId() : null;

        return !is_int($id) || isset($askedSubjectIds[$id]);
    }

    private function ask(Tenant $tenant, object $record): ?SuggestionDraft
    {
        $answer = $this->ai->proposeJson($tenant, $this->key(), $this->instructions(), $this->describe($record));

        return $answer !== null ? $this->toDraft($record, $answer['data'], $answer['model'], $tenant) : null;
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
