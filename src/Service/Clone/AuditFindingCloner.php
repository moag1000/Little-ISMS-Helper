<?php

declare(strict_types=1);

namespace App\Service\Clone;

use App\Entity\AuditFinding;
use App\Entity\ComplianceRequirement;
use App\Entity\Control;
use App\Entity\InternalAudit;
use App\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * AuditFinding Cloner (C4-C1 — Klon-Funktionen).
 *
 * Use case: recurring finding patterns ("we always find policy access control
 * gaps in supplier-audits") — template a finding and assign it to a new
 * audit cycle. The clone keeps the finding scaffolding (type, severity,
 * clause reference, description, related controls, linked requirements)
 * but is decoupled from the source audit so the user must explicitly
 * attach it to a new audit before flush downstream.
 *
 * Reset on clone:
 *   - status → 'open' (initial lifecycle marking)
 *   - audit ref kept (caller may reassign via edit form)
 *   - findingNumber cleared (auto-generated on next save)
 *   - dueDate cleared
 *   - closedAt cleared
 *   - evidence cleared (per-finding artifact)
 *   - nc-specific fields cleared (verifiedAt/By, ncCorrectionDueDate)
 *     — re-verification is per-finding; do not carry over
 *
 * Cascade omissions:
 *   - correctiveActions OneToMany — CAPA records are per-finding cycle;
 *     a cloned finding starts with no CAPAs and the user creates fresh
 *     ones (or the AutoReactionListener fires on linkedRequirement save)
 *
 * Caller is expected to flush.
 */
final class AuditFindingCloner implements EntityClonerInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function supportsEntity(): string
    {
        return AuditFinding::class;
    }

    /**
     * @param AuditFinding $source
     */
    public function clone(object $source, ?Tenant $targetTenant = null, ?string $titleOverride = null): AuditFinding
    {
        if (!$source instanceof AuditFinding) {
            // @intentional-assertion: programmer error — wrong entity passed to cloner
            throw new \InvalidArgumentException(sprintf(
                'AuditFindingCloner expects %s, got %s',
                AuditFinding::class,
                $source::class,
            ));
        }

        $clone = new AuditFinding();

        $tenant = $targetTenant ?? $source->getTenant();
        if ($tenant instanceof Tenant) {
            $clone->setTenant($tenant);
        }

        // Keep source audit ref so the clone shows up in the same audit's
        // findings list; users can re-assign via edit form.
        if ($source->getAudit() instanceof InternalAudit) {
            $clone->setAudit($source->getAudit());
        }

        $baseTitle = (string) $source->getTitle();
        $clone->setTitle($titleOverride !== null && $titleOverride !== ''
            ? $titleOverride
            : ($baseTitle !== '' ? $baseTitle . ' (Kopie)' : 'Kopie')
        );

        $clone->setDescription($source->getDescription());
        $clone->setType($source->getType());
        $clone->setSeverity($source->getSeverity());
        $clone->setSource($source->getSource());
        $clone->setClauseReference($source->getClauseReference());
        $clone->setRelatedControl($source->getRelatedControl());

        // M2M control + compliance-requirement coverage carries over.
        foreach ($source->getRelatedControls() as $control) {
            if ($control instanceof Control) {
                $clone->addRelatedControl($control);
            }
        }
        foreach ($source->getLinkedRequirements() as $requirement) {
            if ($requirement instanceof ComplianceRequirement) {
                $clone->addLinkedRequirement($requirement);
            }
        }

        // Reset lifecycle to 'open'; clear all per-execution audit data.
        $clone->setStatus('open');
        $clone->setFindingNumber(null);
        $clone->setDueDate(null);
        $clone->setClosedAt(null);
        $clone->setEvidence(null);

        // Nonconformity-specific fields — must be re-verified on the clone.
        $clone->setNonconformityDetails(null);
        $clone->setNcRootCauseSummary(null);
        $clone->setNcCorrectionDueDate(null);
        $clone->setNcVerifiedAt(null);
        $clone->setNcVerifiedBy(null);

        // createdAt is set by the entity constructor — no setter needed.

        $this->entityManager->persist($clone);

        return $clone;
    }
}
