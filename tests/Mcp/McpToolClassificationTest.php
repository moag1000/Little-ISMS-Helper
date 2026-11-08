<?php

declare(strict_types=1);

namespace App\Tests\Mcp;

use App\Mcp\McpServer;
use App\Mcp\Tool\McpTool;
use App\Mcp\Tool\ProposeDraftTool;
use App\Mcp\Tool\SaveRecordTool;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * S4 (2026-11): the assistant's tools that change anything are an explicit
 * list. {@see McpServer} checks McpWriteAccess for every `mcp.write` tool
 * before it runs; a new tool that writes but calls itself read-only would
 * slip past that — so each tool's classification is pinned here.
 */
final class McpToolClassificationTest extends TestCase
{
    /** tool class => audit action of a tool that changes data */
    private const array CHANGING_TOOLS = [
        SaveRecordTool::class => 'mcp.write',
        ProposeDraftTool::class => 'mcp.propose',
    ];

    #[Test]
    public function onlyTheListedToolsChangeData(): void
    {
        $tools = 0;
        foreach (glob(dirname(__DIR__, 2) . '/src/Mcp/Tool/*.php') ?: [] as $file) {
            $class = 'App\\Mcp\\Tool\\' . basename($file, '.php');
            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract() || $reflection->isInterface() || !$reflection->implementsInterface(McpTool::class)) {
                continue;
            }
            /** @var McpTool $tool */
            $tool = $reflection->newInstanceWithoutConstructor();
            ++$tools;

            $expected = self::CHANGING_TOOLS[$class] ?? null;
            if ($expected === null) {
                self::assertTrue($tool->isReadOnly(), $class . ' changes data: add it to CHANGING_TOOLS and let McpServer gate it.');
                self::assertSame('mcp.read', $tool->auditAction(), $class);
            } else {
                self::assertFalse($tool->isReadOnly(), $class);
                self::assertSame($expected, $tool->auditAction(), $class);
            }
        }

        self::assertGreaterThanOrEqual(10, $tools);
    }

    /**
     * S6: the classification above trusts isReadOnly(). A tool that calls
     * itself read-only must not contain a writing call either — persisting,
     * the gateway's write(), offering a suggestion, a lifecycle transition,
     * raw SQL. The source of every read-only tool is scanned for them.
     */
    #[Test]
    public function aReadOnlyToolContainsNoWritingCall(): void
    {
        $writing = '/->\s*(persist|flush|remove|write|offer|apply|accept|transition|executeStatement|executeQuery|save|delete|setStatus)\s*\(/';
        $scanned = 0;
        foreach (glob(dirname(__DIR__, 2) . '/src/Mcp/Tool/*.php') ?: [] as $file) {
            $class = 'App\\Mcp\\Tool\\' . basename($file, '.php');
            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract() || $reflection->isInterface() || isset(self::CHANGING_TOOLS[$class])) {
                continue;
            }
            ++$scanned;
            preg_match_all($writing, (string) file_get_contents($file), $calls);
            self::assertSame([], $calls[1], sprintf('%s says it is read-only but calls %s — classify it in CHANGING_TOOLS.', $class, implode(', ', $calls[1])));
        }
        self::assertGreaterThanOrEqual(8, $scanned);
    }
}
