<?php

declare(strict_types=1);

namespace App\Exception\Workflow;

use App\Exception\AppException;

/**
 * Thrown when a status field is asked to transition between two values
 * that the 5-transition matrix forbids.
 *
 * Canonical matrix (see CLAUDE.md "Status fields are first-class"):
 *   draft       -> in_review
 *   in_review   -> approved
 *   in_review   -> draft
 *   approved    -> published
 *   published   -> archived
 *   archived    -> published
 *
 * This complements (but does not replace) Symfony Workflow's built-in
 * NotEnabledTransitionException — we use this domain exception in code
 * paths that drive status directly (bulk-status-change, REST PATCH).
 */
final class InvalidStatusTransitionException extends AppException
{
    public function __construct(
        private readonly string $fromStatus,
        private readonly string $toStatus,
        private readonly ?string $entityClass = null,
        ?string $message = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $message ?? \sprintf(
                'Invalid status transition: "%s" -> "%s"%s.',
                $fromStatus,
                $toStatus,
                $entityClass !== null ? ' on '.$entityClass : '',
            ),
            0,
            $previous,
        );
    }

    public function getFromStatus(): string
    {
        return $this->fromStatus;
    }

    public function getToStatus(): string
    {
        return $this->toStatus;
    }

    public function getEntityClass(): ?string
    {
        return $this->entityClass;
    }
}
