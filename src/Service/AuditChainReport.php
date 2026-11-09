<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Result of {@see AuditLogIntegrityService::verifyChain()}.
 *
 * $unverifiable: rows signed before B3 whose signature covered microseconds the
 * database never stored — their content cannot be re-checked, their chain links are.
 */
final readonly class AuditChainReport
{
    /**
     * @param list<array{id:int,reason:string}> $issues
     */
    public function __construct(
        public array $issues,
        public int $checked,
        public int $unverifiable,
    ) {
    }

    public function isIntact(): bool
    {
        return $this->issues === [];
    }
}
