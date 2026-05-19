<?php

declare(strict_types=1);

namespace App\Job;

use App\Service\AuditLogger;
use App\Service\DataIntegrityService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Async QuickFix repair: merges duplicate entities for the given type.
 *
 * Keeps the entity with the lowest ID (oldest); deletes newer duplicates.
 * Idempotent — zero duplicates means no DB changes.
 *
 * Args:
 *   entityType (string) — audits, assets, risks, incidents, documents
 */
final class QuickFixRepairDuplicatesJob implements AsyncJobInterface
{
    private const ALLOWED_TYPES = ['audits', 'assets', 'risks', 'incidents', 'documents'];

    public function __construct(
        private readonly DataIntegrityService $dataIntegrityService,
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function run(JobContext $ctx): void
    {
        $entityType = (string) $ctx->arg('entityType', '');

        if (!in_array($entityType, self::ALLOWED_TYPES, true)) {
            // @intentional-assertion: programmer error — invalid entity type
            throw new \RuntimeException(sprintf(
                'Unsupported entity type "%s" — must be one of: %s.',
                $entityType,
                implode(', ', self::ALLOWED_TYPES),
            ));
        }

        $ctx->message(sprintf('Merging duplicates for "%s"…', $entityType));

        $filters = $this->entityManager->getFilters();
        $wasEnabled = $filters->isEnabled('tenant_filter');
        if ($wasEnabled) {
            $filters->disable('tenant_filter');
        }

        $deleted = 0;
        try {
            $deleted = $this->dataIntegrityService->mergeDuplicates($entityType);

            $this->auditLogger->logCustom(
                'quick_fix.repair.duplicates_merged',
                $entityType,
                0,
                [],
                ['entity_type' => $entityType, 'deleted_count' => $deleted],
                sprintf(
                    'QuickFix(async): merged duplicates for %s — %d record(s) deleted.',
                    $entityType,
                    $deleted,
                ),
            );
        } finally {
            if ($wasEnabled && $this->entityManager->isOpen()) {
                $this->entityManager->getFilters()->enable('tenant_filter');
            }
        }

        $ctx->progress(
            $deleted,
            $deleted,
            sprintf('Done. %d duplicate(s) of "%s" merged.', $deleted, $entityType),
        );
    }
}
