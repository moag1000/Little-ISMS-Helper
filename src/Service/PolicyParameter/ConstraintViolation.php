<?php

declare(strict_types=1);

namespace App\Service\PolicyParameter;

/**
 * A single "value below framework minimum" finding. `authority` is the framework
 * constraint level (regulatory|benchmark|recommended); only regulatory blocks.
 */
final readonly class ConstraintViolation
{
    public function __construct(
        public string $paramKey,
        public string $framework,
        public mixed $requiredMin,
        public mixed $actualValue,
        public ?string $authority,
        public ?string $source,
    ) {
    }

    public function isBlocking(): bool
    {
        return $this->authority === 'regulatory';
    }
}
