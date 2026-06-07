<?php

declare(strict_types=1);

namespace App\Service\Planning;

use RuntimeException;

/**
 * Thrown when an ActionItem status transition is not permitted by the
 * transition matrix in {@see ActionItemStatusService}.
 */
final class InvalidActionItemTransitionException extends RuntimeException
{
    public static function for(string $from, string $to): self
    {
        return new self(sprintf('Invalid ActionItem status transition: "%s" → "%s".', $from, $to));
    }
}
