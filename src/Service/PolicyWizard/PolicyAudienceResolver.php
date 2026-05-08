<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard;

use App\Entity\Document;
use App\Entity\PolicyTemplate;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\UserRepository;

/**
 * Audience resolver for {@see Document} → list of {@see User} that must
 * acknowledge the policy (W3-L, closes auditor's predicted A.6.3 NC).
 *
 * Strategy (priority order):
 *   1. Explicit override carried by the Document itself in
 *      `Document.substitutionVariables._audience` — accepts a list of
 *      User IDs OR a list of role keys (`ROLE_*`). Lists may mix both.
 *   2. Topic-key heuristic via the `PolicyTemplate.topic` of the
 *      generating template (see {@see TOPIC_AUDIENCE_MAP}). This drives
 *      the by-design fallback for documents produced by the Policy-Wizard.
 *   3. Final fallback: every active user in the document's tenant.
 *
 * The mapping is intentionally simple — the audit-defensible position is
 * "everyone with an active user account, unless the topic is provably
 * narrower" (e.g. Cryptography → IT-Operations only). Tenants that need
 * granular per-document scoping should populate the `_audience` override.
 *
 * Spec reference: ISO 27001 Cl. 7.3 + ISO 27002 §6.3 awareness training.
 */
final class PolicyAudienceResolver
{
    /**
     * Topic → list of role keys mapping. A document whose generating
     * template carries a topic in this map is scoped to users holding
     * any of the listed roles. Topics not listed default to "everyone".
     *
     * Topics map to PolicyTemplate.topic (e.g. `cryptography`, `hr_security`,
     * `acceptable_use`). The values are RBAC role names matched via
     * {@see User::getRoles()}.
     *
     * @var array<string, list<string>>
     */
    private const TOPIC_AUDIENCE_MAP = [
        // Narrow audiences — only role-holders need to acknowledge.
        'cryptography'              => ['ROLE_IT_OPERATIONS', 'ROLE_ADMIN'],
        'logging'                   => ['ROLE_IT_OPERATIONS', 'ROLE_ADMIN'],
        'patch_management'          => ['ROLE_IT_OPERATIONS', 'ROLE_ADMIN'],
        'secure_configuration'      => ['ROLE_IT_OPERATIONS', 'ROLE_ADMIN'],
        'network_security'          => ['ROLE_IT_OPERATIONS', 'ROLE_ADMIN'],
        'secure_development'        => ['ROLE_IT_OPERATIONS', 'ROLE_DEVELOPER'],
        'privacy_pii'               => ['ROLE_DPO'],
        // Everything else (acceptable_use, hr_security, information_classification,
        // information_transfer, identity_management, authentication_information,
        // backup, malware, supplier_relationships, project_management,
        // incident_management, continuity, threat_intelligence, mobile_device,
        // asset_management, physical_security, top_level …) → all active users.
    ];

    public function __construct(
        private readonly UserRepository $userRepository,
    ) {
    }

    /**
     * @return list<User>
     */
    public function resolveAudience(Document $document): array
    {
        $tenant = $document->getTenant();
        if (!$tenant instanceof Tenant) {
            return [];
        }

        // Strategy 1 — explicit override on the Document itself.
        $override = $this->extractAudienceOverride($document);
        if ($override !== null) {
            return $this->resolveOverride($override, $tenant);
        }

        // Strategy 2 — topic-based heuristic.
        $template = $document->getGeneratedFromTemplate();
        if ($template instanceof PolicyTemplate) {
            $topic = $template->getTopic();
            if ($topic !== null && isset(self::TOPIC_AUDIENCE_MAP[$topic])) {
                return $this->filterByTenantAndRoles($tenant, self::TOPIC_AUDIENCE_MAP[$topic]);
            }
        }

        // Strategy 3 — fallback: every active user in the tenant.
        return $this->filterByTenant($tenant);
    }

    /**
     * @return list<int|string>|null
     */
    private function extractAudienceOverride(Document $document): ?array
    {
        $vars = $document->getSubstitutionVariables();
        if (!is_array($vars) || !isset($vars['_audience'])) {
            return null;
        }
        $audience = $vars['_audience'];
        if (!is_array($audience) || $audience === []) {
            return null;
        }

        $clean = [];
        foreach ($audience as $entry) {
            if (is_int($entry) || (is_string($entry) && $entry !== '')) {
                $clean[] = $entry;
            }
        }
        return $clean === [] ? null : $clean;
    }

    /**
     * @param list<int|string> $override Mix of User IDs (int) and ROLE_* keys (string).
     * @return list<User>
     */
    private function resolveOverride(array $override, Tenant $tenant): array
    {
        $userIds = [];
        $roles = [];
        foreach ($override as $entry) {
            if (is_int($entry)) {
                $userIds[] = $entry;
            } elseif (is_string($entry) && str_starts_with($entry, 'ROLE_')) {
                $roles[] = $entry;
            }
        }

        $resolved = [];
        $seen = [];

        // Direct ID lookup.
        foreach ($userIds as $id) {
            $user = $this->userRepository->find($id);
            if ($user instanceof User
                && $user->isActive()
                && $this->userBelongsToTenant($user, $tenant)
                && !isset($seen[$user->getId()])
            ) {
                $resolved[] = $user;
                $seen[$user->getId()] = true;
            }
        }

        // Role-resolved users — only those of the document's tenant.
        if ($roles !== []) {
            foreach ($this->filterByTenantAndRoles($tenant, $roles) as $user) {
                if (!isset($seen[$user->getId()])) {
                    $resolved[] = $user;
                    $seen[$user->getId()] = true;
                }
            }
        }

        return $resolved;
    }

    /**
     * @return list<User>
     */
    private function filterByTenant(Tenant $tenant): array
    {
        $users = $this->userRepository->findActiveUsers();
        $result = [];
        foreach ($users as $user) {
            if ($user instanceof User && $this->userBelongsToTenant($user, $tenant)) {
                $result[] = $user;
            }
        }
        return $result;
    }

    /**
     * @param list<string> $roles
     * @return list<User>
     */
    private function filterByTenantAndRoles(Tenant $tenant, array $roles): array
    {
        $rolesLookup = array_fill_keys($roles, true);
        $result = [];
        foreach ($this->filterByTenant($tenant) as $user) {
            foreach ($user->getRoles() as $userRole) {
                if (isset($rolesLookup[$userRole])) {
                    $result[] = $user;
                    break;
                }
            }
        }
        return $result;
    }

    private function userBelongsToTenant(User $user, Tenant $tenant): bool
    {
        $userTenant = $user->getTenant();
        if (!$userTenant instanceof Tenant) {
            return false;
        }
        return $userTenant->getId() === $tenant->getId();
    }
}
