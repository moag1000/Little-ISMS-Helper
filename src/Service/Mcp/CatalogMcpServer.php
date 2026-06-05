<?php

declare(strict_types=1);

namespace App\Service\Mcp;

use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;

/**
 * F21 — minimal Model Context Protocol (MCP) server exposing READ-ONLY
 * compliance-catalogue queries to an LLM client.
 *
 * Implements the JSON-RPC 2.0 method surface an MCP host needs (`initialize`,
 * `tools/list`, `tools/call`). Transport-agnostic: {@see handle()} takes a
 * decoded request array and returns a response array; the stdio loop lives in
 * {@see \App\Command\McpServerCommand}. Read-only by construction — no tool
 * mutates data.
 */
final class CatalogMcpServer
{
    public const string PROTOCOL_VERSION = '2024-11-05';

    public function __construct(
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly ComplianceRequirementRepository $requirementRepository,
    ) {
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>|null Null for notifications (no id).
     */
    public function handle(array $request): ?array
    {
        $id = $request['id'] ?? null;
        $method = is_string($request['method'] ?? null) ? $request['method'] : '';
        $params = is_array($request['params'] ?? null) ? $request['params'] : [];

        // Notifications (no id) get no response.
        if ($id === null && str_starts_with($method, 'notifications/')) {
            return null;
        }

        try {
            $result = match ($method) {
                'initialize' => $this->initialize(),
                'tools/list' => $this->toolsList(),
                'tools/call' => $this->toolsCall($params),
                'ping'       => new \stdClass(),
                default      => throw new \App\Exception\InvalidArgument\InvalidArgumentException('Method not found: ' . $method),
            };
        } catch (\Throwable $e) {
            return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => $e->getMessage()]];
        }

        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /**
     * @return array<string, mixed>
     */
    private function initialize(): array
    {
        return [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities'    => ['tools' => new \stdClass()],
            'serverInfo'      => ['name' => 'little-isms-helper-catalog', 'version' => '1.0'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function toolsList(): array
    {
        return [
            'tools' => [
                [
                    'name'        => 'list_frameworks',
                    'description' => 'List the active compliance frameworks (code + name).',
                    'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
                ],
                [
                    'name'        => 'list_requirements',
                    'description' => 'List requirement ids + titles for a framework code (e.g. "EUCS", "EU-AI-ACT").',
                    'inputSchema' => [
                        'type'       => 'object',
                        'properties' => ['framework_code' => ['type' => 'string']],
                        'required'   => ['framework_code'],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function toolsCall(array $params): array
    {
        $name = is_string($params['name'] ?? null) ? $params['name'] : '';
        $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        $text = match ($name) {
            'list_frameworks'   => $this->listFrameworks(),
            'list_requirements' => $this->listRequirements(is_string($args['framework_code'] ?? null) ? $args['framework_code'] : ''),
            default             => throw new \App\Exception\InvalidArgument\InvalidArgumentException('Unknown tool: ' . $name),
        };

        return ['content' => [['type' => 'text', 'text' => $text]]];
    }

    private function listFrameworks(): string
    {
        $lines = [];
        foreach ($this->frameworkRepository->findActiveFrameworks() as $fw) {
            $lines[] = sprintf('%s — %s', (string) $fw->getCode(), (string) $fw->getName());
        }

        return $lines === [] ? '(no active frameworks)' : implode("\n", $lines);
    }

    private function listRequirements(string $frameworkCode): string
    {
        if ($frameworkCode === '') {
            return 'Error: framework_code is required.';
        }

        $framework = $this->frameworkRepository->findOneBy(['code' => $frameworkCode]);
        if ($framework === null) {
            return sprintf('No framework with code "%s".', $frameworkCode);
        }

        $lines = [];
        foreach ($this->requirementRepository->findByFramework($framework) as $req) {
            $lines[] = sprintf('%s — %s', (string) $req->getRequirementId(), (string) $req->getTitle());
        }

        return $lines === [] ? '(no requirements)' : implode("\n", $lines);
    }
}
