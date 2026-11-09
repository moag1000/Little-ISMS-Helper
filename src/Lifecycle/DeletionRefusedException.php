<?php

declare(strict_types=1);

namespace App\Lifecycle;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Thrown by {@see \App\EventListener\DeletionGuard} when a flush would delete a
 * record the {@see DeletionPolicy} keeps. 422 for API and JSON callers; an HTML
 * request is sent back with the reason as a flash
 * ({@see \App\EventSubscriber\DeletionRefusedSubscriber}).
 */
final class DeletionRefusedException extends UnprocessableEntityHttpException
{
    public function __construct(
        public readonly DeletionRefusal $refusal,
        public readonly string $entityType,
    ) {
        parent::__construct(sprintf('Not deleted: %s %s.', $entityType, $refusal->reason));
    }
}
