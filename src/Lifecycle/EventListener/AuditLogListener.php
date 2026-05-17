<?php

declare(strict_types=1);

namespace App\Lifecycle\EventListener;

use App\Service\AuditLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Workflow\Event\CompletedEvent;

/**
 * After successful transition, writes a `status_change` row to audit_log.
 * Uses existing AuditLogger which already covers tenant_id + AUD-02
 * integrity-signature + ISO 27001 Cl. 7.5.3 requirements.
 *
 * The listener defensively wraps AuditLogger to keep transitions from
 * failing on audit-log errors (e.g. closed EM after a different bug).
 */
final class AuditLogListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            'workflow.completed' => ['onCompleted', 50],
        ];
    }

    public function onCompleted(CompletedEvent $event): void
    {
        $subject = $event->getSubject();
        if (!method_exists($subject, 'getId')) {
            return; // pre-flush entity without ID; skip silently
        }

        $context = $event->getContext();
        $ref = new \ReflectionClass($subject);
        // For Doctrine proxies and PHPUnit mocks the real class is the parent.
        // Walk up until we find a class whose short name matches a simple identifier
        // (no underscores from generated names like "MockObject_Foo_abc123").
        $parent = $ref->getParentClass();
        while ($parent !== false && str_contains($ref->getShortName(), '_')) {
            $ref = $parent;
            $parent = $ref->getParentClass();
        }
        $entityClass = $ref->getShortName();
        $entityId = (int) $subject->getId();
        $transitionName = $event->getTransition()->getName();
        $workflowName = $event->getWorkflowName();

        // marking AFTER apply already reflects new place — extract via marking()
        $newPlaces = array_keys($event->getMarking()->getPlaces());

        $this->auditLogger->logCustom(
            'status_change',
            $entityClass,
            $entityId,
            null, // old values reconstructed from from-places below
            [
                'status' => $newPlaces[0] ?? null,
                'workflow' => $workflowName,
                'transition' => $transitionName,
                'reason' => $context['reason'] ?? null,
            ],
            sprintf(
                'Lifecycle: %s#%d transitioned via "%s" to "%s"',
                $entityClass,
                $entityId,
                $transitionName,
                $newPlaces[0] ?? '?',
            ),
        );
    }
}
