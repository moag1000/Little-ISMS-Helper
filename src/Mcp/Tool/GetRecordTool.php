<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Mcp\ApiResourceGateway;
use App\Mcp\McpSession;

/**
 * One record of a REST API resource through its own Get operation (voter
 * `view` on the record, tenant filter, API fields).
 */
final class GetRecordTool extends AbstractMcpTool
{
    public function __construct(private readonly ApiResourceGateway $gateway)
    {
    }

    public function name(): string
    {
        return 'get_record';
    }

    public function description(): string
    {
        return 'Read one record of a resource by id (e.g. Risk 12) with all fields the REST API shows. '
            . 'Ids come from list_records, search or my_day.';
    }

    public function inputSchema(McpSession $session): array
    {
        $names = array_keys(array_filter($this->gateway->resources($session), static fn (array $r): bool => $r['get']));

        return [
            'type' => 'object',
            'properties' => [
                'resource' => ['type' => 'string', 'enum' => $names],
                'id' => ['type' => 'integer', 'minimum' => 1],
            ],
            'required' => ['resource', 'id'],
        ];
    }

    public function call(array $arguments, McpSession $session): array
    {
        $resource = (string) self::string($arguments, 'resource', true, 100);
        $id = (int) self::int($arguments, 'id', required: true);

        return ['resource' => $resource, 'record' => $this->gateway->get($session, $resource, $id)]
            + ['_audit' => ['resource' => $resource, 'record_id' => $id]];
    }
}
