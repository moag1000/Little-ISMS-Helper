<?php

declare(strict_types=1);

namespace App\Attribute;

use Attribute;

/**
 * Records that describe people (data subjects, employees) beyond the name
 * the app shows everywhere — data minimisation for the assistant connection
 * (MCP), GDPR Art. 5 (1) c and 25: what travels to an external model is only
 * what the caller's task needs.
 *
 * Read by {@see \App\Mcp\PersonalDataPolicy} for every assistant path alike
 * (`list_records`, `get_record`, `save_record` answers via
 * {@see \App\Mcp\ApiResourceGateway}, and the `search` tool):
 *  - `assistant: false` — never offered to the assistant, whatever the role
 *    (badge and movement history, crypto operation log);
 *  - `fields: [...]` — the resource is offered, but these fields reach the
 *    assistant only for the data protection officer's role (ROLE_DPO);
 *  - `fields: []` (default) — the whole resource / search group reaches the
 *    assistant only for ROLE_DPO;
 *  - `searchGroup` — the group of the global search (SearchService) whose
 *    hits are this class's records.
 *
 * The web UI is not affected: there the voters and list roles decide.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class PersonalDataResource
{
    /**
     * @param list<string> $fields
     */
    public function __construct(
        public array $fields = [],
        public ?string $searchGroup = null,
        public bool $assistant = true,
    ) {
    }

    /** Only some fields are personal data; the rest of the record may travel. */
    public function isFieldLevel(): bool
    {
        return $this->fields !== [];
    }
}
