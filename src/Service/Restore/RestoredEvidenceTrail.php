<?php

declare(strict_types=1);

namespace App\Service\Restore;

use App\Service\AuditLogger;
use App\Service\Import\EvidenceFieldFilter;

/**
 * Provenance for evidence a restore brings in. Approvals, report times, test
 * results and overrides (#[ApiReadOnlyEvidence]) are restored as they were —
 * the backup is the record — but nobody in this system decided them now. One
 * audit batch per entity type names every restored record with evidence and
 * which fields came from which backup, so an approval read later can be told
 * apart from one given here.
 */
final class RestoredEvidenceTrail
{
    /** Hand values beside a derivation rule (fulfilment overrides) — evidence as well, without the attribute. */
    private const array EXTRA_FIELDS = [
        \App\Entity\ComplianceRequirementFulfillment::class => ['overrideSource', 'overrideReason', 'overrideReviewDue'],
    ];

    /** @var array<class-string, list<array{entity_id: int|null, fields: list<string>}>> */
    private array $rows = [];

    /** @param list<array<string, mixed>> $entities rows of one entity type, as restored */
    public function collect(string $entityClass, array $entities): void
    {
        $fields = [...EvidenceFieldFilter::evidenceFields($entityClass), ...(self::EXTRA_FIELDS[$entityClass] ?? [])];
        if ($fields === []) {
            return;
        }
        foreach ($entities as $row) {
            $present = array_values(array_filter($fields, static fn (string $f): bool => ($row[$f] ?? null) !== null && ($row[$f] ?? null) !== false));
            if ($present !== []) {
                $this->rows[$entityClass][] = ['entity_id' => isset($row['id']) ? (int) $row['id'] : null, 'fields' => $present];
            }
        }
    }

    public function write(AuditLogger $auditLogger, string $source): void
    {
        foreach ($this->rows as $class => $rows) {
            $auditLogger->logBulk(
                'restore.evidence',
                substr($class, (int) strrpos($class, '\\') + 1),
                ['source' => $source, 'records' => count($rows)],
                array_map(static fn (array $r): array => [
                    'action' => 'restore.evidence',
                    'entity_id' => $r['entity_id'],
                    'new_values' => ['restored_fields' => $r['fields'], 'source' => $source],
                ], $rows),
                sprintf('Restore: %d record(s) with approval/report evidence taken from backup %s', count($rows), $source),
            );
        }
    }
}
