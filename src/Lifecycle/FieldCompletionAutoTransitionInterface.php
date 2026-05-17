<?php

declare(strict_types=1);

namespace App\Lifecycle;

use Doctrine\ORM\Event\PostUpdateEventArgs;

/**
 * Contract for the field-completion auto-transition listener.
 *
 * Extracted so that {@see \App\Service\WorkflowAutoProgressionService}
 * (deprecated Y.1 wrapper) can depend on this interface rather than the
 * `final` concrete listener class, enabling test-double injection.
 *
 * @see \App\Lifecycle\EventListener\FieldCompletionAutoTransition
 */
interface FieldCompletionAutoTransitionInterface
{
    public function postUpdate(PostUpdateEventArgs $event): void;
}
