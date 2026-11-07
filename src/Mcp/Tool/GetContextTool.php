<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Mcp\ApiResourceGateway;
use App\Mcp\McpSession;
use App\Security\Persona\Persona;
use App\Security\Persona\PersonaResolver;
use App\Service\ModuleConfigurationService;
use App\Service\Suggestion\SuggestionQueue;

/**
 * Start of every conversation: who calls, for which organisation, as which
 * persona, which modules are on and which record types list_records reads.
 */
final class GetContextTool extends AbstractMcpTool
{
    public function __construct(
        private readonly PersonaResolver $personas,
        private readonly ModuleConfigurationService $modules,
        private readonly ApiResourceGateway $gateway,
        private readonly SuggestionQueue $suggestions,
    ) {
    }

    public function name(): string
    {
        return 'get_context';
    }

    public function description(): string
    {
        return 'Who you act for: user, organisation, personas (ISB, risk manager, top management, …), active modules, '
            . 'the record resources list_records/get_record can read (with filter names) and the number of suggestions '
            . 'waiting for this person. Call this first.';
    }

    public function inputSchema(McpSession $session): array
    {
        return ['type' => 'object', 'properties' => new \stdClass()];
    }

    public function call(array $arguments, McpSession $session): array
    {
        $user = $session->user;

        return [
            'user' => ['name' => $user->getFullName(), 'email' => $user->getEmail()],
            'organisation' => ['id' => $session->tenant->getId(), 'name' => $session->tenant->getName()],
            'personas' => array_map(static fn (Persona $p): string => $p->value, $this->personas->personasOf($user)),
            'active_modules' => $this->modules->getActiveModulesForTenant($session->tenant),
            'resources' => $this->gateway->resources($session),
            'pending_suggestions' => count($this->suggestions->pendingFor($session->tenant)),
            'app_url' => $session->url('/'),
            'writing' => 'Records are changed only in the app. propose_draft adds a draft to the suggestion queue; a person accepts or rejects it there.',
        ];
    }
}
