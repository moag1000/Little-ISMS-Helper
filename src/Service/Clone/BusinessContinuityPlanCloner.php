<?php

declare(strict_types=1);

namespace App\Service\Clone;

use App\Entity\Asset;
use App\Entity\BusinessContinuityPlan;
use App\Entity\CrisisTeam;
use App\Entity\Supplier;
use App\Entity\Tenant;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * BusinessContinuityPlan Cloner (C4-C1 — Klon-Funktionen).
 *
 * Use case: ISO 22301 + BSI 200-4 annual BC-Plan refresh cycle. A BC-Plan
 * captures dozens of fields (RTO/RPO, recovery procedures, communication
 * plan, alternative site, response team), and the next-year version is
 * structurally identical with date/version updates. Cloning saves a full
 * day of structured-form re-entry.
 *
 * The clone keeps the operational template (procedures, RTO/RPO, response
 * team JSON, escalation levels, crisis-team + supplier + asset M2M refs)
 * and resets the test/review state.
 *
 * Reset on clone:
 *   - status → 'draft' (initial lifecycle marking)
 *   - version → '1.0'
 *   - lastTested / nextTestDate cleared (must re-plan exercise cycle)
 *   - lastReviewDate / nextReviewDate cleared
 *   - reviewNotes cleared
 *
 * M2M references preserved — crisis teams, critical suppliers, critical
 * assets are typically the same in a refresh cycle. User can adjust in
 * the edit form after clone.
 *
 * Caller is expected to flush.
 */
final class BusinessContinuityPlanCloner implements EntityClonerInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function supportsEntity(): string
    {
        return BusinessContinuityPlan::class;
    }

    /**
     * @param BusinessContinuityPlan $source
     */
    public function clone(object $source, ?Tenant $targetTenant = null, ?string $titleOverride = null): BusinessContinuityPlan
    {
        if (!$source instanceof BusinessContinuityPlan) {
            throw new \InvalidArgumentException(sprintf(
                'BusinessContinuityPlanCloner expects %s, got %s',
                BusinessContinuityPlan::class,
                $source::class,
            ));
        }

        $clone = new BusinessContinuityPlan();

        $tenant = $targetTenant ?? $source->getTenant();
        if ($tenant instanceof Tenant) {
            $clone->setTenant($tenant);
        }

        $baseName = (string) $source->getName();
        $clone->setName($titleOverride !== null && $titleOverride !== ''
            ? $titleOverride
            : ($baseName !== '' ? $baseName . ' (Kopie)' : 'Kopie')
        );

        $clone->setDescription($source->getDescription());
        $clone->setBusinessProcess($source->getBusinessProcess());
        $clone->setPlanOwner($source->getPlanOwner());

        // Operational content — full template carries over.
        $clone->setActivationCriteria($source->getActivationCriteria());
        $clone->setRolesAndResponsibilities($source->getRolesAndResponsibilities());
        $clone->setResponseTeam($source->getResponseTeam());
        $clone->setResponseTeamMembers($source->getResponseTeamMembers());
        $clone->setEscalationLevels($source->getEscalationLevels());
        $clone->setRecoveryProcedures($source->getRecoveryProcedures());
        $clone->setCommunicationPlan($source->getCommunicationPlan());
        $clone->setInternalCommunication($source->getInternalCommunication());
        $clone->setExternalCommunication($source->getExternalCommunication());
        $clone->setStakeholderContacts($source->getStakeholderContacts());
        $clone->setAlternativeSite($source->getAlternativeSite());
        $clone->setAlternativeSiteAddress($source->getAlternativeSiteAddress());
        $clone->setAlternativeSiteCapacity($source->getAlternativeSiteCapacity());
        $clone->setBackupProcedures($source->getBackupProcedures());
        $clone->setRestoreProcedures($source->getRestoreProcedures());
        $clone->setRequiredResources($source->getRequiredResources());
        $clone->setRto($source->getRto());
        $clone->setRpo($source->getRpo());
        $clone->setBsiPhase($source->getBsiPhase());

        // M2M references — typically reused in next-cycle plan.
        foreach ($source->getCrisisTeams() as $team) {
            if ($team instanceof CrisisTeam) {
                $clone->addCrisisTeam($team);
            }
        }
        foreach ($source->getCriticalSuppliers() as $supplier) {
            if ($supplier instanceof Supplier) {
                $clone->addCriticalSupplier($supplier);
            }
        }
        foreach ($source->getCriticalAssets() as $asset) {
            if ($asset instanceof Asset) {
                $clone->addCriticalAsset($asset);
            }
        }

        // Reset lifecycle + test/review cadence (must be re-planned).
        $clone->setStatus('draft');
        $clone->setVersion('1.0');
        $clone->setLastTested(null);
        $clone->setNextTestDate(null);
        $clone->setLastReviewDate(null);
        $clone->setNextReviewDate(null);
        $clone->setReviewNotes(null);

        $clone->setCreatedAt(new DateTimeImmutable());
        $clone->setUpdatedAt(null);

        $this->entityManager->persist($clone);

        return $clone;
    }
}
