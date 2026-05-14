<?php

declare(strict_types=1);

namespace App\Tests\Service\ComplianceWizard\Check\PolicyWizard\Privacy;

use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\DocumentRepository;
use App\Repository\UserRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\Privacy\DpoCharterAppointedCheck;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class DpoCharterAppointedCheckTest extends TestCase
{
    private DocumentRepository&MockObject $documentRepository;
    private UserRepository&MockObject $userRepository;
    private DpoCharterAppointedCheck $check;

    protected function setUp(): void
    {
        $this->documentRepository = $this->createMock(DocumentRepository::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->check = new DpoCharterAppointedCheck(
            $this->documentRepository,
            $this->userRepository,
        );
    }

    #[Test]
    public function testPassesWhenDpoUserAndStandaloneCharterPresent(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn(42);

        $dpoUser = $this->createMock(User::class);
        $dpoUser->method('getTenant')->willReturn($tenant);
        $this->userRepository->method('findByCustomRole')
            ->willReturn([$dpoUser]);

        // First QB call returns 1 (charter doc), second call returns 0 (section).
        $this->documentRepository->method('createQueryBuilder')
            ->willReturnOnConsecutiveCalls(
                $this->stubScalarQueryBuilder(1),
                $this->stubScalarQueryBuilder(0),
            );

        $result = $this->check->run($tenant);

        self::assertTrue($result->passed);
        self::assertSame(100.0, $result->score);
        self::assertSame(1, $result->details['dpo_users']);
        self::assertSame(1, $result->details['charter_documents']);
        self::assertNull($result->gap);
        self::assertSame('gdpr', $this->check->getStandard());
    }

    #[Test]
    public function testFailsWhenNoDpoUserAndNoCharter(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn(42);

        $this->userRepository->method('findByCustomRole')->willReturn([]);
        $this->documentRepository->method('createQueryBuilder')
            ->willReturnOnConsecutiveCalls(
                $this->stubScalarQueryBuilder(0),
                $this->stubScalarQueryBuilder(0),
            );

        $result = $this->check->run($tenant);

        self::assertFalse($result->passed);
        self::assertSame(0.0, $result->score);
        self::assertNotNull($result->gap);
        $reasons = array_column($result->details['violations'], 'reason');
        self::assertContains('no_user_with_role_dpo', $reasons);
        self::assertContains('no_dpo_charter_document_or_section', $reasons);
    }

    #[Test]
    public function testGapActionableAndPassesWithSectionCharter(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn(42);

        $dpoUser = $this->createMock(User::class);
        $dpoUser->method('getTenant')->willReturn($tenant);
        $this->userRepository->method('findByCustomRole')->willReturn([$dpoUser]);

        // 0 charter doc, 1 section = passes (section evidence).
        $this->documentRepository->method('createQueryBuilder')
            ->willReturnOnConsecutiveCalls(
                $this->stubScalarQueryBuilder(0),
                $this->stubScalarQueryBuilder(1),
            );

        $sectionResult = $this->check->run($tenant);
        self::assertTrue($sectionResult->passed);
        self::assertSame(0, $sectionResult->details['charter_documents']);
        self::assertSame(1, $sectionResult->details['charter_sections']);

        // Re-test: gap details when failing.
        $strictDocs = $this->createMock(DocumentRepository::class);
        $strictDocs->method('createQueryBuilder')
            ->willReturnOnConsecutiveCalls(
                $this->stubScalarQueryBuilder(0),
                $this->stubScalarQueryBuilder(0),
            );
        $strictUsers = $this->createMock(UserRepository::class);
        $strictUsers->method('findByCustomRole')->willReturn([]);
        $strict = new DpoCharterAppointedCheck($strictDocs, $strictUsers);
        $strictResult = $strict->run($tenant);
        self::assertFalse($strictResult->passed);
        self::assertSame('app_policy_wizard_index', $strictResult->gap['route']);
        self::assertSame('policy_wizard', $strictResult->gap['translation_domain']);
        self::assertSame(
            'compliance_check.dpo_charter_appointed.fail_message',
            $strictResult->gap['title'],
        );

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
