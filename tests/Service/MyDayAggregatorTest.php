<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AuditChecklist;
use App\Entity\Document;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\DocumentRepository;
use App\Repository\PolicyAcknowledgementRepository;
use App\Repository\UserRepository;
use App\Repository\WizardSessionRepository;
use App\Repository\WorkflowInstanceRepository;
use App\Service\ComplianceAnalyticsService;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\DeadlineSourceRegistry;
use App\Service\Deadline\ObligationInbox;
use App\Service\MyDayAggregator;
use App\Service\Responsibility\Responsibility;
use App\Service\Responsibility\ResponsibilityResolver;
use App\Service\TenantContext;
use App\Tests\Stub\FakeDeadlineSource;
use App\Tests\Stub\PersonaResolverFactory;
use DateTimeImmutable;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * My Day: deadline buckets come from the deadline sources (one inbox, each
 * duty at the person responsible — see ObligationInboxTest and
 * ResponsibilityResolverTest); acknowledgements, audit checklist and the
 * compliance manager's portfolio buckets are built by the aggregator.
 */
#[AllowMockObjectsWithoutExpectations]
final class MyDayAggregatorTest extends TestCase
{
    private MockObject $tenantContext;
    private MockObject $workflowInstances;
    private MockObject $policyAckRepo;
    private MockObject $documentRepo;
    private MockObject $wizardSessionRepo;
    private MockObject $complianceAnalytics;
    private MockObject $urls;
    /** @var list<FakeDeadlineSource> */
    private array $sources = [];
    /** @var list<User> */
    private array $users = [];

    protected function setUp(): void
    {
        $this->tenantContext = $this->createMock(TenantContext::class);
        $this->workflowInstances = $this->createMock(WorkflowInstanceRepository::class);
        $this->policyAckRepo = $this->createMock(PolicyAcknowledgementRepository::class);
        $this->documentRepo = $this->createMock(DocumentRepository::class);
        $this->wizardSessionRepo = $this->createMock(WizardSessionRepository::class);
        $this->complianceAnalytics = $this->createMock(ComplianceAnalyticsService::class);
        $this->urls = $this->createMock(UrlGeneratorInterface::class);
    }

    private function aggregator(): MyDayAggregator
    {
        $users = $this->createStub(UserRepository::class);
        $users->method('findActiveByTenant')->willReturn($this->users);
        $registry = new DeadlineSourceRegistry($this->sources);

        return new MyDayAggregator(
            $this->tenantContext,
            $this->workflowInstances,
            $this->policyAckRepo,
            $this->documentRepo,
            $this->wizardSessionRepo,
            $this->complianceAnalytics,
            $this->urls,
            PersonaResolverFactory::create(),
            new ObligationInbox($registry, new ResponsibilityResolver($users, PersonaResolverFactory::create(), $registry)),
        );
    }

    #[Test]
    public function testAggregateReturnsEmptyWithoutTenantContext(): void
    {
        $this->tenantContext->method('getCurrentTenant')->willReturn(null);
        $user = $this->createUser(11, null, ['ROLE_MANAGER', 'ROLE_USER']);

        $this->workflowInstances->expects($this->never())->method('findOverdueForTenant');
        $this->documentRepo->expects($this->never())->method('findPendingApprovalForTenant');

        $result = $this->aggregator()->aggregate($user);

        self::assertSame(0, $result['total']);
        self::assertSame([], $result['deadline_buckets']);
    }

    #[Test]
    public function deadlineBucketsComeFromTheSourcesAtTheResponsiblePerson(): void
    {
        $tenant = $this->createTenant(1);
        $owner = $this->createUser(11, $tenant, ['ROLE_USER']);
        $other = $this->createUser(12, $tenant, ['ROLE_RISK_MANAGER']);
        $this->users = [$owner, $other];
        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);
        $this->stubEmpty();
        $this->urls->method('generate')->willReturn('/risk/1');
        $this->sources = [new FakeDeadlineSource('risk_review', new DeadlineProfile('risk_review_overdue', normReference: 'ISO 27001 6.1.3'), [
            FakeDeadlineSource::record(1, Responsibility::holders($owner)->orRoles('ROLE_RISK_MANAGER'), new DateTimeImmutable('-3 days')),
            FakeDeadlineSource::record(2, Responsibility::holders($owner), new DateTimeImmutable('+3 days')),
            FakeDeadlineSource::record(3, Responsibility::holders($owner), new DateTimeImmutable('+12 days')),
        ])];

        $mine = $this->aggregator()->aggregate($owner);
        $theirs = $this->aggregator()->aggregate($other);

