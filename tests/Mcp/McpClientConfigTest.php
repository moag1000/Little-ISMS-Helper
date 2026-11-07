<?php

declare(strict_types=1);

namespace App\Tests\Mcp;

use App\Mcp\McpClientConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class McpClientConfigTest extends TestCase
{
    #[Test]
    public function snippetsCarryEndpointAndTokenOrPlaceholder(): void
    {
        $with = McpClientConfig::snippets('https://isms.example.test/mcp', 'abc123');
        self::assertSame(
            'claude mcp add --transport http isms https://isms.example.test/mcp --header "Authorization: Bearer abc123"',
            $with['claude_code'],
        );
        $desktop = json_decode($with['claude_desktop'], true);
        self::assertSame(['-y', 'mcp-remote', 'https://isms.example.test/mcp', '--header', 'Authorization:${ISMS_AUTH}'], $desktop['mcpServers']['isms']['args']);
        self::assertSame('Bearer abc123', $desktop['mcpServers']['isms']['env']['ISMS_AUTH']);

        $project = json_decode($with['project_file'], true);
        self::assertSame('http', $project['mcpServers']['isms']['type']);
        self::assertStringNotContainsString('abc123', $with['project_file'], 'the project file is meant to be committed');

        self::assertStringContainsString(McpClientConfig::TOKEN_PLACEHOLDER, McpClientConfig::snippets('https://x/mcp', null)['claude_code']);
    }
}
