<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Mcp\McpSession;
use App\Mcp\McpToolException;

/**
 * Argument helpers shared by the tools. Tools are read-only and available to
 * every caller unless they say otherwise.
 */
abstract class AbstractMcpTool implements McpTool
{
    public function isReadOnly(): bool
    {
        return true;
    }

    public function auditAction(): string
    {
        return $this->isReadOnly() ? 'mcp.read' : 'mcp.propose';
    }

    public function isAvailable(McpSession $session): bool
    {
        return true;
    }

    /** @param array<string, mixed> $arguments */
    protected static function string(array $arguments, string $key, bool $required = false, int $max = 200): ?string
    {
        $value = $arguments[$key] ?? null;
        $value = is_scalar($value) ? trim((string) $value) : '';
        if ($value === '') {
            if ($required) {
                throw new McpToolException(sprintf('Argument "%s" is required.', $key));
            }

            return null;
        }

        return mb_substr($value, 0, $max);
    }

    /** @param array<string, mixed> $arguments */
    protected static function int(array $arguments, string $key, ?int $default = null, bool $required = false): ?int
    {
        $value = $arguments[$key] ?? null;
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return (int) $value;
        }
        if ($required) {
            throw new McpToolException(sprintf('Argument "%s" must be a whole number.', $key));
        }

        return $default;
    }

    protected static function date(?\DateTimeInterface $date): ?string
    {
        return $date?->format('Y-m-d');
    }
}
