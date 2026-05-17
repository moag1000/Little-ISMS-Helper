<?php

declare(strict_types=1);

namespace App\Lifecycle\EventListener;

use App\Repository\AlvaHintDismissalRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Workflow\Event\CompletedEvent;

/**
 * Invalidates AlvaHint dismissals tied to an entity so that hints
 * resurface after a workflow transition changes the entity's state.
 *
 * Conservative scope: deletes all dismissal rows for (entityType, entityId).
 * Rules that use "stuck in status X" sticky-keys will re-evaluate against
 * the new state on the next page render.
 */
final class AlvaHintInvalidator implements EventSubscriberInterface
{
    public function __construct(
        private readonly AlvaHintDismissalRepository $dismissalRepository,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            'workflow.completed' => ['onCompleted', 30],
        ];
    }

    public function onCompleted(CompletedEvent $event): void
    {
        $subject = $event->getSubject();
        if (!method_exists($subject, 'getId')) {
            return;
        }
        $ref = new \ReflectionClass($subject);
        // For Doctrine proxies and PHPUnit mocks the real class is the parent.
        // Walk up until we find a short name without underscores (generated names
        // look like "MockObject_Foo_abc123").
        $parent = $ref->getParentClass();
        while ($parent !== false && str_contains($ref->getShortName(), '_')) {
            $ref = $parent;
            $parent = $ref->getParentClass();
        }
        $entityClass = $ref->getShortName();
        $entityId = (int) $subject->getId();
        $this->dismissalRepository->invalidateForEntity($entityClass, $entityId);
    }
}
