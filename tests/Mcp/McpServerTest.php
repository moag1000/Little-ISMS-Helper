<?php

declare(strict_types=1);

namespace App\Tests\Mcp;

use App\Entity\ApiToken;
use App\Entity\Tenant;
use App\Entity\User;
use App\Mcp\McpPrompts;
use App\Mcp\McpServer;
use App\Mcp\McpSession;
use App\Mcp\McpToolException;
use App\Mcp\Tool\AbstractMcpTool;
use App\Service\AuditLogger;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Protocol layer of the assistant connection: JSON-RPC envelope, version
 * negotiation, tool listing by availability, tool errors as isError results,
 * audit entry per call without arguments.
 */
#[AllowMockObjectsWithoutExpectations]
final class McpServerTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $audited = [];

    #[Test]
    public function initializeNegotiatesTheProtocolVersion(): void
    {
        $server = $this->server([]);

        $known = $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']], $this->session());
        self::assertSame('2025-06-18', $known['result']['protocolVersion'] ?? null);
        self::assertSame(McpServer::SERVER_NAME, $known['result']['serverInfo']['name'] ?? null);
        self::assertArrayHasKey('tools', $known['result']['capabilities'] ?? []);

        $unknown = $server->handle(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'initialize', 'params' => ['protocolVersion' => '1999-01-01']], $this->session());
        self::assertSame(McpServer::PROTOCOL_VERSIONS[0], $unknown['result']['protocolVersion'] ?? null);
    }

    #[Test]
    public function notificationsGetNoAnswerAndUnknownMethodsAnError(): void
    {
        $server = $this->server([]);

        self::assertNull($server->handle(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $this->session()));
        self::assertSame(-32601, $server->handle(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'nope'], $this->session())['error']['code'] ?? null);
        self::assertSame(-32600, $server->handle(['id' => 4, 'method' => 'ping'], $this->session())['error']['code'] ?? null);
    }

    #[Test]
    public function toolsListShowsOnlyAvailableTools(): void
    {
        $server = $this->server([$this->tool('visible'), $this->tool('hidden', available: false)]);

        $list = $server->handle(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/list'], $this->session());
        $names = array_column($list['result']['tools'] ?? [], 'name');

        self::assertSame(['visible'], $names);
        self::assertTrue($list['result']['tools'][0]['annotations']['readOnlyHint']);
    }

    #[Test]
    public function toolCallReturnsTextAndStructuredContentAndAuditsWithoutArguments(): void
    {
        $server = $this->server([$this->tool('echo', result: ['answer' => 42, '_audit' => ['resource' => 'Risk', 'count' => 1]])]);

        $answer = $server->handle(['jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/call', 'params' => ['name' => 'echo', 'arguments' => ['secret' => 'personal data']]], $this->session());

        self::assertFalse($answer['result']['isError']);
        self::assertSame(['answer' => 42], $answer['result']['structuredContent']);
        self::assertStringContainsString('"answer": 42', $answer['result']['content'][0]['text']);
        self::assertCount(1, $this->audited);
        self::assertSame('mcp.read', $this->audited[0]['action']);
        self::assertSame(['tool' => 'echo', 'resource' => 'Risk', 'count' => 1], $this->audited[0]['newValues']);
        self::assertStringNotContainsString('personal data', (string) json_encode($this->audited));
    }

    #[Test]
    public function toolErrorsAreResultsAndUnknownToolsProtocolErrors(): void
    {
        $server = $this->server([$this->tool('broken', error: 'Not found in your organisation.'), $this->tool('hidden', available: false)]);

        $error = $server->handle(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => ['name' => 'broken']], $this->session());
        self::assertTrue($error['result']['isError']);
        self::assertSame('Not found in your organisation.', $error['result']['content'][0]['text']);

        foreach (['missing', 'hidden'] as $name) {
            $unknown = $server->handle(['jsonrpc' => '2.0', 'id' => 8, 'method' => 'tools/call', 'params' => ['name' => $name]], $this->session());
            self::assertSame(-32602, $unknown['error']['code'] ?? null, $name);
        }
    }

    #[Test]
    public function promptsAreListedAndRendered(): void
    {
        $server = $this->server([]);

        $list = $server->handle(['jsonrpc' => '2.0', 'id' => 9, 'method' => 'prompts/list'], $this->session());
        self::assertContains('audit_readiness', array_column($list['result']['prompts'] ?? [], 'name'));

        $prompt = $server->handle(['jsonrpc' => '2.0', 'id' => 10, 'method' => 'prompts/get', 'params' => ['name' => 'audit_readiness']], $this->session());
        self::assertSame('user', $prompt['result']['messages'][0]['role'] ?? null);
        self::assertStringContainsString('ISO27001', $prompt['result']['messages'][0]['content']['text'] ?? '');

        $unknown = $server->handle(['jsonrpc' => '2.0', 'id' => 11, 'method' => 'prompts/get', 'params' => ['name' => 'x']], $this->session());
        self::assertSame(-32602, $unknown['error']['code'] ?? null);
    }

    /** @param list<AbstractMcpTool> $tools */
    private function server(array $tools): McpServer
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $p = []): string => $id . ' ' . implode(' ', $p));
        $audit = $this->createMock(AuditLogger::class);
        $audit->method('logCustom')->willReturnCallback(function (string $action, string $entityType, ?int $entityId = null, ?array $oldValues = null, ?array $newValues = null, ?string $description = null): void {
            $this->audited[] = ['action' => $action, 'newValues' => $newValues];
        });

        return new McpServer($tools, new McpPrompts($translator), $audit, new NullLogger());
    }

    private function session(): McpSession
    {
        $tenant = (new Tenant())->setName('Org');
        $user = (new User())->setEmail('isb@example.test')->setTenant($tenant);

        return new McpSession($user, $tenant, (new ApiToken())->setUser($user)->setLabel('Laptop'), 'https://isms.example.test');
    }

    /** @param array<string, mixed> $result */
    private function tool(string $name, bool $available = true, array $result = [], ?string $error = null): AbstractMcpTool
    {
        return new class ($name, $available, $result, $error) extends AbstractMcpTool {
            /** @param array<string, mixed> $result */
            public function __construct(private string $toolName, private bool $available, private array $result, private ?string $error)
            {
            }

            public function name(): string
            {
                return $this->toolName;
            }

            public function description(): string
            {
                return 'test tool';
            }

            public function inputSchema(McpSession $session): array
            {
                return ['type' => 'object', 'properties' => new \stdClass()];
            }

            public function isAvailable(McpSession $session): bool
            {
                return $this->available;
            }

            public function call(array $arguments, McpSession $session): array
            {
                if ($this->error !== null) {
                    throw new McpToolException($this->error);
                }

                return $this->result;
            }
        };
    }
}
