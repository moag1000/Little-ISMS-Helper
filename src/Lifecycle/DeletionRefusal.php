<?php

declare(strict_types=1);

namespace App\Lifecycle;

/**
 * Why a record may not be deleted ({@see DeletionPolicy::refusal()}): the
 * English sentence for logs, bulk results and the API, and the translation key
 * (domain `lifecycle`) with its parameters for the flash.
 */
final readonly class DeletionRefusal implements \Stringable
{
    /** @param array<string, string|int> $parameters */
    public function __construct(
        public string $reason,
        public string $translationKey,
        public array $parameters = [],
    ) {
    }

    public function __toString(): string
    {
        return $this->reason;
    }
}
