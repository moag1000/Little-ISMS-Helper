<?php

declare(strict_types=1);

namespace App\Tests\Service\ComplianceWizard\Check\PolicyWizard\Bsi;

use App\Entity\PolicyTemplate;
use App\Entity\Tenant;
use App\Repository\DocumentRepository;
use App\Repository\PolicyTemplateRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\Bsi\BsiBaselineCoverageCheck;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class BsiBaselineCoverageCheckTest extends TestCase
{
    private DocumentRepository&MockObject $documentRepository;
    private PolicyTemplateRepository&MockObject $templateRepository;
    private BsiBaselineCoverageCheck $check;

    protected function setUp(): void
    {
        $this->documentRepository = $this->createMock(DocumentRepository::class);
        $this->templateRepository = $this->createMock(PolicyTemplateRepository::class);
        $this->check = new BsiBaselineCoverageCheck(
            $this->documentRepository,
            $this->templateRepository,
        );
    }

    #[Test]
    public function testPassesWhenAllBasisTopicsCovered(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $templates = [
            $this->makeTemplate('it_security_policy'),
            $this->makeTemplate('isms_concept'),
        ];
        $this->templateRepository->method('findBy')->willReturn($templates);

        // Every getSingleScalarResult returns 1 → all topics covered.
        $this->documentRepository->method('createQueryBuilder')
            ->willReturn($this->stubScalarQueryBuilder(1));

        $result = $this->check->run($tenant);

        self::assertTrue($result->passed);
        self::assertSame(100.0, $result->score);
        self::assertSame(2, $result->details['expected_topics']);
        self::assertSame(2, $result->details['covered_topics']);
        self::assertNull($result->gap);
    }

    #[Test]
    public function testFailsWhenSomeBasisTopicsMissing(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $templates = [
            $this->makeTemplate('a'),
            $this->makeTemplate('b'),
            $this->makeTemplate('c'),
            $this->makeTemplate('d'),
        ];
        $this->templateRepository->method('findBy')->willReturn($templates);

        // 2 covered, 2 missing
        $this->documentRepository->method('createQueryBuilder')
            ->willReturnOnConsecutiveCalls(
                $this->stubScalarQueryBuilder(1),
                $this->stubScalarQueryBuilder(0),
                $this->stubScalarQueryBuilder(1),
                $this->stubScalarQueryBuilder(0),
            );

        $result = $this->check->run($tenant);

        self::assertFalse($result->passed);
        self::assertSame(50.0, $result->score);
        self::assertSame(4, $result->details['expected_topics']);
        self::assertSame(2, $result->details['covered_topics']);
        self::assertSame(2, $result->details['missing_count']);
        self::assertNotNull($result->gap);
        self::assertSame('critical', $result->gap['priority']);
    }

    #[Test]
    public function testGapMessageActionable(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $this->templateRepository->method('findBy')->willReturn([
            $this->makeTemplate('missing_topic'),
        ]);
        $this->documentRepository->method('createQueryBuilder')
            ->willReturn($this->stubScalarQueryBuilder(0));

        $result = $this->check->run($tenant);

        self::assertNotNull($result->gap);
        self::assertSame('app_policy_wizard_index', $result->gap['route']);
        self::assertSame('policy_wizard', $result->gap['translation_domain']);
        self::assertSame(
            'compliance_check.bsi_baseline_coverage.fail_message',
            $result->gap['title'],
        );
        self::assertContains('missing_topic', $result->gap['items']);

        $nullResult = $this->check->run(null);
        self::assertFalse($nullResult->passed);
        self::assertSame('no_tenant', $nullResult->details['reason']);
    }

    private function makeTemplate(string $topic): PolicyTemplate
    {
        $template = new PolicyTemplate();
        $template->setStandard('bsi');
        $template->setBsiTier(PolicyTemplate::BSI_TIER_BASIS);
        $template->setTopic($topic);
        return $template;
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
