<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ComplianceFramework;
use App\Entity\Tenant;
use App\Entity\User;
use App\Event\FrameworkActivatedEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Entry point for framework activation.
 *
 * Fires FrameworkActivatedEvent which CreateInheritanceSuggestionsListener picks up
 * (WS-1 mapping-based inheritance suggestions). Respects the switch
 * mapping_inheritance.enabled of Admin > Compliance-Richtlinien (CompliancePolicyService;
 * env COMPLIANCE_MAPPING_INHERITANCE_ENABLED is only its default — CM-Auflage 4 rollback safety).
 */
final class ComplianceFrameworkActivationService
{
    public function __construct(
        private readonly EventDispatcherInterface $dispatcher,
        private readonly AuditLogger $auditLogger,
        private readonly LoggerInterface $logger,
        private readonly CompliancePolicyService $policy,
    ) {
    }

    private function inheritanceEnabled(): bool
    {
        return $this->policy->getBool(CompliancePolicyService::KEY_INHERITANCE_ENABLED);
    }

    public function activate(Tenant $tenant, ComplianceFramework $framework, User $activatedBy): void
    {
        $inheritanceEnabled = $this->inheritanceEnabled();
        $this->auditLogger->logCustom(
            'compliance.framework.activated',
            'ComplianceFramework',
            $framework->getId(),
            null,
            [
                'tenant_id' => $tenant->getId(),
                'framework_code' => $framework->getCode(),
                'activated_by' => $activatedBy->getId(),
                'inheritance_enabled' => $inheritanceEnabled,
            ],
            sprintf('Framework %s activated for tenant #%d', $framework->getCode(), $tenant->getId()),
        );

        if (!$inheritanceEnabled) {
            $this->logger->info('Framework activated, inheritance suggestions skipped (feature flag off)', [
                'framework' => $framework->getCode(),
                'tenant' => $tenant->getId(),
            ]);
            return;
        }

        $this->dispatcher->dispatch(new FrameworkActivatedEvent($tenant, $framework, $activatedBy));
    }
}
