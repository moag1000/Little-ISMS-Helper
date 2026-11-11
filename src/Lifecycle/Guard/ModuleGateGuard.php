<?php

declare(strict_types=1);

namespace App\Lifecycle\Guard;

use App\Entity\Tenant;
use App\Lifecycle\Config\LifecycleConfigResolverInterface;
use App\Lifecycle\EntityTypeRegistry;
use App\Service\ModuleConfigurationService;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Workflow\Event\GuardEvent;

/**
 * Blocks transition if its YAML/DB-overlay metadata includes
 * `module: <key>` and that module is not active for the current tenant.
 */
#[AsEventListener(event: 'workflow.guard', method: 'onGuard', priority: 80)]
final class ModuleGateGuard
{
    public function __construct(
        private readonly LifecycleConfigResolverInterface $resolver,
        private readonly ModuleConfigurationService $moduleService,
    ) {}

    public function onGuard(GuardEvent $event): void
    {
        $workflowName = $event->getWorkflowName();
        $transitionName = $event->getTransition()->getName();
        $subject = $event->getSubject();

        // S35: the record type's module (EntityTypeRegistry) holds for every transition, so a workflow YAML
        // without `module:` metadata cannot be driven while its module is off — UI, REST, MCP and jobs alike.
        $typeModule = EntityTypeRegistry::moduleOfClass($subject::class);
        $tenant = method_exists($subject, 'getTenant') ? $subject->getTenant() : null;
        $moduleKeys = array_unique(array_filter([
            $typeModule,
            (string) $this->resolver->get($subject, $workflowName, $transitionName, 'module'),
        ], static fn (?string $k): bool => $k !== null && $k !== ''));

        foreach ($moduleKeys as $moduleKey) {
            $active = $tenant instanceof Tenant
                ? $this->moduleService->isModuleActiveForTenant($moduleKey, $tenant)
                : $this->moduleService->isModuleActive($moduleKey);
            if (!$active) {
                $event->setBlocked(true, sprintf("Modul '%s' nicht aktiviert.", $moduleKey));

                return;
            }
        }
    }
}
