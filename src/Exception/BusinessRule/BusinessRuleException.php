<?php

declare(strict_types=1);

namespace App\Exception\BusinessRule;

use App\Exception\AppException;

/**
 * Thrown when a domain / business-rule pre-condition is violated.
 *
 * Examples: state-machine transition not allowed in current state,
 * self-approval attempted, incomplete record prevents progression.
 *
 * @see App\Exception\AppException
 */
final class BusinessRuleException extends AppException
{
    /**
     * @param string $message    Human-readable reason.
     * @param string|null $ruleCode  Machine-readable rule identifier (e.g. 'draft_required').
     * @param \Throwable|null $previous
     */
    public function __construct(
        string $message,
        private readonly ?string $ruleCode = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getRuleCode(): ?string
    {
        return $this->ruleCode;
    }
}
