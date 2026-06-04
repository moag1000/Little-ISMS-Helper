<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AccessReviewCampaign;
use App\Entity\AccessReviewItem;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\UserRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Access Review Campaign Service — creates, drives, and closes UAR campaigns.
 *
 * ISO 27001 A.5.18 / A.8.2 — periodic recertification of access rights.
 * NIS2 Art. 21(2)(e) — identity and access management.
 *
 * ─── Three public entry points ────────────────────────────────────────────────
 *
 * 1. createCampaign()   — bulk-creates AccessReviewItem rows for all in-scope
 *                         user/role pairs; mirrors Training bulk-assign pattern.
 *                         Wires an SLA monitor on campaign.dueDate.
 *
 * 2. decide()           — records approve/revoke/escalate on one item + writes
 *                         an HMAC-chained AuditLog entry (ISO A.5.18 evidence).
 *
 * 3. close()            — stamps closedAt + transitions status to 'closed'.
 *
 * ─── Privileged scope ─────────────────────────────────────────────────────────
 *
 * scope=privileged → only users holding at least one of the elevated roles:
 * ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_MANAGER, ROLE_GROUP_CISO, ROLE_DPO,
 * ROLE_CISO, ROLE_RISK_MANAGER, ROLE_COMPLIANCE_MANAGER.
 */
final class AccessReviewCampaignService
{
    /** Roles considered "privileged" for the privileged-scope filter. */
    private const PRIVILEGED_ROLES = [
        'ROLE_SUPER_ADMIN',
        'ROLE_ADMIN',
        'ROLE_MANAGER',
        'ROLE_GROUP_CISO',
        'ROLE_DPO',
        'ROLE_CISO',
        'ROLE_RISK_MANAGER',
        'ROLE_COMPLIANCE_MANAGER',
    ];

    /** AuditLogger action constant for access-review decisions. */
    public const ACTION_ACCESS_REVIEW_DECISION = 'access_review.decision';

    /** AuditLogger action constant for campaign creation. */
    public const ACTION_ACCESS_REVIEW_CREATED  = 'access_review.campaign_created';

    /** AuditLogger action constant for campaign close. */
    public const ACTION_ACCESS_REVIEW_CLOSED   = 'access_review.campaign_closed';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger            $auditLogger,
        private readonly UserRepository         $userRepository,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // Public API
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Create a new campaign and bulk-generate one AccessReviewItem per
     * in-scope (user, role) pair. Mirrors the Training bulk-assign approach.
     *
     * @param string            $scope    'all_users' | 'privileged'
     * @param DateTimeInterface $dueDate  Campaign deadline
     * @param User              $creator  Authenticated user who launches the campaign
     * @param string            $name     Campaign name
     * @param Tenant            $tenant   Owning tenant
     */
    public function createCampaign(
        string $scope,
        DateTimeInterface $dueDate,
        User $creator,
        string $name,
        Tenant $tenant,
    ): AccessReviewCampaign {
        $campaign = new AccessReviewCampaign();
        $campaign->setTenant($tenant);
        $campaign->setName($name);
        $campaign->setScope($scope);
        $campaign->setDueDate($dueDate);
        $campaign->setCreatedBy($creator);

        $this->entityManager->persist($campaign);

        // Bulk-assign: one item per in-scope user/role pair ─────────────────
        $users = $this->resolveInScopeUsers($scope, $tenant);
        $itemCount = 0;

        foreach ($users as $user) {
            $roles = $this->resolveUserRolesForReview($user, $scope);
            foreach ($roles as $role) {
                $item = new AccessReviewItem();
                $item->setTenant($tenant);
                $item->setCampaign($campaign);
                $item->setSubjectUser($user);
                $item->setReviewedRole($role);
                $this->entityManager->persist($item);
                $itemCount++;
            }
        }

        $this->entityManager->flush();

        // SLA monitor on campaign.dueDate (reuses SlaDeadlineFactory pattern) ─
        $this->wireSlaMonitor($campaign);

        // Audit log — campaign creation event (ISO A.5.18) ───────────────────
        $this->auditLogger->logCustom(
            action:      self::ACTION_ACCESS_REVIEW_CREATED,
            entityType:  'AccessReviewCampaign',
            entityId:    $campaign->getId(),
            oldValues:   null,
            newValues:   [
                'name'       => $campaign->getName(),
                'scope'      => $scope,
                'due_date'   => $dueDate->format('Y-m-d'),
                'item_count' => $itemCount,
                'tenant_id'  => $tenant->getId(),
            ],
            description: sprintf(
                'UAR campaign "%s" created (%s, %d items)',
                $campaign->getName(),
                $scope,
                $itemCount,
            ),
        );

        return $campaign;
    }

