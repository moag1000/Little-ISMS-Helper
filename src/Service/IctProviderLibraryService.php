<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\IctProviderLibrary;
use App\Entity\Supplier;
use App\Entity\Tenant;

/**
 * F-NEU — applies a curated {@see IctProviderLibrary} entry to a tenant by
 * pre-filling a {@see Supplier} (DORA-relevant) from the library master data.
 *
 * Returns an UNPERSISTED Supplier so the caller (controller) can run it through
 * the normal form / review flow rather than silently writing — keeping the
 * curated-library "stage, don't auto-commit" governance.
 */
final class IctProviderLibraryService
{
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
