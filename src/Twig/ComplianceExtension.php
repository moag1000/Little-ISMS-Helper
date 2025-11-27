<?php

namespace App\Twig;

use Override;
use Exception;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Service\ModuleConfigurationService;
use App\Service\TenantContext;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig Extension for Compliance Navigation
 *
 * Provides quick access to compliance frameworks for navigation menus.
 * This enables direct links to frameworks from the sidebar without extra clicks.
 */
class ComplianceExtension extends AbstractExtension
{
    public function __construct(
        private readonly ComplianceFrameworkRepository $complianceFrameworkRepository,
        private readonly ComplianceRequirementRepository $complianceRequirementRepository,
        private readonly ModuleConfigurationService $moduleConfigurationService,
        private readonly TenantContext $tenantContext,
        private readonly CacheInterface $cache
    ) {
    }

    #[Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('get_compliance_frameworks', $this->getComplianceFrameworks(...)),
            new TwigFunction('get_compliance_frameworks_quick', $this->getComplianceFrameworksQuick(...)),
            new TwigFunction('is_nis2_active', $this->isNis2Active(...)),
        ];
    }

    /**
     * Check if NIS2 framework is installed and active
     *
     * @return bool True if NIS2 framework exists and is active
     */
    public function isNis2Active(): bool
    {
        // Return false if compliance module is not active
        if (!$this->moduleConfigurationService->isModuleActive('compliance')) {
            return false;
        }

        try {
            return $this->cache->get('nis2_framework_active', function (ItemInterface $item): bool {
                $item->expiresAfter(300); // Cache for 5 minutes

                $nis2Framework = $this->complianceFrameworkRepository->findOneBy(['code' => 'NIS2']);

                return $nis2Framework && $nis2Framework->isActive();
            });
        } catch (Exception) {
            // On cache failure, fallback to direct database query (no cache)
            // This ensures the application continues to work even if cache fails
            $nis2Framework = $this->complianceFrameworkRepository->findOneBy(['code' => 'NIS2']);
            return $nis2Framework && $nis2Framework->isActive();
        }
    }

    /**
     * Get all active compliance frameworks for navigation
     * Note: This is heavier than getComplianceFrameworksQuick() as it calculates compliance %
     *
     * @return array Array of frameworks with id, code, name, and mandatory status
     */
    public function getComplianceFrameworks(): array
    {
        // Return empty if compliance module is not active
        if (!$this->moduleConfigurationService->isModuleActive('compliance')) {
            return [];
        }

        return $this->cache->get('compliance_nav_frameworks', function (ItemInterface $item): array {
            $item->expiresAfter(300); // Cache for 5 minutes

            $frameworks = $this->complianceFrameworkRepository->findActiveFrameworks();
            $tenant = $this->tenantContext->getCurrentTenant();
            $result = [];

            foreach ($frameworks as $framework) {
                // Calculate tenant-specific compliance percentage
                $stats = $this->complianceRequirementRepository->getFrameworkStatisticsForTenant($framework, $tenant);
                $compliancePercentage = $stats['applicable'] > 0
                    ? round(($stats['fulfilled'] / $stats['applicable']) * 100, 2)
                    : 0;

                $result[] = [
                    'id' => $framework->getId() ?? 0,
                    'code' => $framework->getCode() ?? 'N/A',
                    'name' => $framework->getName() ?? 'Unknown',
                    'mandatory' => $framework->isMandatory() ?? false,
                    'compliance_percentage' => $compliancePercentage,
                ];
            }

            return $result;
        });
    }

    /**
     * Get a quick list of framework codes and IDs for navigation
     * Lightweight version for sidebar menus - NO compliance calculation
     *
     * @return array Array with id, code, name (short version)
     */
    public function getComplianceFrameworksQuick(): array
    {
        // Return empty if compliance module is not active
        if (!$this->moduleConfigurationService->isModuleActive('compliance')) {
            return [];
        }

        return $this->cache->get('compliance_nav_quick', function (ItemInterface $item): array {
            $item->expiresAfter(300); // Cache for 5 minutes

            $frameworks = $this->complianceFrameworkRepository->findActiveFrameworks();
            $result = [];

            foreach ($frameworks as $framework) {
                $name = $framework->getName() ?? 'Unknown';
                // Shorten long names for menu display
                if (strlen($name) > 30) {
                    $name = substr($name, 0, 27) . '...';
                }

                $result[] = [
                    'id' => $framework->getId() ?? 0,
                    'code' => $framework->getCode() ?? 'N/A',
                    'name' => $name,
                    'mandatory' => $framework->isMandatory() ?? false,
                ];
            }

            return $result;
        });
    }
}
