<?php

declare(strict_types=1);

namespace App\Tests\Service\ComplianceWizard\Check\PolicyWizard;

use App\Entity\Document;
use App\Entity\Tenant;
use App\Entity\WorkflowInstance;
use App\Repository\DocumentRepository;
use App\Repository\WorkflowInstanceRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyApprovalChainCompletedCheck;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class PolicyApprovalChainCompletedCheckTest extends TestCase
{
    private DocumentRepository&MockObject $documentRepository;
    private WorkflowInstanceRepository&MockObject $workflowRepository;
    private PolicyApprovalChainCompletedCheck $check;

    protected function setUp(): void
    {
        $this->documentRepository = $this->createMock(DocumentRepository::class);
        $this->workflowRepository = $this->createMock(WorkflowInstanceRepository::class);
        $this->check = new PolicyApprovalChainCompletedCheck(
            $this->documentRepository,
            $this->workflowRepository,
        );
    }

    #[Test]
    public function passesWhenNoPoliciesYet(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $this->documentRepository->method('createQueryBuilder')->willReturn(
            $this->stubResultQueryBuilder([]),
        );

        $result = $this->check->run($tenant);

        self::assertTrue($result->passed);
        self::assertSame(100.0, $result->score);
        self::assertSame(0, $result->details['policies_total']);
    }

    #[Test]
    public function passesWhenEveryPolicyHasBothCisoAndTopMgmtSignoff(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $policy = $this->createMock(Document::class);
        $policy->method('getId')->willReturn(42);

        $this->documentRepository->method('createQueryBuilder')->willReturn(
            $this->stubResultQueryBuilder([$policy]),
        );

        $instance = $this->createMock(WorkflowInstance::class);
        $instance->method('getApprovalHistory')->willReturn([
            ['action' => 'approved', 'approver_role' => 'ROLE_CISO'],
            ['action' => 'approved', 'approver_role' => 'ROLE_TOP_MGMT'],
        ]);
        // Plain stub (no `with()` / `expects()`) — PHPUnit 14 deprecates
        // bare `with()` on stubs. The test asserts behaviour through the
        // returned PolicyWizardCheckResult below.
        $this->workflowRepository->method('findByEntity')->willReturn([$instance]);

        $result = $this->check->run($tenant);

        self::assertTrue($result->passed);
        self::assertSame(100.0, $result->score);
        self::assertSame(1, $result->details['policies_signed']);
    }

    #[Test]
    public function failsWhenAnyPolicyIsMissingARoleSignoff(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $policy = $this->createMock(Document::class);
        $policy->method('getId')->willReturn(7);
        $policy->method('getOriginalFilename')->willReturn('access-control-v1.pdf');

        $this->documentRepository->method('createQueryBuilder')->willReturn(
            $this->stubResultQueryBuilder([$policy]),
        );

        $instance = $this->createMock(WorkflowInstance::class);
        $instance->method('getApprovalHistory')->willReturn([
            // ROLE_TOP_MGMT signoff missing on purpose.
            ['action' => 'approved', 'approver_role' => 'ROLE_CISO'],
        ]);
        $this->workflowRepository->method('findByEntity')->willReturn([$instance]);

        $result = $this->check->run($tenant);

        self::assertFalse($result->passed);
        self::assertSame(0.0, $result->score);
        self::assertSame(1, $result->details['policies_unsigned']);
        self::assertNotNull($result->gap);
        self::assertSame('critical', $result->gap['priority']);
        self::assertSame(['ROLE_CISO', 'ROLE_TOP_MGMT'], $result->details['required_roles']);
    }

    /**
     * @param list<object> $documents
     */
    private function stubResultQueryBuilder(array $documents): QueryBuilder&MockObject
    {
        $query = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getResult'])
            ->getMock();
        $query->method('getResult')->willReturn($documents);

        $qb = $this->createMock(QueryBuilder::class);
        foreach (['select', 'innerJoin', 'where', 'andWhere', 'setParameter'] as $method) {
            $qb->method($method)->willReturnSelf();
        }
        $qb->method('getQuery')->willReturn($query);
        return $qb;
    }
}
