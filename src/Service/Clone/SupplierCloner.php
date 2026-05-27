<?php

declare(strict_types=1);

namespace App\Service\Clone;

use App\Entity\Supplier;
use App\Entity\Tenant;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Supplier Cloner (C4-C1 — Klon-Funktionen).
 *
 * Use case: onboard a similar SaaS vendor (e.g. clone "GitHub" as template
 * to create "GitLab"), template-build a typical audit firm, or replicate
 * the standard managed-service-provider profile.
 *
 * The clone keeps the template fields (description, service-type,
 * criticality, security-requirements, certifications scaffolding) and
 * resets evaluation state.
 *
 * Reset on clone:
 *   - status → 'evaluation' (initial lifecycle)
 *   - securityScore cleared
 *   - lastSecurityAssessment / nextAssessmentDate cleared
 *   - assessmentFindings / nonConformities cleared
 *   - contract dates cleared (per-supplier negotiation)
 *   - dpaSignedDate cleared
 *
 * Cascade omissions:
 *   - supportedAssets, identifiedRisks, documents M2M — supplier-instance
 *     specific evidence; cloning would attach the new supplier to
 *     historical rows that don't belong to it
 *
 * Caller is expected to flush.
 */
final class SupplierCloner implements EntityClonerInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function supportsEntity(): string
    {
        return Supplier::class;
    }

    /**
     * @param Supplier $source
     */
    public function clone(object $source, ?Tenant $targetTenant = null, ?string $titleOverride = null): Supplier
    {
        if (!$source instanceof Supplier) {
            throw new \InvalidArgumentException(sprintf(
                'SupplierCloner expects %s, got %s',
                Supplier::class,
                $source::class,
            ));
        }

        $clone = new Supplier();

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
        $clone->setContactPerson($source->getContactPerson());
        $clone->setEmail($source->getEmail());
        $clone->setPhone($source->getPhone());
        $clone->setAddress($source->getAddress());
        $clone->setServiceProvided($source->getServiceProvided());
        $clone->setCriticality($source->getCriticality());
        $clone->setSecurityRequirements($source->getSecurityRequirements());
        $clone->setContractualSLAs($source->getContractualSLAs());

        // Certifications scaffolding — supplier-template-worthy.
        $clone->setHasISO27001($source->isHasISO27001());
        $clone->setHasISO22301($source->isHasISO22301());
        $clone->setCertifications($source->getCertifications());
        $clone->setIsDoraRelevant($source->isDoraRelevant());
        $clone->setHasDPA($source->isHasDPA());

        // Reset evaluation state.
        $clone->setStatus('evaluation'); // @phpstan-ignore lifecycle.directSetStatus (initial state on clone pre-persist — matches entity-specific lifecycle.initial_marking)
        $clone->setSecurityScore(null);
        $clone->setLastSecurityAssessment(null);
        $clone->setNextAssessmentDate(null);
        $clone->setAssessmentFindings(null);
        $clone->setNonConformities(null);
        $clone->setContractStartDate(null);
        $clone->setContractEndDate(null);
        $clone->setDpaSignedDate(null);

        $clone->setCreatedAt(new DateTimeImmutable());
        $clone->setUpdatedAt(null);

        $this->entityManager->persist($clone);

        return $clone;
    }
}
