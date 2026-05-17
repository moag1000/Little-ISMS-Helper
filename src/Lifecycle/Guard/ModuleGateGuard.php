<?php

declare(strict_types=1);

namespace App\Lifecycle\Guard;

use App\Lifecycle\Config\LifecycleConfigResolverInterface;
use App\Service\ModuleConfigurationService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Workflow\Event\GuardEvent;

/**
 * Blocks transition if its YAML/DB-overlay metadata includes
 * `module: <key>` and that module is not active for the current tenant.
 */
final class ModuleGateGuard implements EventSubscriberInterface
{
    public function __construct(
        private readonly LifecycleConfigResolverInterface $resolver,
        private readonly ModuleConfigurationService $moduleService,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            'workflow.guard' => ['onGuard', 80],
        ];
    }

    public function onGuard(GuardEvent $event): void
    {
        $workflowName = $event->getWorkflowName();
        $transitionName = $event->getTransition()->getName();
        $subject = $event->getSubject();

        $moduleKey = $this->resolver->get($subject, $workflowName, $transitionName, 'module');
        if ($moduleKey === null || $moduleKey === '') {
            return;
        }

        if (!$this->moduleService->isModuleActive((string) $moduleKey)) {
            $event->setBlocked(true, sprintf("Modul '%s' nicht aktiviert.", $moduleKey));
        }
    }
}
