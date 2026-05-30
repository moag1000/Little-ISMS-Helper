<?php

declare(strict_types=1);

namespace App\Service\PolicyParameter;

/**
 * One row of the cross-framework parameter register (audit artifact).
 * `frameworks` are the selected frameworks that constrain this param;
 * `authority`/`source` describe the strongest applicable constraint.
 */
final readonly class RegisterRow
{
    /**
     * @param list<string> $isoClauses
     * @param list<string> $frameworks
     */
    public function __construct(
        public string $paramKey,
        public string $label,
        public mixed $value,
        public ?string $authority,
        public ?string $source,
        public array $isoClauses,
        public array $frameworks,
    ) {
    }

    public function isRegulatory(): bool
    {
        return $this->authority === 'regulatory';
    }
}
