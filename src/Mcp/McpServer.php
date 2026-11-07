<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Mcp\Tool\McpTool;
use App\Service\AuditLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The MCP protocol (JSON-RPC 2.0, stateless Streamable HTTP with JSON
 * answers): initialize, ping, tools/list, tools/call, prompts/list,
 * prompts/get. No session id, no server-sent events — every POST carries
 * the bearer token and is answered on its own, which fits PHP-FPM and
 * shared hosting without a worker.
 *
 * Every tool call is audit-logged (tool, resource, record id, result
 * count; never arguments or answer text, which may carry personal data).
 */
class McpServer
{
    public const string SERVER_NAME = 'little-isms-helper';

    /** Newest first; an unknown client version gets the newest. */
    public const array PROTOCOL_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

    /** @var array<string, McpTool> */
    private array $tools = [];

    /**
     * @param iterable<McpTool> $tools
     */
    public function __construct(
        #[AutowireIterator('app.mcp_tool')] iterable $tools,
        private readonly McpPrompts $prompts,
        private readonly AuditLogger $auditLogger,
        private readonly LoggerInterface $logger,
    ) {
        foreach ($tools as $tool) {
            $this->tools[$tool->name()] = $tool;
        }
    }

    /**
     * Answers one JSON-RPC message; null for notifications (no answer).
     *
     * @param array<mixed> $message
     * @return array<string, mixed>|null
     */
    public function handle(array $message, McpSession $session): ?array
    {
        $id = $message['id'] ?? null;
        $method = $message['method'] ?? null;
        $isNotification = !array_key_exists('id', $message);
        if (($message['jsonrpc'] ?? null) !== '2.0' || !is_string($method)) {
            return $isNotification ? null : self::error($id, -32600, 'Invalid request');
        }
        if ($isNotification) {
            return null; // notifications/initialized, notifications/cancelled, …
        }
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        try {
            $result = match ($method) {
                'initialize' => $this->initialize($params, $session),
                'ping' => new \stdClass(),
                'tools/list' => ['tools' => $this->listTools($session)],
                'tools/call' => $this->callTool($params, $session),
                'prompts/list' => ['prompts' => $this->prompts->list($session)],
                'prompts/get' => $this->prompts->get((string) ($params['name'] ?? ''), (array) ($params['arguments'] ?? []), $session),
                'resources/list' => ['resources' => []],
                'resources/templates/list' => ['resourceTemplates' => []],
                default => null,
            };
        } catch (McpProtocolException $e) {
            return self::error($id, $e->getCode(), $e->getMessage());
        }

        return $result === null
            ? self::error($id, -32601, 'Method not found: ' . $method)
            : ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /** @return array<string, McpTool> */
    public function tools(): array
    {
        return $this->tools;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function initialize(array $params, McpSession $session): array
    {
        $requested = (string) ($params['protocolVersion'] ?? '');

        return [
            'protocolVersion' => in_array($requested, self::PROTOCOL_VERSIONS, true) ? $requested : self::PROTOCOL_VERSIONS[0],
            'capabilities' => [
                'tools' => ['listChanged' => false],
                'prompts' => ['listChanged' => false],
            ],
            'serverInfo' => [
                'name' => self::SERVER_NAME,
                'title' => 'Little ISMS Helper',
                'version' => (string) (\Composer\InstalledVersions::getRootPackage()['pretty_version'] ?? 'dev'),
            ],
            'instructions' => $this->prompts->instructions($session),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function listTools(McpSession $session): array
    {
        $list = [];
        foreach ($this->tools as $tool) {
            if (!$tool->isAvailable($session)) {
                continue;
            }
            $list[] = [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'inputSchema' => $tool->inputSchema($session),
                'annotations' => [
                    'readOnlyHint' => $tool->isReadOnly(),
                    'destructiveHint' => false,
                    'idempotentHint' => $tool->isReadOnly(),
                    'openWorldHint' => false,
                ],
            ];
        }

        return $list;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function callTool(array $params, McpSession $session): array
    {
        $name = (string) ($params['name'] ?? '');
        $tool = $this->tools[$name] ?? null;
        if (!$tool instanceof McpTool || !$tool->isAvailable($session)) {
            throw new McpProtocolException('Unknown tool: ' . $name, -32602);
        }
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        try {
            $result = $tool->call($arguments, $session);
        } catch (McpToolException $e) {
            $this->audit($tool, $session, ['error' => true]);

            return ['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true];
        } catch (\Throwable $e) {
            $this->logger->error('MCP tool failed', ['tool' => $name, 'exception' => $e]);
            $this->audit($tool, $session, ['error' => true]);

            return ['content' => [['type' => 'text', 'text' => 'The tool failed. Details are in the server log.']], 'isError' => true];
        }

        $audit = is_array($result['_audit'] ?? null) ? $result['_audit'] : [];
        unset($result['_audit']);
        $this->audit($tool, $session, $audit);

        return [
            'content' => [['type' => 'text', 'text' => (string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)]],
            'structuredContent' => $result === [] ? new \stdClass() : $result,
            'isError' => false,
        ];
    }

    /**
     * @param array<string, mixed> $details
     */
    private function audit(McpTool $tool, McpSession $session, array $details): void
    {
        $this->auditLogger->logCustom(
            action: $tool->isReadOnly() ? 'mcp.read' : 'mcp.propose',
            entityType: 'ApiToken',
            entityId: $session->token->getId(),
            newValues: ['tool' => $tool->name()] + array_filter($details, static fn ($v): bool => is_scalar($v) || $v === null),
            description: sprintf('Assistant (MCP) used "%s" with token "%s"', $tool->name(), $session->token->getLabel()),
        );
    }

    /** @return array<string, mixed> */
    private static function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
