<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\IctProviderLibrary;
use App\Entity\Supplier;
use App\Entity\Tenant;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;

/**
 * F-NEU — applies a curated {@see IctProviderLibrary} entry to a tenant by
 * pre-filling a {@see Supplier} (DORA-relevant) from the library master data.
 *
 * {@see buildSupplier()} returns an UNPERSISTED Supplier; {@see applyToTenant()}
 * persists it (+ audit) and is the path the UI "apply" button uses, landing the
 * manager on the supplier detail page to review/complete it.
 */
final class IctProviderLibraryService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * Build + persist a Supplier from a library entry, audited.
     */
    public function applyToTenant(IctProviderLibrary $entry, Tenant $tenant): Supplier
    {
        $supplier = $this->buildSupplier($entry, $tenant);
        $this->entityManager->persist($supplier);
        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            action: 'ict_provider_library.applied',
            entityType: 'Supplier',
            entityId: $supplier->getId(),
            description: sprintf('Supplier created from ICT-provider library entry "%s"', $entry->getName()),
        );

        return $supplier;
    }

    public function appliedFlash(IctProviderLibrary $entry): string
    {
        return sprintf('Supplier "%s" created from the ICT-provider library — please review and complete the details.', $entry->getName());
    }

    public function buildSupplier(IctProviderLibrary $entry, Tenant $tenant): Supplier
    {
        $supplier = new Supplier();
        $supplier->setTenant($tenant);
        $supplier->setName($entry->getName());
        $supplier->setDescription($entry->getServiceType());
        $supplier->setServiceProvided($entry->getServiceType());
        $supplier->setCriticality($this->mapCriticality($entry->getDefaultCriticality()));
        $supplier->setCountryOfHeadOffice($entry->getHeadquartersCountry());
        $supplier->setIsDoraRelevant(true);
        // Status is lifecycle-managed — the Supplier keeps its entity default and
        // transitions via LifecycleService once the caller persists it.

        return $supplier;
    }

    /**
     * Library criticality hints (critical|important|standard) → Supplier scale.
     */
    private function mapCriticality(string $libraryValue): string
    {
        return match ($libraryValue) {
            'critical' => 'critical',
            'standard' => 'low',
            default    => 'medium',
        };
    }
}
