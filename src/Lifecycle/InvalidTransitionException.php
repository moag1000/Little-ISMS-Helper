<?php

declare(strict_types=1);

namespace App\Lifecycle;

/**
 * Thrown when a {@see LifecycleService::transition()} call requests a
 * status target that is not allowed for the entity's current status
 * by its registered lifecycle (audit-s3 foundation pattern P-4).
 *
 * Domain-level exception — callers should translate this to a
 * 4xx HTTP response or a user-facing form-error, never to a 500.
 */
final class InvalidTransitionException extends \DomainException
{
    public function __construct(
        string $message,
        public readonly string $entityClass,
        public readonly string $fromStatus,
        public readonly string $toStatus,
        /** @var list<string> */
        public readonly array $allowedTransitions = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
