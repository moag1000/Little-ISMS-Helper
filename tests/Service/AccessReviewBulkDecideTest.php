<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AccessReviewCampaign;
use App\Entity\AccessReviewItem;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\AccessReviewCampaignService;
use App\Service\AuditLogger;
use App\Service\EmailNotificationService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see AccessReviewCampaignService::bulkDecide()}.
 *
 * Covers:
 *   - Loop iteration: all pending items get decided
 *   - Skip guard: already-decided items are not overwritten (idempotency)
 *   - Skip guard: items on a closed campaign are not decided
 *   - Return value when all items are skipped (no audit log)
 *   - Empty array input (no side-effects)
 *
 * All dependencies are mocked — no DB, no Kernel.
 */
#[AllowMockObjectsWithoutExpectations]
class AccessReviewBulkDecideTest extends TestCase
{
    private MockObject&EntityManagerInterface $em;
    private MockObject&AuditLogger $auditLogger;
    private MockObject&UserRepository $userRepository;
    private MockObject&EmailNotificationService $emailNotificationService;
    private AccessReviewCampaignService $service;

    protected function setUp(): void
    {
        $this->em                       = $this->createMock(EntityManagerInterface::class);
        $this->auditLogger              = $this->createMock(AuditLogger::class);
        $this->userRepository           = $this->createMock(UserRepository::class);
        $this->emailNotificationService = $this->createMock(EmailNotificationService::class);

        $this->service = new AccessReviewCampaignService(
            entityManager:            $this->em,
            auditLogger:              $this->auditLogger,
            userRepository:           $this->userRepository,
            emailNotificationService: $this->emailNotificationService,
        );
    }

    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function bulkDecide_applies_decision_to_all_pending_items(): void
    {
        $tenant   = $this->buildTenant(10);
        $campaign = $this->buildCampaign($tenant);
        $reviewer = $this->buildUser('reviewer@example.com');

        $item1 = $this->buildItem($campaign, $tenant);
        $item2 = $this->buildItem($campaign, $tenant);

        // flush called once per decide() call (2 items × 1 flush each)
        $this->em->expects($this->exactly(2))->method('flush');

        // logCustom: once per item (from decide()) + once batch summary
        $this->auditLogger->expects($this->exactly(3))->method('logCustom');

        $result = $this->service->bulkDecide([$item1, $item2], 'approved', $reviewer);

        $this->assertSame(2, $result['decided']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame('approved', $item1->getDecision());
        $this->assertSame('approved', $item2->getDecision());
        $this->assertSame($reviewer, $item1->getDecidedBy());
        $this->assertInstanceOf(DateTimeImmutable::class, $item1->getDecidedAt());
    }

    #[Test]
    public function bulkDecide_skips_already_decided_items_and_does_not_overwrite(): void
    {
        $tenant   = $this->buildTenant(11);
        $campaign = $this->buildCampaign($tenant);
        $reviewer = $this->buildUser('reviewer@example.com');

        $pendingItem  = $this->buildItem($campaign, $tenant);
        $decidedItem  = $this->buildItem($campaign, $tenant);
        $decidedItem->setDecision(AccessReviewItem::DECISION_APPROVED); // already decided

        // 1 flush (pendingItem's decide()) + 1 batch audit
        $this->em->expects($this->exactly(1))->method('flush');
        // 1 per-item logCustom + 1 batch logCustom
        $this->auditLogger->expects($this->exactly(2))->method('logCustom');

        $result = $this->service->bulkDecide([$pendingItem, $decidedItem], 'revoked', $reviewer);

        $this->assertSame(1, $result['decided']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame('revoked', $pendingItem->getDecision());
        $this->assertSame('approved', $decidedItem->getDecision(), 'Decided item must not be overwritten by bulk-decide');
    }

    #[Test]
    public function bulkDecide_skips_items_whose_campaign_is_closed(): void
    {
        $tenant         = $this->buildTenant(12);
        $openCampaign   = $this->buildCampaign($tenant);
        $closedCampaign = $this->buildCampaign($tenant, closed: true);
        $reviewer       = $this->buildUser('reviewer@example.com');

        $openItem   = $this->buildItem($openCampaign, $tenant);
        $closedItem = $this->buildItem($closedCampaign, $tenant);

        // 1 flush (openItem) + 1 batch audit
        $this->em->expects($this->exactly(1))->method('flush');
        $this->auditLogger->expects($this->exactly(2))->method('logCustom');

        $result = $this->service->bulkDecide([$openItem, $closedItem], 'revoked', $reviewer);

        $this->assertSame(1, $result['decided']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame('revoked', $openItem->getDecision());
        $this->assertSame('pending', $closedItem->getDecision(), 'Item on closed campaign must stay pending');
    }

    #[Test]
    public function bulkDecide_returns_zero_decided_and_no_audit_when_all_skipped(): void
    {
        $tenant   = $this->buildTenant(13);
        $campaign = $this->buildCampaign($tenant);
        $reviewer = $this->buildUser('reviewer@example.com');

        $alreadyApproved = $this->buildItem($campaign, $tenant);
        $alreadyApproved->setDecision(AccessReviewItem::DECISION_APPROVED);

        $this->em->expects($this->never())->method('flush');
        $this->auditLogger->expects($this->never())->method('logCustom');

        $result = $this->service->bulkDecide([$alreadyApproved], 'revoked', $reviewer);

        $this->assertSame(0, $result['decided']);
        $this->assertSame(1, $result['skipped']);
    }

    #[Test]
    public function bulkDecide_with_empty_array_returns_zero_zero_with_no_side_effects(): void
    {
        $reviewer = $this->buildUser('reviewer@example.com');

        $this->em->expects($this->never())->method('flush');
        $this->auditLogger->expects($this->never())->method('logCustom');

        $result = $this->service->bulkDecide([], 'approved', $reviewer);

        $this->assertSame(0, $result['decided']);
        $this->assertSame(0, $result['skipped']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function buildTenant(int $id): Tenant
    {
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn($id);
        return $tenant;
    }

    private function buildCampaign(Tenant $tenant, bool $closed = false): AccessReviewCampaign
    {
        $campaign = new AccessReviewCampaign();
        $campaign->setTenant($tenant);
        $campaign->setName('Test Campaign');
        $campaign->setDueDate(new \DateTime('+30 days'));
        if ($closed) {
            $campaign->setStatus(AccessReviewCampaign::STATUS_CLOSED);
        }
        return $campaign;
    }

    private function buildItem(AccessReviewCampaign $campaign, Tenant $tenant): AccessReviewItem
    {
        $subjectUser = $this->buildUser('subject@example.com');

        $item = new AccessReviewItem();
        $item->setTenant($tenant);
        $item->setCampaign($campaign);
        $item->setSubjectUser($subjectUser);
        $item->setReviewedRole('ROLE_ADMIN');
        return $item;
    }

    private function buildUser(string $email): User
    {
        $user = $this->createMock(User::class);
        $user->method('getEmail')->willReturn($email);
        $user->method('getRoles')->willReturn(['ROLE_USER', 'ROLE_ADMIN']);
        return $user;
    }
}
