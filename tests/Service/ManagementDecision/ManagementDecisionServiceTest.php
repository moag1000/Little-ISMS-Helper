<?php

declare(strict_types=1);

namespace App\Tests\Service\ManagementDecision;

use App\Entity\ManagementDecision;
use App\Entity\Tenant;
use App\Enum\ManagementDecisionStatus;
use App\Enum\ManagementDecisionType;
use App\Lifecycle\LifecycleTransitionInterface;
use App\Repository\ManagementDecisionRepository;
use App\Service\AuditLogger;
use App\Service\ManagementDecision\DecisionProposalBuilder;
use App\Service\ManagementDecision\ManagementDecisionService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
final class ManagementDecisionServiceTest extends TestCase
{
    private MockObject $repository;
    private MockObject $builder;
    private MockObject $lifecycle;
    private ManagementDecisionService $service;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(ManagementDecisionRepository::class);
        $this->builder = $this->createMock(DecisionProposalBuilder::class);
        $this->lifecycle = $this->createMock(LifecycleTransitionInterface::class);
        $this->tenant = new Tenant();
        $this->service = new ManagementDecisionService(
            $this->repository,
            $this->builder,
            $this->createMock(EntityManagerInterface::class),
            $this->lifecycle,
            $this->createMock(AuditLogger::class),
            new NullLogger(),
        );
        $this->builder->method('criteriaSnapshot')->willReturn(['automatic' => 3, 'manager' => 7, 'executive' => 25]);
    }

    #[Test]
    public function criteriaAreCoveredWhileTheApprovedThresholdsStillApply(): void
    {
        $approval = $this->approved(['automatic' => 3, 'manager' => 7, 'executive' => 25]);
        $this->repository->method('findApproved')->willReturn([$approval]);

        self::assertSame($approval, $this->service->criteriaApproval($this->tenant));
    }

    #[Test]
    public function changedThresholdsEndTheCoverage(): void
    {
        // ISO 27001 6.1.2 a: top management approved 4, the admin changed it to 3 since.
        $this->repository->method('findApproved')->willReturn([$this->approved(['automatic' => 4, 'manager' => 7, 'executive' => 25])]);

        self::assertNull($this->service->criteriaApproval($this->tenant));
    }

    #[Test]
    public function anExpiredApprovalDoesNotCover(): void
    {
        $approval = $this->approved(['automatic' => 3, 'manager' => 7, 'executive' => 25]);
        $approval->setValidUntil(new \DateTimeImmutable('-1 day'));
        $this->repository->method('findApproved')->willReturn([$approval]);

        self::assertNull($this->service->criteriaApproval($this->tenant));
    }

    #[Test]
    public function aRecordedApprovalSupersedesTheOlderOne(): void
    {
        $older = $this->approved(['automatic' => 3, 'manager' => 7, 'executive' => 25]);
        $this->repository->method('findApproved')->willReturn([$older]);
        $this->lifecycle->expects(self::once())->method('transition')
            ->with($older, 'management_decision_lifecycle', 'supersede');

        $decision = $this->service->record($this->tenant, ManagementDecisionType::Soa, 'SoA 2026', null, null);

        self::assertSame(ManagementDecisionStatus::Approved->value, $decision->getStatus());
        self::assertNotNull($decision->getValidUntil(), 'a SoA approval is valid for 12 months');
    }

    /** @param array<string, int> $snapshot */
    private function approved(array $snapshot): ManagementDecision
    {
        $decision = (new ManagementDecision())
            ->setTenant($this->tenant)
            ->setType(ManagementDecisionType::RiskCriteria)
            ->setTitle('Kriterien')
            ->setSubjectSnapshot($snapshot)
            ->setDecidedAt(new \DateTimeImmutable('-1 month'));
        $decision->setStatus(ManagementDecisionStatus::Approved->value);
        (new \ReflectionProperty(ManagementDecision::class, 'id'))->setValue($decision, 1);

        return $decision;
    }
}
