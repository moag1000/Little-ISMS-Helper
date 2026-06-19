<?php

declare(strict_types=1);

namespace App\Service\Certificate;

/**
 * Value object returned by CertificateCoverageResolver::resolve().
 *
 * @param array<int|string, string> $requirementIds Deduplicated business requirement-ids covered
 * @param bool                      $isFallback     True when no rule matched and the full framework fallback was used
 */
final readonly class CoverageResult
{
    /**
     * @param array<int|string, string> $requirementIds
     */
    public function __construct(
        public array $requirementIds,
        public bool $isFallback,
    ) {
    }
}
