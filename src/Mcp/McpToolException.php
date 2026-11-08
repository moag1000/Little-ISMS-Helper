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
}
