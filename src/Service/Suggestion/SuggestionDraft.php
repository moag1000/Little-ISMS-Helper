<?php

declare(strict_types=1);

namespace App\Service\Suggestion;

/**
 * What a kind proposes, before it is a queue entry. `subject` is the record
 * the proposal is about (an entity with getId(); null = organisation-wide).
 * `dedupKey` defaults to a hash over kind, subject and payload: the same
 * proposal is offered once, and never again after a rejection.
 */
final readonly class SuggestionDraft
{
    /**
     * @param array<string, mixed> $payload what apply() needs, JSON-serialisable
     */
    public function __construct(
        public ?object $subject,
        public string $title,
        public string $rationale,
        public array $payload = [],
        public ?string $editableText = null,
        public ?float $confidence = null,
        public ?string $dedupKey = null,
        public ?string $model = null,
    ) {
    }

    public function subjectClass(): ?string
    {
        if ($this->subject === null) {
            return null;
        }
        $class = $this->subject::class;
        if ($this->subject instanceof \Doctrine\Persistence\Proxy) {
            $class = get_parent_class($this->subject) ?: $class;
        }

        return $class;
    }

    public function subjectId(): ?int
    {
        if ($this->subject === null || !method_exists($this->subject, 'getId')) {
            return null;
        }
        $id = $this->subject->getId();

        return is_int($id) ? $id : null;
    }

    public function resolveDedupKey(string $kind): string
    {
        $raw = $this->dedupKey ?? json_encode([$this->subjectClass(), $this->subjectId(), $this->payload], JSON_THROW_ON_ERROR);

        return sha1($kind . '|' . $raw);
    }
}
