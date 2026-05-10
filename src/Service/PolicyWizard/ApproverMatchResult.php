<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard;

/**
 * ApproverMatchResult — immutable DTO returned by
 * {@see TopicApproverRoleResolver::validateApproverForTopic()}.
 *
 * Captures the full reasoning of the match decision so callers can
 * either:
 *  - Render a UI badge (strict / weak / mismatch) per approver-row.
 *  - Emit an audit-event payload that carries the recommended-roles
 *    and approver-roles lists for later forensic reconstruction.
 *
 * Read-only via constructor-promoted readonly properties — the result
 * is allocated by the resolver and consumed by Voters / Audit-emitters
 * downstream; mutation would silently corrupt audit-history.
 */
final readonly class ApproverMatchResult
{
    /**
     * @param string                $state            One of TopicApproverRoleResolver::MATCH_*
     * @param string|null           $topicKey         The topic key that was checked, null when topic was missing.
     * @param list<string>          $recommendedRoles Roles fachlich-correct for $topicKey.
     * @param list<string>          $approverRoles    Symfony role-strings the approver actually carries.
     * @param list<string>          $matchedRoles     Intersection that produced the match (empty for mismatch).
     * @param string                $reason           Human-readable explanation for audit-trail.
     */
    public function __construct(
        public string $state,
        public ?string $topicKey,
        public array $recommendedRoles,
        public array $approverRoles,
        public array $matchedRoles,
        public string $reason,
    ) {
    }

    public function isStrictMatch(): bool
    {
        return $this->state === TopicApproverRoleResolver::MATCH_STRICT;
    }

    public function isWeakMatch(): bool
    {
        return $this->state === TopicApproverRoleResolver::MATCH_WEAK;
    }

    public function isMismatch(): bool
    {
        return $this->state === TopicApproverRoleResolver::MATCH_MISMATCH;
    }

    /**
     * Audit-event payload (associative, JSON-serialisable). Used by
     * {@see PolicySectionApprovalService::assertApproverRoleMatch()}
     * when emitting `policy_wizard.approver_role_match` /
     * `policy_wizard.approver_role_mismatch_warning` entries.
     *
     * @return array<string, mixed>
     */
    public function toAuditPayload(): array
    {
        return [
            'state'             => $this->state,
            'topic_key'         => $this->topicKey,
            'recommended_roles' => $this->recommendedRoles,
            'approver_roles'    => $this->approverRoles,
            'matched_roles'     => $this->matchedRoles,
            'reason'            => $this->reason,
        ];
    }
}
