<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\ComplianceWizardService;
use Twig\Attribute\AsTwigFunction;

/**
 * compliance_wizard_key(frameworkCode) — wizard route key for a framework, or
 * null when no available wizard serves it. Single source of truth is the
 * wizard config (ComplianceWizardService::getAvailableWizards), so templates
 * never derive the key from the framework code by string munging.
 */
final class ComplianceWizardLinkExtension
{
    public function __construct(
        private readonly ComplianceWizardService $wizardService,
    ) {
    }

    #[AsTwigFunction('compliance_wizard_key')]
    public function wizardKey(?string $frameworkCode): ?string
    {
        if ($frameworkCode === null || $frameworkCode === '') {
            return null;
        }

        return $this->wizardService->getWizardKeyForFrameworkCode($frameworkCode);
    }
}
