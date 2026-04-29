<?php

declare(strict_types=1);

namespace App\Model;

/**
 * Value object representing a single phase in the ISMS implementation journey.
 *
 * Not a Doctrine entity -- purely in-memory, built by ImplementationJourneyService.
 */
class JourneyPhase
{
    public function __construct(
        public readonly string $key,
        public readonly string $labelKey,
        public readonly string $isoRef,
        public readonly string $icon,
        public readonly int $completionPercent,
        public readonly bool $locked,
        public readonly bool $dismissed,
        public readonly ?string $dismissReason,
        public readonly ?string $dismissedBy,
        public readonly ?\DateTimeImmutable $dismissedAt,
        public readonly string $route,
        public readonly ?string $prerequisiteKey,
    ) {
    }

    /**
     * Derive the display status from completion, locked and dismissed flags.
     *
     * @return string One of 'not_started', 'partial', 'complete', 'locked', 'dismissed'
     */
    public function getStatus(): string
    {
        if ($this->dismissed) {
            return 'dismissed';
        }

        if ($this->locked) {
            return 'locked';
        }

        return match (true) {
            $this->completionPercent >= 100 => 'complete',
            $this->completionPercent > 0   => 'partial',
            default                        => 'not_started',
        };
    }
}
