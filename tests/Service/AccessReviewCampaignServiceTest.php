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
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see AccessReviewCampaignService::decide()}.
 *
 * All dependencies are mocked — no DB, no Kernel.
 * Focus: audit log is called, decision+metadata are persisted on the item.
 */
#[AllowMockObjectsWithoutExpectations]
class AccessReviewCampaignServiceTest extends TestCase
{
    private MockObject&EntityManagerInterface $em;
    private MockObject&AuditLogger $auditLogger;
    private MockObject&UserRepository $userRepository;
    private AccessReviewCampaignService $service;

    protected function setUp(): void
    {
        $this->em             = $this->createMock(EntityManagerInterface::class);
        $this->auditLogger    = $this->createMock(AuditLogger::class);
        $this->userRepository = $this->createMock(UserRepository::class);

        $this->service = new AccessReviewCampaignService(
            entityManager:  $this->em,
            auditLogger:    $this->auditLogger,
            userRepository: $this->userRepository,
        );
    }

    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function decide_approved_persists_decision_and_calls_audit_log(): void
    {
        $tenant   = $this->buildTenant(42);
        $campaign = $this->buildCampaign($tenant);
        $item     = $this->buildItem($campaign, $tenant);
        $reviewer = $this->buildUser('reviewer@example.com');

        // EM flush must be called (decision persisted)
        $this->em->expects($this->once())->method('flush');

        // AuditLogger::logCustom must be called with correct action and entityType
        $this->auditLogger
            ->expects($this->once())
            ->method('logCustom')
            ->with(
                $this->equalTo(AccessReviewCampaignService::ACTION_ACCESS_REVIEW_DECISION),
                $this->equalTo('AccessReviewItem'),
                $this->isNull(),          // item.id is null before DB flush in unit test
                $this->callback(fn(array $old): bool => ($old['decision'] ?? null) === 'pending'),
                $this->callback(fn(array $new): bool =>
                    ($new['decision'] ?? null) === 'approved'
                    && ($new['reviewer'] ?? null) === 'reviewer@example.com'
                    && ($new['reviewed_role'] ?? null) === 'ROLE_ADMIN'
                    && ($new['tenant_id'] ?? null) === 42
                ),
                $this->stringContains('approved'),
            );

        $this->service->decide($item, 'approved', $reviewer, 'looks good');

        $this->assertSame('approved', $item->getDecision());
        $this->assertSame($reviewer, $item->getDecidedBy());
        $this->assertSame('looks good', $item->getComment());
        $this->assertInstanceOf(DateTimeImmutable::class, $item->getDecidedAt());
    }

    #[Test]
    public function decide_revoked_writes_correct_audit_action(): void
    {
        $tenant   = $this->buildTenant(7);
        $campaign = $this->buildCampaign($tenant);
        $item     = $this->buildItem($campaign, $tenant);
        $reviewer = $this->buildUser('boss@example.com');

        $capturedNewValues = null;
        $this->auditLogger
            ->expects($this->once())
            ->method('logCustom')
            ->willReturnCallback(function (
                string $action,
                string $entityType,
                ?int $entityId,
                ?array $oldValues,
                ?array $newValues,
            ) use (&$capturedNewValues): void {
                $capturedNewValues = $newValues;
            });

        $this->em->expects($this->once())->method('flush');

        $this->service->decide($item, 'revoked', $reviewer, 'role no longer required');

        $this->assertSame('revoked', $item->getDecision());
        $this->assertNotNull($capturedNewValues);
        $this->assertSame('revoked', $capturedNewValues['decision']);
        $this->assertSame('ROLE_ADMIN', $capturedNewValues['reviewed_role']);
        $this->assertSame(7, $capturedNewValues['tenant_id']);
    }

    #[Test]
    public function decide_throws_when_campaign_is_closed(): void
    {
        $tenant   = $this->buildTenant(1);
        $campaign = $this->buildCampaign($tenant, closed: true);
        $item     = $this->buildItem($campaign, $tenant);
        $reviewer = $this->buildUser('reviewer@example.com');

        $this->em->expects($this->never())->method('flush');
        $this->auditLogger->expects($this->never())->method('logCustom');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/closed/i');

        $this->service->decide($item, 'approved', $reviewer);
    }

    #[Test]
    public function decide_escalated_records_correct_decision(): void
    {
        $tenant   = $this->buildTenant(3);
        $campaign = $this->buildCampaign($tenant);
        $item     = $this->buildItem($campaign, $tenant);
        $reviewer = $this->buildUser('ciso@example.com');

        $this->em->expects($this->once())->method('flush');
        $this->auditLogger->expects($this->once())->method('logCustom');

        $this->service->decide($item, 'escalated', $reviewer, null);

        $this->assertSame('escalated', $item->getDecision());
        $this->assertNull($item->getComment());
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