    /**
     * Record a recertification decision on one item.
     *
     * This is the security-critical write path — EVERY decision writes an
     * HMAC-chained AuditLog entry.  Do NOT bypass or batch-skip audit here.
     *
     * @param string $decision  'approved' | 'revoked' | 'escalated'
     */
    public function decide(
        AccessReviewItem $item,
        string $decision,
        User $reviewer,
        ?string $comment = null,
    ): void {
        if (!$item->getCampaign()?->isOpen()) {
            throw new \LogicException('Cannot record a decision on a closed campaign.');
        }

        $oldDecision = $item->getDecision();

        $item->setDecision($decision);
        $item->setDecidedBy($reviewer);
        $item->setDecidedAt(new DateTimeImmutable());
        $item->setComment($comment);

        $this->entityManager->flush();

        // HMAC-chained audit entry — required by ISO 27001 A.5.18 ─────────
        $this->auditLogger->logCustom(
            action:      self::ACTION_ACCESS_REVIEW_DECISION,
            entityType:  'AccessReviewItem',
            entityId:    $item->getId(),
            oldValues:   [
                'decision' => $oldDecision,
            ],
            newValues:   [
                'decision'      => $decision,
                'reviewed_role' => $item->getReviewedRole(),
                'subject_user'  => $item->getSubjectUser()?->getEmail(),
                'reviewer'      => $reviewer->getEmail(),
                'comment'       => $comment,
                'campaign_id'   => $item->getCampaign()->getId(),
                'tenant_id'     => $item->getTenant()?->getId(),
            ],
            description: sprintf(
                'Access review decision: user %s role %s -> %s (by %s)',
                $item->getSubjectUser()?->getEmail() ?? '?',
                $item->getReviewedRole() ?? '?',
                $decision,
                $reviewer->getEmail(),
            ),
        );
    }

    /**
     * Close a campaign — stamps closedAt and transitions status.
     */
    public function close(AccessReviewCampaign $campaign, User $closer): void
    {
        if ($campaign->isClosed()) {
            return; // idempotent
        }

        $campaign->setStatus(AccessReviewCampaign::STATUS_CLOSED);
        $campaign->setClosedAt(new DateTimeImmutable());
        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            action:      self::ACTION_ACCESS_REVIEW_CLOSED,
            entityType:  'AccessReviewCampaign',
            entityId:    $campaign->getId(),
            oldValues:   ['status' => AccessReviewCampaign::STATUS_OPEN],
            newValues:   [
                'status'     => AccessReviewCampaign::STATUS_CLOSED,
                'closed_by'  => $closer->getEmail(),
                'closed_at'  => (new DateTimeImmutable())->format('c'),
                'tenant_id'  => $campaign->getTenant()?->getId(),
            ],
            description: sprintf(
                'UAR campaign "%s" closed by %s',
                $campaign->getName(),
                $closer->getEmail(),
            ),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Resolve active users in the given tenant matching the scope.
     *
     * @return User[]
     */
    private function resolveInScopeUsers(string $scope, Tenant $tenant): array
    {
        /** @var User[] $all */
        $all = $this->userRepository->findBy(['tenant' => $tenant, 'isActive' => true]);

        if ($scope === AccessReviewCampaign::SCOPE_PRIVILEGED) {
            return array_filter(
                $all,
                fn(User $u): bool => $this->hasAnyPrivilegedRole($u),
            );
        }

        return $all; // SCOPE_ALL_USERS
    }

    /**
     * Determine which roles to recertify for a given user + scope combination.
     *
     * For all_users: every role the user holds (via $user->getRoles()).
     * For privileged: only the privileged subset.
     *
     * ROLE_USER is always excluded — it is the baseline and not meaningful to review.
     *
     * @return list<string>
     */
    private function resolveUserRolesForReview(User $user, string $scope): array
    {
        $roles = array_filter(
            $user->getRoles(),
            fn(string $r): bool => $r !== 'ROLE_USER',
        );

        if ($scope === AccessReviewCampaign::SCOPE_PRIVILEGED) {
            $roles = array_filter(
                $roles,
                fn(string $r): bool => in_array($r, self::PRIVILEGED_ROLES, true),
            );
        }

        return array_values($roles);
    }

    private function hasAnyPrivilegedRole(User $user): bool
    {
        foreach ($user->getRoles() as $role) {
            if (in_array($role, self::PRIVILEGED_ROLES, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Wire an SLA deadline monitor on campaign.dueDate using the
     * SlaDeadlineFactory custom-deadline pattern (SlaDeadlineType::Custom).
     *
     * Checkpoints: 7d (168h), 3d (72h), 1d (24h) before the due date.
     */
    private function wireSlaMonitor(AccessReviewCampaign $campaign): void
    {
        $tenant  = $campaign->getTenant();
        $id      = $campaign->getId();
        $dueDate = $campaign->getDueDate();

        if ($tenant === null || $id === null || $dueDate === null) {
            return;
        }

        $triggeredAt = new DateTimeImmutable();
        $deadlineAt  = DateTimeImmutable::createFromInterface($dueDate)->setTime(23, 59, 59);

        $monitor = new \App\Entity\Notification\SlaDeadlineMonitor();
        $monitor->setTenant($tenant);
        $monitor->setEntityType('AccessReviewCampaign');
        $monitor->setEntityId($id);
        $monitor->setDeadlineType(\App\Enum\SlaDeadlineType::Custom);
        $monitor->setTriggeredAt($triggeredAt);
        $monitor->setDeadlineAt($deadlineAt);
        $monitor->setNotifyAtCheckpoints([168, 72, 24]); // 7d, 3d, 1d

        $this->entityManager->persist($monitor);
        $this->entityManager->flush();
    }
}
