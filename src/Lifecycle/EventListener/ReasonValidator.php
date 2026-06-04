<?php

declare(strict_types=1);

namespace App\Lifecycle\EventListener;

use App\Lifecycle\Config\LifecycleConfigResolverInterface;
use App\Lifecycle\Exception\ReasonRequiredException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Workflow\Event\TransitionEvent;

/**
 * Pre-apply validator: when transition metadata declares
 * `reason_required: true` (YAML or DB-overlay), the context-array
 * passed to `Workflow::apply()` MUST contain a non-empty `reason` key.
 * Otherwise throws ReasonRequiredException (caught by LifecycleService
 * + translated to 422 in LifecycleController).
 */
#[AsEventListener(event: 'workflow.transition', method: 'onTransition', priority: 50)]
final class ReasonValidator
{
    public function __construct(
        private readonly LifecycleConfigResolverInterface $resolver,
    ) {}

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
