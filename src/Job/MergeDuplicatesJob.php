<?php

declare(strict_types=1);

namespace App\Job;

use App\Service\AuditLogger;
use App\Service\DataIntegrityService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Async admin job: merge duplicate entities for a given entity type.
 *
 * Keeps the entity with the lowest ID (oldest) and removes newer duplicates.
 * Wraps DataIntegrityService::mergeDuplicates() in the tenant-filter-off
 * envelope so global rows are visible.
 *
 * Args:
 *   entityType (string) — one of: audits, assets, risks, incidents, documents
 *   actor      (string) — operator email for audit trail
 */
final class MergeDuplicatesJob implements AsyncJobInterface
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
        $actor = (string) $ctx->arg('actor', 'admin');

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
        } finally {
            if ($wasEnabled && $this->entityManager->isOpen()) {
                $this->entityManager->getFilters()->enable('tenant_filter');
            }
        }

        $this->auditLogger->logCustom(
            'admin.data_repair.duplicates_merged',
            $entityType,
            0,
            [],
            ['entity_type' => $entityType, 'deleted_count' => $deleted, 'actor' => $actor],
            sprintf(
                'Merged duplicates for entity type "%s": %d record(s) deleted (oldest kept). (async job)',
                $entityType,
                $deleted,
            ),
        );

        $ctx->progress(
            $deleted,
            $deleted,
            sprintf('Done. %d duplicate(s) of "%s" merged.', $deleted, $entityType),
        );
    }
}
