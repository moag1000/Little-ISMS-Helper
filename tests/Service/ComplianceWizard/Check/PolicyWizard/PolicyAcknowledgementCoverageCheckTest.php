<?php

declare(strict_types=1);

namespace App\Tests\Service\ComplianceWizard\Check\PolicyWizard;

use App\Entity\Document;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\DocumentRepository;
use App\Repository\PolicyAcknowledgementRepository;
use App\Repository\UserRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyAcknowledgementCoverageCheck;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class PolicyAcknowledgementCoverageCheckTest extends TestCase
{
    private DocumentRepository&MockObject $documentRepository;
    private PolicyAcknowledgementRepository&MockObject $ackRepository;
    private UserRepository&MockObject $userRepository;
    private PolicyAcknowledgementCoverageCheck $check;

    protected function setUp(): void
    {
        $this->documentRepository = $this->createMock(DocumentRepository::class);
        $this->ackRepository = $this->createMock(PolicyAcknowledgementRepository::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->check = new PolicyAcknowledgementCoverageCheck(
            $this->documentRepository,
            $this->ackRepository,
            $this->userRepository,
        );
    }

    #[Test]
    public function thresholdConstantIsExactlyNinetyFivePercent(): void
    {
        // Pinned by ISO 27002 §6.3 awareness benchmark — bumping the
        // threshold should be a deliberate code change with audit-trail.
        self::assertSame(95.0, PolicyAcknowledgementCoverageCheck::THRESHOLD_PERCENT);
    }

    #[Test]
    public function passesAtExactlyNinetyFivePercentCoverage(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $policy = $this->createMock(Document::class);

        $this->documentRepository->method('createQueryBuilder')->willReturn(
            $this->stubResultQueryBuilder([$policy]),
        );
        // 19/20 = 95.0 % exactly — must still pass (>= threshold).
        $this->userRepository->method('findActiveUsers')->willReturn(array_fill(0, 20, $this->createMock(User::class)));
        $this->ackRepository->method('findByDocument')->willReturn(array_fill(0, 19, new \stdClass()));

        $result = $this->check->run($tenant);

        self::assertTrue($result->passed);
        self::assertSame(100.0, $result->score);
        self::assertSame(20, $result->details['audience_size']);
    }

    #[Test]
    public function failsBelowNinetyFivePercentCoverage(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $policy = $this->createMock(Document::class);
        $policy->method('getId')->willReturn(11);
        $policy->method('getOriginalFilename')->willReturn('backup-policy.pdf');

        $this->documentRepository->method('createQueryBuilder')->willReturn(
            $this->stubResultQueryBuilder([$policy]),
        );
        // 18/20 = 90 % — below threshold.
        $this->userRepository->method('findActiveUsers')->willReturn(array_fill(0, 20, $this->createMock(User::class)));
        $this->ackRepository->method('findByDocument')->willReturn(array_fill(0, 18, new \stdClass()));

        $result = $this->check->run($tenant);

        self::assertFalse($result->passed);
        self::assertSame(0.0, $result->score);
        self::assertNotNull($result->gap);
        self::assertSame('high', $result->gap['priority']);
    }

    #[Test]
    public function emptyAudienceShortCircuitsToPass(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $policy = $this->createMock(Document::class);

        $this->documentRepository->method('createQueryBuilder')->willReturn(
            $this->stubResultQueryBuilder([$policy]),
        );
        $this->userRepository->method('findActiveUsers')->willReturn([]);

        $result = $this->check->run($tenant);

        self::assertTrue($result->passed);
        self::assertSame(100.0, $result->score);
        self::assertSame(0, $result->details['audience_size']);
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
