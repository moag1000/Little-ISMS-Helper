<?php

declare(strict_types=1);

namespace App\Service\ManagementDecision;

/**
 * A prepared proposal for top management: title, text, the record it is about
 * and the values the approval will cover.
 */
final readonly class DecisionProposal
{
    /** @param array<string, mixed>|null $snapshot */
    public function __construct(
        public string $title,
        public string $text,
        public ?string $subjectType = null,
        public ?int $subjectId = null,
        public ?array $snapshot = null,
    ) {
    }
}
