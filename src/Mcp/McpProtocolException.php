<?php

declare(strict_types=1);

namespace App\Mcp;

/**
 * A JSON-RPC error (unknown tool or prompt, invalid parameters). The code is
 * the JSON-RPC error code (-32602 invalid params, …).
 */
final class McpProtocolException extends \RuntimeException
{
}
