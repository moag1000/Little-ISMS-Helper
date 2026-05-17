<?php

declare(strict_types=1);

namespace App\Lifecycle\EventListener;

use App\Lifecycle\Config\LifecycleConfigResolverInterface;
use App\Lifecycle\Exception\ReasonRequiredException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Workflow\Event\TransitionEvent;

/**
 * Pre-apply validator: when transition metadata declares
 * `reason_required: true` (YAML or DB-overlay), the context-array
 * passed to `Workflow::apply()` MUST contain a non-empty `reason` key.
 * Otherwise throws ReasonRequiredException (caught by LifecycleService
 * + translated to 422 in LifecycleController).
 */
final class ReasonValidator implements EventSubscriberInterface
{
    public function __construct(
        private readonly LifecycleConfigResolverInterface $resolver,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            'workflow.transition' => ['onTransition', 50],
        ];
    }

    public function onTransition(TransitionEvent $event): void
    {
        $required = (bool) $this->resolver->get(
            $event->getSubject(),
            $event->getWorkflowName(),
            $event->getTransition()->getName(),
            'reason_required',
            false,
        );

        if (!$required) {
            return;
        }

        $context = $event->getContext();
        $reason = $context['reason'] ?? null;

        if (!is_string($reason) || trim($reason) === '') {
            throw new ReasonRequiredException(
                $event->getWorkflowName(),
                $event->getTransition()->getName(),
            );
        }
    }
}
