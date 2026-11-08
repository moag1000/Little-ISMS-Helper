<?php

declare(strict_types=1);

namespace App\Mcp;

use App\EventSubscriber\ReadOnlyRoleSubscriber;

/**
 * May this assistant call write records directly (save_record)? All three
 * have to hold: the organisation allows writing (McpSettings, on by default
 * once MCP is on), the token was created with "Assistent darf schreiben",
 * and the person is not a read-only reviewer (auditor, group CISO, top
 * management without ROLE_MANAGER). Everything else the person's own rights
 * decide, in the API operation's security expression and voter.
 */
class McpWriteAccess
{
    public function __construct(
        private readonly McpSettings $settings,
        private readonly ReadOnlyRoleSubscriber $readOnlyRoles,
    ) {
    }

    public function allows(McpSession $session): bool
    {
        return $this->reasonDenied($session) === null;
    }

    /** Why writing is off for this call, in words for the assistant; null when it is allowed. */
    public function reasonDenied(McpSession $session): ?string
    {
        if (!$this->settings->isWriteEnabled($session->tenant)) {
            return 'Writing through the assistant is switched off for this organisation (Admin > Settings > AI).';
        }
        if (!$session->token->canWrite()) {
            return 'This API token is read-only. Create a token with "Assistant may write" under Profile > API tokens.';
        }
        if ($this->readOnlyRoles->isReadOnlyUser()) {
            return 'Your roles are read-only (reviewer role without manager rights).';
        }

        return null;
    }
}