        self::assertSame(['risk_review_overdue'], $mine['deadline_buckets']);
        self::assertSame(3, $mine['summary']['risk_review_overdue']);
        self::assertSame(['danger', 'warning', 'info'], array_column($mine['risk_review_overdue'], 'tone'));
        self::assertSame(['overdue', 'due', null], array_column($mine['risk_review_overdue'], 'badge'));
        self::assertSame('ISO 27001 6.1.3', $mine['risk_review_overdue'][0]['subtitle']);
        self::assertSame('/risk/1', $mine['risk_review_overdue'][0]['link']);
        self::assertArrayNotHasKey('risk_review_overdue', $theirs, 'The risk manager only gets risks without an owner.');
    }

    #[Test]
    public function acknowledgementsAreBuiltHereNotFromTheSource(): void
    {
        $tenant = $this->createTenant(1);
        $user = $this->createUser(11, $tenant, ['ROLE_USER']);
        $this->users = [$user];
        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);
        $this->stubEmpty();
        $this->sources = [new FakeDeadlineSource('policy_ack', new DeadlineProfile('acknowledgements', leadDays: null), [
            FakeDeadlineSource::record(1, Responsibility::holders($user)),
        ])];

        $result = $this->aggregator()->aggregate($user);

        self::assertSame(0, $result['summary']['acknowledgements']);
        self::assertSame([], $result['deadline_buckets']);
    }

    #[Test]
    public function compliancePortfolioBucketsOnlyForComplianceManagers(): void
    {
        $tenant = $this->createTenant(1);
        $user = $this->createUser(22, $tenant, ['ROLE_USER']);
        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);
        $this->stubEmpty();
        $this->documentRepo->expects($this->never())->method('findPendingApprovalForTenant');

        $result = $this->aggregator()->aggregate($user);

        self::assertArrayNotHasKey('documents_pending_approval_for_me', $result['summary']);
    }

    #[Test]
    public function testDocumentsPendingApprovalSurfacesToComplianceManager(): void
    {
        $tenant = $this->createTenant(1);
        $cm = $this->createUser(11, $tenant, ['ROLE_COMPLIANCE_MANAGER', 'ROLE_USER']);
        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);
        $this->stubEmpty('findPendingApprovalForTenant');

        $doc = $this->createMock(Document::class);
        $doc->method('getId')->willReturn(42);
        $doc->method('getOriginalFilename')->willReturn('IT-Security-Policy-v3.pdf');
        $doc->method('getVersion')->willReturn('3');
        $this->documentRepo->expects($this->once())
            ->method('findPendingApprovalForTenant')
            ->with($tenant)
            ->willReturn([$doc]);
        $this->urls->method('generate')->willReturn('/document/42');

        $result = $this->aggregator()->aggregate($cm);

        self::assertSame(1, $result['summary']['documents_pending_approval_for_me']);
        self::assertSame('in_review', $result['documents_pending_approval_for_me'][0]['badge']);
    }

    #[Test]
    public function testWizardOverdueSurfacesToComplianceManager(): void
    {
        $tenant = $this->createTenant(1);
        $cm = $this->createUser(11, $tenant, ['ROLE_COMPLIANCE_MANAGER', 'ROLE_USER']);
        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);
        $this->stubEmpty('findOverdueByTenant');

        $session = $this->createMock(\App\Entity\WizardSession::class);
        $session->method('getId')->willReturn(5);
        $session->method('getWizardName')->willReturn('ISO 27001:2022');
        $session->method('getLastActivityAt')->willReturn(new DateTimeImmutable('-100 days'));
        $session->method('getUser')->willReturn($cm);
        $this->wizardSessionRepo->expects($this->once())
            ->method('findOverdueByTenant')
            ->with($tenant, 90)
            ->willReturn([$session]);
        $this->urls->method('generate')->willReturn('/compliance-wizard');

        $result = $this->aggregator()->aggregate($cm);

        self::assertSame(1, $result['summary']['wizard_overdue']);
        self::assertSame('overdue', $result['wizard_overdue'][0]['badge']);
    }

    #[Test]
    public function testFrameworkGapsCriticalSurfacesToComplianceManager(): void
    {
        $tenant = $this->createTenant(1);
        $cm = $this->createUser(11, $tenant, ['ROLE_COMPLIANCE_MANAGER', 'ROLE_USER']);
        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);
        $this->stubEmpty('findFrameworkGapsCritical');

        $this->complianceAnalytics->expects($this->once())
            ->method('findFrameworkGapsCritical')
            ->with(60.0)
            ->willReturn([
                ['id' => 3, 'name' => 'NIS2 Directive', 'code' => 'NIS2', 'compliance_percentage' => 45.0, 'mandatory' => true],
            ]);
        $this->urls->method('generate')->willReturn('/compliance');

        $result = $this->aggregator()->aggregate($cm);

        self::assertSame(1, $result['summary']['framework_gaps_critical']);
        self::assertSame('danger', $result['framework_gaps_critical'][0]['tone']);
        self::assertSame('mandatory', $result['framework_gaps_critical'][0]['badge']);
    }

    /** Every query returns nothing, except the one the test configures itself. */
    private function stubEmpty(string ...$except): void
    {
        $empty = [
            'findOverdueForTenant' => $this->workflowInstances,
            'findPendingApprovalForTenant' => $this->documentRepo,
            'findOverdueByTenant' => $this->wizardSessionRepo,
            'findFrameworkGapsCritical' => $this->complianceAnalytics,
        ];
        foreach ($empty as $method => $mock) {
            if (!in_array($method, $except, true)) {
                $mock->method($method)->willReturn([]);
            }
        }
        $this->stubEmptyDocumentQuery();
    }

    private function createTenant(int $id): Tenant
    {
        $tenant = new Tenant();
        $idProperty = (new \ReflectionClass($tenant))->getProperty('id');
        $idProperty->setValue($tenant, $id);
        return $tenant;
    }

    /** @param list<string> $roles */
    private function createUser(int $id, ?Tenant $tenant, array $roles): User
    {
        $user = new User();
        $idProperty = (new \ReflectionClass($user))->getProperty('id');
        $idProperty->setValue($user, $id);
        $user->setEmail('user' . $id . '@example.com');
        $user->setRoles($roles);
        if ($tenant !== null) {
            $user->setTenant($tenant);
        }
        return $user;
    }

    private function stubEmptyDocumentQuery(): void
    {
        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $query = $this->createMock(Query::class);
        $query->method('getResult')->willReturn([]);
        $qb->method('getQuery')->willReturn($query);
        $this->documentRepo->method('createQueryBuilder')->willReturn($qb);
    }
}
