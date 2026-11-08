<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Mcp\McpSession;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One tool of the assistant connection (MCP). Few generic tools instead of
 * one per entity: a new API resource, deadline source or suggestion kind
 * shows up in the existing tools without a new class here.
 *
 * Tools read through the services the UI uses (same tenant scope, same
 * roles). Two tools write: save_record (API create/update operations with
 * the person's rights, nothing that needs a decision) and propose_draft
 * (a draft into the suggestion queue, where a person decides it).
 */
#[AutoconfigureTag('app.mcp_tool')]
interface McpTool
{
    /** Stable snake_case name the assistant calls. */
    public function name(): string;

    /** What the tool answers and when to use it (English, read by the model). */
    public function description(): string;

    /**
     * JSON schema of the arguments (type object).
     *
     * @return array<string, mixed>
     */
    public function inputSchema(McpSession $session): array;

    /** Read-only tools change nothing. */
    public function isReadOnly(): bool;

    /** Audit-log action of a call: mcp.read, mcp.propose or mcp.write. */
    public function auditAction(): string;

    /** May this caller use the tool at all (role, module)? Hidden from tools/list otherwise. */
    public function isAvailable(McpSession $session): bool;

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed> JSON-ready result; key '_audit' (optional, stripped) carries what the audit log records
     *
     * @throws \App\Mcp\McpToolException when the tool cannot answer
     */
    public function call(array $arguments, McpSession $session): array;
}
