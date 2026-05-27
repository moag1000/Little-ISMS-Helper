<?php

declare(strict_types=1);

namespace App\Service\Clone;

use App\Entity\Risk;
use App\Entity\Tenant;
use App\Enum\RiskStatus;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Risk Cloner (C4-C1 — Klon-Funktionen).
 *
 * Use case: a Consultant wants to roll out the same "Phishing → Customer DB"
 * risk to every subsidiary, or copy a template risk and adjust the asset.
 * Cloning preserves the assessment scaffolding (probability/impact, threat,
 * vulnerability, treatment strategy + description) and the master-data
 * references (asset, location, supplier, person) so the user only needs to
 * tweak what's different.
 *
 * Reset on clone:
 *   - status → Identified (initial lifecycle marking)
 *   - residual scores → 1/1 (force re-assessment of effectiveness)
 *   - reviewDate cleared
 *   - acceptance fields cleared (approval is risk-specific)
 *   - timestamps regenerated
 *
 * Caller is expected to flush.
 */
final class RiskCloner implements EntityClonerInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function supportsEntity(): string
    {
        return Risk::class;
    }

    /**
     * @param Risk $source
     */
    public function clone(object $source, ?Tenant $targetTenant = null, ?string $titleOverride = null): Risk
    {
        if (!$source instanceof Risk) {
            throw new \InvalidArgumentException(sprintf(
                'RiskCloner expects %s, got %s',
                Risk::class,
                $source::class,
            ));
        }

        $clone = new Risk();

        $baseTitle = (string) $source->getTitle();
        $clone->setTitle($titleOverride !== null && $titleOverride !== ''
            ? $titleOverride
            : ($baseTitle !== '' ? $baseTitle . ' (Kopie)' : 'Kopie')
        );

        $tenant = $targetTenant ?? $source->getTenant();
        if ($tenant instanceof Tenant) {
            $clone->setTenant($tenant);
        }

        $clone->setCategory($source->getCategory());
        $clone->setDescription($source->getDescription());
        $clone->setThreat($source->getThreat());
        $clone->setVulnerability($source->getVulnerability());

        // Master-data references stay the same — clone is typically about
        // the same asset/location/supplier with a different angle.
        $clone->setAsset($source->getAsset());
        $clone->setPerson($source->getPerson());
        $clone->setLocation($source->getLocation());
        $clone->setSupplier($source->getSupplier());

        // Inherent assessment carries over; residual is reset to force the
        // user to re-evaluate the treatment effectiveness for the clone.
        $clone->setProbability($source->getProbability());
        $clone->setImpact($source->getImpact());
        $clone->setResidualProbability(1);
        $clone->setResidualImpact(1);

        // Treatment scaffolding (strategy + description) is template-worthy.
        $clone->setTreatmentStrategy($source->getTreatmentStrategy());
        $clone->setTreatmentDescription($source->getTreatmentDescription());
        $clone->setRiskOwner($source->getRiskOwner());

        // GDPR scaffolding — same data class typically applies.
        $clone->setInvolvesPersonalData($source->isInvolvesPersonalData());
        $clone->setInvolvesSpecialCategoryData($source->isInvolvesSpecialCategoryData());
        $clone->setLegalBasis($source->getLegalBasis());
        $clone->setProcessingScale($source->getProcessingScale());
        $clone->setRequiresDPIA($source->isRequiresDPIA());
        $clone->setDataSubjectImpact($source->getDataSubjectImpact());

        // Reset lifecycle to initial marking; clear approval audit fields.
        $clone->setStatus(RiskStatus::Identified); // @phpstan-ignore lifecycle.directSetStatus (initial state on clone pre-persist — matches entity-specific lifecycle.initial_marking)
        $clone->setReviewDate(null);
        $clone->setAcceptanceApprovedBy(null);
        $clone->setAcceptanceApprovedAt(null);
        $clone->setAcceptanceJustification(null);
        $clone->setAcceptanceExpiryDate(null);

        $clone->setCreatedAt(new DateTimeImmutable());
        $clone->setUpdatedAt(null);

        $this->entityManager->persist($clone);

        return $clone;
    }
}
