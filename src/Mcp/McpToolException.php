<?php

declare(strict_types=1);

namespace App\Mcp;

/**
 * A tool could not answer (unknown record, no right, module off). The text
 * goes back to the assistant as a tool error it can explain to the person,
 * never as an HTTP or protocol error.
 */
final class McpToolException extends \RuntimeException
{
    public function __construct(string $message = '', public readonly bool $accessDenied = false)
    {
        parent::__construct($message);
    }

    /** The caller's role, token or organisation setting refused the call — audited as `mcp.denied`. */
    public static function denied(string $message): self
    {
        return new self($message, true);
    }
}
