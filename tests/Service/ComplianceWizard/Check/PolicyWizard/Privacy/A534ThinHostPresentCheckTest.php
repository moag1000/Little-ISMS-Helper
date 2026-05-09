<?php

declare(strict_types=1);

namespace App\Tests\Service\ComplianceWizard\Check\PolicyWizard\Privacy;

use App\Entity\ComplianceFramework;
use App\Entity\Tenant;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\DocumentRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\Privacy\A534ThinHostPresentCheck;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class A534ThinHostPresentCheckTest extends TestCase
{
    private DocumentRepository&MockObject $documentRepository;
    private ComplianceFrameworkRepository&MockObject $frameworkRepository;
    private A534ThinHostPresentCheck $check;

    protected function setUp(): void
    {
        $this->documentRepository = $this->createMock(DocumentRepository::class);
        $this->frameworkRepository = $this->createMock(ComplianceFrameworkRepository::class);
        $this->check = new A534ThinHostPresentCheck(
            $this->documentRepository,
            $this->frameworkRepository,
        );
    }

    #[Test]
    public function testPassesWhenDualScopeActiveAndThinHostExists(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $iso = $this->makeFramework(true);
        $gdpr = $this->makeFramework(true);
        $this->frameworkRepository->method('findOneBy')->willReturnCallback(
            fn (array $criteria): ?ComplianceFramework => match ($criteria['code'] ?? null) {
                'ISO27001' => $iso,
                'GDPR' => $gdpr,
                default => null,
            },
        );
        $this->documentRepository->method('createQueryBuilder')
            ->willReturn($this->stubScalarQueryBuilder(1));

        $result = $this->check->run($tenant);

        self::assertTrue($result->passed);
        self::assertSame(100.0, $result->score);
        self::assertSame(1, $result->details['thin_host_documents']);
        self::assertNull($result->gap);
        self::assertSame('gdpr', $this->check->getStandard());
    }

    #[Test]
    public function testFailsWhenDualScopeActiveButThinHostMissing(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $iso = $this->makeFramework(true);
        $gdpr = $this->makeFramework(true);
        $this->frameworkRepository->method('findOneBy')->willReturnCallback(
            fn (array $criteria): ?ComplianceFramework => match ($criteria['code'] ?? null) {
                'ISO27001' => $iso,
                'GDPR' => $gdpr,
                default => null,
            },
        );
        $this->documentRepository->method('createQueryBuilder')
            ->willReturn($this->stubScalarQueryBuilder(0));

        $result = $this->check->run($tenant);

        self::assertFalse($result->passed);
        self::assertSame(0.0, $result->score);
        self::assertSame(0, $result->details['thin_host_documents']);
        self::assertNotNull($result->gap);
        self::assertSame('high', $result->gap['priority']);
    }

    #[Test]
    public function testGapActionableAndVacuousWhenSingleScope(): void
    {
        $tenant = $this->createMock(Tenant::class);

        // GDPR active, ISO inactive → vacuous pass.
        $iso = $this->makeFramework(false);
        $gdpr = $this->makeFramework(true);
        $this->frameworkRepository->method('findOneBy')->willReturnCallback(
            fn (array $criteria): ?ComplianceFramework => match ($criteria['code'] ?? null) {
                'ISO27001' => $iso,
                'GDPR' => $gdpr,
                default => null,
            },
        );

        $vacuous = $this->check->run($tenant);
        self::assertTrue($vacuous->passed);
        self::assertSame('thin_host_not_required_outside_dual_scope', $vacuous->details['reason']);
        self::assertNull($vacuous->gap);

        // Re-test with both active + missing host for gap detail check.
        $strictIso = $this->makeFramework(true);
        $strictGdpr = $this->makeFramework(true);
        $strictFwRepo = $this->createMock(ComplianceFrameworkRepository::class);
        $strictFwRepo->method('findOneBy')->willReturnCallback(
            fn (array $criteria): ?ComplianceFramework => match ($criteria['code'] ?? null) {
                'ISO27001' => $strictIso,
                'GDPR' => $strictGdpr,
                default => null,
            },
        );
        $strictDocs = $this->createMock(DocumentRepository::class);
        $strictDocs->method('createQueryBuilder')
            ->willReturn($this->stubScalarQueryBuilder(0));
        $strict = new A534ThinHostPresentCheck($strictDocs, $strictFwRepo);

        $strictResult = $strict->run($tenant);
        self::assertNotNull($strictResult->gap);
        self::assertSame('app_policy_wizard_index', $strictResult->gap['route']);
        self::assertSame('policy_wizard', $strictResult->gap['translation_domain']);
        self::assertSame(
            'compliance_check.a534_thin_host_present.fail_message',
            $strictResult->gap['title'],
        );

        $nullResult = $this->check->run(null);
        self::assertFalse($nullResult->passed);
        self::assertSame('no_tenant', $nullResult->details['reason']);
    }

    private function makeFramework(bool $active): ComplianceFramework
    {
        $framework = new ComplianceFramework();
        $framework->setActive($active);
        return $framework;
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
