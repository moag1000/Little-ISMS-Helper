<?php

declare(strict_types=1);

namespace App\Service\Sso;

use App\Entity\IdentityProvider;
use App\Entity\User;
use App\Service\AuditLogger;

/**
 * Wrapper around AuditLogger that emits structured SSO-specific audit events.
 *
 * All 6 event types flow through AuditLogger::logCustom, which calls
 * AuditLogIntegrityService::sign() maintaining the HMAC chain.
 *
 * Do NOT mark final — tests mock this service.
 */
class SsoEventLogger
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {
    }

    public function logLoginSuccess(IdentityProvider $provider, string $email): void
    {
        $this->audit->logCustom(
            AuditLogger::ACTION_SSO_LOGIN_SUCCESS,
            'IdentityProvider',
            $provider->getId(),
            null,
            ['email' => $email, 'provider_slug' => $provider->getSlug()],
            sprintf('SSO login success: %s via %s', $email, $provider->getSlug()),
        );
    }

    public function logLoginFailure(IdentityProvider $provider, string $email, string $reason): void
    {
        $this->audit->logCustom(
            AuditLogger::ACTION_SSO_LOGIN_FAILURE,
            'IdentityProvider',
            $provider->getId(),
            null,
            ['email' => $email, 'reason' => $reason, 'provider_slug' => $provider->getSlug()],
            sprintf('SSO login failure: %s via %s — %s', $email, $provider->getSlug(), $reason),
        );
    }

    public function logJitProvisioned(IdentityProvider $provider, User $user, string $email): void
    {
        $this->audit->logCustom(
            AuditLogger::ACTION_SSO_JIT_PROVISIONED,
            'IdentityProvider',
            $provider->getId(),
            null,
            ['email' => $email, 'user_id' => $user->getId(), 'provider_slug' => $provider->getSlug()],
            sprintf('SSO JIT provisioned: %s via %s', $email, $provider->getSlug()),
        );
    }

    public function logRoleChanged(IdentityProvider $provider, User $user, string $oldRole, string $newRole): void
    {
        $this->audit->logCustom(
            AuditLogger::ACTION_SSO_ROLE_CHANGED,
            'IdentityProvider',
            $provider->getId(),
            ['old_role' => $oldRole, 'user_id' => $user->getId()],
            ['new_role' => $newRole, 'user_id' => $user->getId()],
            sprintf('SSO role changed for user %d: %s → %s', (int) $user->getId(), $oldRole, $newRole),
        );
    }

    public function logConfigChanged(IdentityProvider $provider, string $field, mixed $old, mixed $new): void
    {
        $this->audit->logCustom(
            AuditLogger::ACTION_SSO_CONFIG_CHANGED,
            'IdentityProvider',
            $provider->getId(),
            ['field' => $field, 'old_value' => $old],
            ['field' => $field, 'new_value' => $new],
            sprintf('SSO config changed for provider "%s": field %s', $provider->getSlug(), $field),
        );
    }

    public function logEnforcementChanged(IdentityProvider $provider, bool $old, bool $new): void
    {
        $this->audit->logCustom(
            AuditLogger::ACTION_SSO_ENFORCEMENT_CHANGED,
            'IdentityProvider',
            $provider->getId(),
            ['sso_enforced_old' => $old],
            ['sso_enforced_new' => $new],
            sprintf(
                'SSO enforcement changed for provider "%s": %s → %s',
                $provider->getSlug(),
                $old ? 'on' : 'off',
                $new ? 'on' : 'off',
            ),
        );
    }
}
