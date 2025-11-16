<?php

namespace App\Twig;

use App\Repository\ComplianceFrameworkRepository;
use App\Service\ModuleConfigurationService;
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
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly ModuleConfigurationService $moduleConfigService,
        private readonly CacheInterface $cache
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('get_compliance_frameworks', [$this, 'getComplianceFrameworks']),
            new TwigFunction('get_compliance_frameworks_quick', [$this, 'getComplianceFrameworksQuick']),
        ];
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
        if (!$this->moduleConfigService->isModuleActive('compliance')) {
            return [];
        }

        return $this->cache->get('compliance_nav_frameworks', function (ItemInterface $item) {
            $item->expiresAfter(300); // Cache for 5 minutes

            $frameworks = $this->frameworkRepository->findActiveFrameworks();
            $result = [];

            foreach ($frameworks as $framework) {
                $result[] = [
                    'id' => $framework->getId() ?? 0,
                    'code' => $framework->getCode() ?? 'N/A',
                    'name' => $framework->getName() ?? 'Unknown',
                    'mandatory' => $framework->isMandatory() ?? false,
                    'compliance_percentage' => $framework->getCompliancePercentage(),
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
        if (!$this->moduleConfigService->isModuleActive('compliance')) {
            return [];
        }

        return $this->cache->get('compliance_nav_quick', function (ItemInterface $item) {
            $item->expiresAfter(300); // Cache for 5 minutes

            $frameworks = $this->frameworkRepository->findActiveFrameworks();
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
