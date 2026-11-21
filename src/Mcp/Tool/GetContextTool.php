<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Mcp\ApiResourceGateway;
use App\Mcp\McpSession;
use App\Mcp\PersonalDataPolicy;
use App\Mcp\McpWriteAccess;
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
        private readonly McpWriteAccess $writeAccess,
        private readonly PersonalDataPolicy $personalData,
    ) {
    }

    public function name(): string
    {
        return 'get_context';
    }

    public function description(): string
    {
        return 'Who you act for: user, organisation, personas (ISB, risk manager, top management, …), active modules, '
            . 'the record resources list_records/get_record can read (with filter names, and whether save_record may create '
            . 'or update them), the record types of active modules that exist only in the app (only_in_the_app), whether you may write directly, and the number of suggestions waiting for this person. '
            . 'Call this first.';
    }

    public function inputSchema(McpSession $session): array
    {
        return ['type' => 'object', 'properties' => new \stdClass()];
    }

    /**
     * Record types of the active modules the assistant cannot read or write (no REST resource yet): an
     * assistant that finds nothing for "Prozess" or "Audit" can tell the person it is the connection, not the
     * data, that is missing (#1207).
     *
     * @param list<string> $offered
     * @return list<string>
     */
    private function onlyInTheApp(McpSession $session, array $offered): array
    {
        $missing = [];
        foreach ($this->modules->getActiveModulesForTenant($session->tenant) as $key) {
            foreach ((array) ($this->modules->getModule((string) $key)['entities'] ?? []) as $entity) {
                if (!in_array((string) $entity, $offered, true)) {
                    $missing[(string) $entity] = true;
                }
            }
        }
        $names = array_keys($missing);
        sort($names);

        return $names;
    }

    public function call(array $arguments, McpSession $session): array
    {
        $user = $session->user;
        $direct = $this->writeAccess->allows($session);
        $resources = $this->gateway->resources($session);

        return [
            'user' => ['name' => $user->getFullName(), 'email' => $user->getEmail()],
            'organisation' => ['id' => $session->tenant->getId(), 'name' => $session->tenant->getName()],
            'personas' => array_map(static fn (Persona $p): string => $p->value, $this->personas->personasOf($user)),
            'active_modules' => $this->modules->getActiveModulesForTenant($session->tenant),
            'resources' => $resources,
            'only_in_the_app' => $this->onlyInTheApp($session, array_keys($resources)),
            'pending_suggestions' => count($this->personalData->suggestions($this->suggestions->pendingFor($session->tenant), $session)),
            'app_url' => $session->url('/'),
            'writing' => [
                'direct' => $direct,
                'note' => $direct
                    ? 'save_record creates and changes records with your rights (resources with create/update = true). Status '
                        . 'changes, approvals, risk acceptance and management decisions are not written directly: propose_draft '
                        . 'adds a draft to the suggestion queue, a person accepts or rejects it there.'
                    : (string) $this->writeAccess->reasonDenied($session) . ' propose_draft still adds drafts to the suggestion '
                        . 'queue; a person accepts or rejects them there.',
            ],
        ];
    }
}
