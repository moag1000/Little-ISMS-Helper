<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\AuditFinding;
use App\Entity\InternalAudit;
use App\Entity\User;
use App\Security\Authority\ExplicitRole;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * S27 (ISO 27001 9.2.2, A.5.35): who does audit work — checklist verdicts,
 * the audit report, audit findings, verifying findings and corrective actions,
 * the document effectiveness review, SoA snapshots and approval steps with
 * `approver_role: ROLE_AUDITOR`.
 *
 * Granted to
 * - a person who reaches ROLE_AUDITOR through an auditor or manager role
 *   ({@see ExplicitRole::doesAuditWork()}) — never only through the read-only
 *   reviewer roles top management, group CISO and group auditor, which inherit
 *   ROLE_AUDITOR for reading;
 * - the lead auditor and audit team of the audit in question
 *   (`RECORD_RESPONSIBLE` of the {@see InternalAudit}, also for its findings).
 *
 * `#[IsGranted(AuditWorkVoter::AUDIT_WORK, subject: 'audit')]`; without a
 * subject only the role part applies. `LifecycleVoter` and
 * `WorkflowService::canUserApprove()` read a ROLE_AUDITOR requirement as this
 * attribute. Durable test: tests/Security/AuditWorkPathsTest.php.
 */
final class AuditWorkVoter extends Voter
{
    public const string AUDIT_WORK = 'AUDIT_WORK';

    public function __construct(
        private readonly ExplicitRole $explicitRole,
        private readonly AccessDecisionManagerInterface $accessDecisionManager,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::AUDIT_WORK;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || !$user->isActive()) {
            return false;
        }
        if ($this->explicitRole->doesAuditWork($token->getRoleNames())) {
            return true;
        }

        $audit = match (true) {
            $subject instanceof InternalAudit => $subject,
            $subject instanceof AuditFinding => $subject->getAudit(),
            default => null,
        };

        return $audit instanceof InternalAudit
            && $this->accessDecisionManager->decide($token, [RecordResponsibleVoter::RESPONSIBLE], $audit);
    }
}
