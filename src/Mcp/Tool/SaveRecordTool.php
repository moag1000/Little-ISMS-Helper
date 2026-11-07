<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Mcp\ApiResourceGateway;
use App\Mcp\McpSession;
use App\Mcp\McpToolException;
use App\Mcp\McpWriteAccess;

/**
 * The one direct-writing tool: create or change a record of any REST
 * resource through its own API operation (Post, or Patch / Put on the
 * existing record) — the person's rights, the form's validation, the
 * tenant forced on create, the entity audit log. Nothing that needs a
 * decision: status, approvals, risk acceptance and management decisions
 * are not in the write groups and are refused here; they stay with
 * LifecycleService and the suggestion queue (propose_draft). No delete.
 * Only offered when McpWriteAccess allows it for the call.
 */
final class SaveRecordTool extends AbstractMcpTool
{
    public function __construct(
        private readonly ApiResourceGateway $gateway,
        private readonly McpWriteAccess $writeAccess,
    ) {
    }

    public function name(): string
    {
        return 'save_record';
    }

    public function description(): string
    {
        return 'Create a record (no id) or change fields of an existing one (with id) — e.g. a risk, asset, control, incident, '
            . 'supplier, training. Send only the fields to set in "data"; relations as IRI strings from get_record/list_records '
            . '(e.g. "/api/assets/5"). Validation errors come back as "field: message". Status, approvals and acceptance are '
            . 'not writable here (use propose_draft). Call with "data": {} to get the writable fields of a resource. '
            . 'Tell the person what you are about to save first.';
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function auditAction(): string
    {
        return 'mcp.write';
    }

    public function isAvailable(McpSession $session): bool
    {
        return $this->writeAccess->allows($session);
    }

    public function inputSchema(McpSession $session): array
    {
        $names = array_keys(array_filter($this->gateway->resources($session), static fn (array $r): bool => $r['create'] || $r['update']));

        return [
            'type' => 'object',
            'properties' => [
                'resource' => ['type' => 'string', 'enum' => $names],
                'id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Existing record to change; omit to create.'],
                'data' => ['type' => 'object', 'description' => 'Fields to set (API field names).'],
            ],
            'required' => ['resource', 'data'],
        ];
    }

    public function call(array $arguments, McpSession $session): array
    {
        $denied = $this->writeAccess->reasonDenied($session);
        if ($denied !== null) {
            throw new McpToolException($denied);
        }
        $resource = (string) self::string($arguments, 'resource', true, 100);
        $id = self::int($arguments, 'id');
        $data = $arguments['data'] ?? null;
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new McpToolException('Argument "data" must be an object of field names and values.');
        }
        if ($data === []) {
            return [
                'resource' => $resource,
                'writable_fields' => $this->gateway->writableFields($session, $resource),
                '_audit' => ['resource' => $resource, 'operation' => 'describe'],
            ];
        }

        $record = $this->gateway->write($session, $resource, $id, $data);
        $savedId = is_int($record['id'] ?? null) ? $record['id'] : $id;

        return [
            'resource' => $resource,
            'operation' => $id === null ? 'created' : 'updated',
            'record' => $record,
            '_audit' => [
                'resource' => $resource,
                'record_id' => $savedId,
                'operation' => $id === null ? 'create' : 'update',
                'fields' => implode(',', array_keys($data)),
            ],
        ];
    }
}
