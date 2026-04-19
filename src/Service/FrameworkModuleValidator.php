<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ComplianceFramework;
use App\Repository\ComplianceFrameworkRepository;

/**
 * L-02: Validates that every active ComplianceFramework has all of its
 * required modules enabled in the current tenant/configuration.
 *
 * Surfaces a warning list that the admin-UI and dashboards can render,
 * plus a CLI entry point for CI / scheduled checks.
 */
class FrameworkModuleValidator
{
    public function __construct(
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly ModuleConfigurationService $moduleConfigurationService,
    ) {
    }

    /**
     * @return list<array{framework_code: string, framework_name: string, missing_modules: list<string>}>
     */
    public function findFrameworksWithMissingModules(): array
    {
        $active = $this->frameworkRepository->findBy(['active' => true]);
        $activeModules = $this->moduleConfigurationService->getActiveModules();
        $issues = [];

        foreach ($active as $framework) {
            $required = $framework->getRequiredModules();
            if ($required === []) {
                continue;
            }
            $missing = array_values(array_diff($required, $activeModules));
            if ($missing === []) {
                continue;
            }
            $issues[] = [
                'framework_code' => (string) $framework->getCode(),
                'framework_name' => (string) $framework->getName(),
                'missing_modules' => $missing,
            ];
        }
        return $issues;
    }

    public function isFrameworkOperational(ComplianceFramework $framework): bool
    {
        $required = $framework->getRequiredModules();
        if ($required === []) {
            return true;
        }
        $active = $this->moduleConfigurationService->getActiveModules();
        return array_diff($required, $active) === [];
    }
}
