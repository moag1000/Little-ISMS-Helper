<?php

declare(strict_types=1);

namespace App\Tests\Service\ComplianceWizard\Check\PolicyWizard\Privacy;

use App\Entity\IncidentSlaConfig;
use App\Entity\Tenant;
use App\Repository\DocumentRepository;
use App\Repository\IncidentSlaConfigRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\Privacy\DataBreachNotification72hCheck;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class DataBreachNotification72hCheckTest extends TestCase
{
    private DocumentRepository&MockObject $documentRepository;
    private IncidentSlaConfigRepository&MockObject $slaRepository;
    private DataBreachNotification72hCheck $check;

    protected function setUp(): void
    {
        $this->documentRepository = $this->createMock(DocumentRepository::class);
        $this->slaRepository = $this->createMock(IncidentSlaConfigRepository::class);
        $this->check = new DataBreachNotification72hCheck(
            $this->documentRepository,
            $this->slaRepository,
        );
    }

    #[Test]
    public function testPassesWhenProcedurePresentAndSlaWithin72h(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $this->documentRepository->method('createQueryBuilder')
            ->willReturn($this->stubScalarQueryBuilder(1));

        $sla = (new IncidentSlaConfig())
            ->setSeverity(IncidentSlaConfig::SEVERITY_BREACH)
            ->setResponseHours(48);
        $this->slaRepository->method('findByTenantAndSeverity')->willReturn($sla);

        $result = $this->check->run($tenant);

        self::assertTrue($result->passed);
        self::assertSame(100.0, $result->score);
        self::assertSame(1, $result->details['procedure_documents']);
        self::assertSame(48, $result->details['breach_sla_hours']);
        self::assertNull($result->gap);
        self::assertSame('gdpr', $this->check->getStandard());
    }

    #[Test]
    public function testFailsWhenSlaExceedsCeiling(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $this->documentRepository->method('createQueryBuilder')
            ->willReturn($this->stubScalarQueryBuilder(1));

        $sla = (new IncidentSlaConfig())
            ->setSeverity(IncidentSlaConfig::SEVERITY_BREACH)
            ->setResponseHours(96);
        $this->slaRepository->method('findByTenantAndSeverity')->willReturn($sla);

        $result = $this->check->run($tenant);

        self::assertFalse($result->passed);
        self::assertSame(0.0, $result->score);
        self::assertNotNull($result->gap);
        self::assertSame('critical', $result->gap['priority']);
        self::assertSame('sla_exceeds_gdpr_72h_ceiling', $result->details['violations'][0]['reason']);
    }

    #[Test]
    public function testGapActionableWhenProcedureMissingAndSlaMissing(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $this->documentRepository->method('createQueryBuilder')
            ->willReturn($this->stubScalarQueryBuilder(0));
        $this->slaRepository->method('findByTenantAndSeverity')->willReturn(null);

        $result = $this->check->run($tenant);

        self::assertFalse($result->passed);
        self::assertSame(0.0, $result->score);
        self::assertNotNull($result->gap);
        self::assertSame('app_policy_wizard_index', $result->gap['route']);
        self::assertSame('policy_wizard', $result->gap['translation_domain']);
        self::assertSame(
            'compliance_check.data_breach_notification_72h.fail_message',
            $result->gap['title'],
        );
        self::assertCount(2, $result->details['violations']);
        $reasons = array_column($result->details['violations'], 'reason');
        self::assertContains('missing_procedure_document', $reasons);
        self::assertContains('missing_breach_sla_row', $reasons);

        $nullResult = $this->check->run(null);
        self::assertFalse($nullResult->passed);
        self::assertSame('no_tenant', $nullResult->details['reason']);
    }

    private function stubScalarQueryBuilder(int $count): QueryBuilder&MockObject
    {
        $query = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getSingleScalarResult'])
            ->getMock();
        $query->method('getSingleScalarResult')->willReturn($count);

        $qb = $this->createMock(QueryBuilder::class);
        foreach (['select', 'innerJoin', 'where', 'andWhere', 'setParameter'] as $method) {
            $qb->method($method)->willReturnSelf();
        }
        $qb->method('getQuery')->willReturn($query);
        return $qb;
    }
}
