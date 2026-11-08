<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Mcp\ApiResourceGateway;
use App\Mcp\McpSession;

/**
 * Generic list over every REST API resource (risks, controls incl. SoA
 * fields, incidents, assets, suppliers, register entries, …) through the
 * resource's own API operation — same rights, filters and fields as /api.
 */
final class ListRecordsTool extends AbstractMcpTool
{
    public function __construct(private readonly ApiResourceGateway $gateway)
    {
    }

    public function name(): string
    {
        return 'list_records';
    }

    public function description(): string
    {
        return 'List records of one resource of the organisation (e.g. Risk, Control incl. Statement of Applicability fields, '
            . 'Incident, Asset, Supplier, IsmsRegisterEntry). Same rights, filters and fields as the REST API. '
            . 'Use get_context for the resources and their filter names; filters are passed as query parameters, '
            . 'e.g. {"status": "open", "order[title]": "asc"}. Paged: up to 50 per page.';
    }

    public function inputSchema(McpSession $session): array
    {
        $names = array_keys(array_filter($this->gateway->resources($session), static fn (array $r): bool => $r['list']));

        return [
            'type' => 'object',
            'properties' => [
                'resource' => ['type' => 'string', 'enum' => $names, 'description' => 'Resource short name'],
                'filters' => ['type' => 'object', 'description' => 'Query parameters of the API collection (see get_context)', 'additionalProperties' => true],
                'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                'items_per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20],
            ],
            'required' => ['resource'],
        ];
    }

    public function call(array $arguments, McpSession $session): array
    {
        $resource = (string) self::string($arguments, 'resource', true, 100);
        $filters = is_array($arguments['filters'] ?? null) ? $arguments['filters'] : [];
        $result = $this->gateway->list(
            $session,
            $resource,
            $filters,
            (int) self::int($arguments, 'page', 1),
            (int) self::int($arguments, 'items_per_page', 20),
        );

        return $result + ['_audit' => ['resource' => $resource, 'count' => count($result['items'])]];
    }
}
