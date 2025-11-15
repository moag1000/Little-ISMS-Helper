<?php

namespace App\Twig;

use App\Repository\ComplianceFrameworkRepository;
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
        private readonly ComplianceFrameworkRepository $frameworkRepository
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
     *
     * @return array Array of frameworks with id, code, name, and mandatory status
     */
    public function getComplianceFrameworks(): array
    {
        $frameworks = $this->frameworkRepository->findActiveFrameworks();
        $result = [];

        foreach ($frameworks as $framework) {
            $result[] = [
                'id' => $framework->getId(),
                'code' => $framework->getCode(),
                'name' => $framework->getName(),
                'mandatory' => $framework->isMandatory(),
                'compliance_percentage' => $framework->getCompliancePercentage(),
            ];
        }

        return $result;
    }

    /**
     * Get a quick list of framework codes and IDs for navigation
     * Lightweight version for sidebar menus
     *
     * @return array Array with id, code, name (short version)
     */
    public function getComplianceFrameworksQuick(): array
    {
        $frameworks = $this->frameworkRepository->findActiveFrameworks();
        $result = [];

        foreach ($frameworks as $framework) {
            $name = $framework->getName();
            // Shorten long names for menu display
            if (strlen($name) > 30) {
                $name = substr($name, 0, 27) . '...';
            }

            $result[] = [
                'id' => $framework->getId(),
                'code' => $framework->getCode(),
                'name' => $name,
                'mandatory' => $framework->isMandatory(),
            ];
        }

        return $result;
    }
}
