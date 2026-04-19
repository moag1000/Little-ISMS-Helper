<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\InternalAudit;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\InternalAuditRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Phase 9.P2.4 — derives tenant-scoped audits from a Holding program
 * audit. Idempotent: subsidiaries that already have a derived audit
 * pointing at the same program are skipped.
 *
 * Scope copy strategy (minimal, conservative):
 *   - title, scope, scope_type, scope_details, planned_date,
 *     audit_type are copied verbatim
 *   - auditNumber gets a "-<tenant-code>" suffix so the derived
 *     records stay unique per subtree
 *   - status hard-resets to "planned" — the Tochter runs its own
 *     lifecycle
 *   - tenant is set to the target subsidiary
 *   - parent_audit points back to the program
 *
 * Left unchanged on the program itself: the program stays in the
 * Holding tenant as a template row. Rolling up findings across the
 * subtree happens by enumerating $program->getDerivedAudits().
 */
class GroupAuditProgramService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InternalAuditRepository $auditRepository,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * @param Tenant[] $subsidiaries Target tenants (typically the
     *                               holding's descendants). Order is
     *                               preserved.
     * @return array{derived: list<InternalAudit>, skipped: list<Tenant>}
     */
    public function deriveForSubsidiaries(InternalAudit $program, array $subsidiaries, ?User $actor = null): array
    {
        $derived = [];
        $skipped = [];

        foreach ($subsidiaries as $tenant) {
            if (!$tenant instanceof Tenant) {
                continue;
            }
            if ($tenant === $program->getTenant()) {
                // Don't derive an audit back onto the program's own
                // tenant — that would duplicate the program row.
                $skipped[] = $tenant;
                continue;
            }
            $existing = $this->auditRepository->findOneBy([
                'parentAudit' => $program,
                'tenant' => $tenant,
            ]);
            if ($existing !== null) {
                $skipped[] = $tenant;
                continue;
            }

            $child = (new InternalAudit())
                ->setTenant($tenant)
                ->setParentAudit($program)
                ->setAuditNumber($program->getAuditNumber() . '-' . (string) $tenant->getCode())
                ->setTitle($program->getTitle())
                ->setScope($program->getScope())
                ->setScopeType($program->getScopeType())
                ->setScopeDetails($program->getScopeDetails())
                ->setStatus('planned');

            $plannedDate = $program->getPlannedDate();
            if ($plannedDate !== null) {
                $child->setPlannedDate($plannedDate);
            }

            $this->entityManager->persist($child);
            $derived[] = $child;
        }

        $this->entityManager->flush();

        if ($derived !== []) {
            $this->auditLogger->logCustom(
                'internal_audit.program_derived',
                'InternalAudit',
                $program->getId(),
                null,
                [
                    'program_id' => $program->getId(),
                    'program_number' => $program->getAuditNumber(),
                    'derived_count' => count($derived),
                    'skipped_count' => count($skipped),
                    'derived_tenant_ids' => array_map(
                        static fn(InternalAudit $a): ?int => $a->getTenant()?->getId(),
                        $derived
                    ),
                ],
                sprintf(
                    'Konzern-Audit-Programm %s: %d Tochter-Audits abgeleitet (%d übersprungen)',
                    (string) $program->getAuditNumber(),
                    count($derived),
                    count($skipped)
                ),
            );
        }

        return ['derived' => $derived, 'skipped' => $skipped];
    }
}
