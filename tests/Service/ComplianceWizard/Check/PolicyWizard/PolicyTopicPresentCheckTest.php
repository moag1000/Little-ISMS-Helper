<?php

declare(strict_types=1);

namespace App\Tests\Service\ComplianceWizard\Check\PolicyWizard;

use App\Entity\Tenant;
use App\Repository\DocumentRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyTopicPresentCheck;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class PolicyTopicPresentCheckTest extends TestCase
{
    private DocumentRepository&MockObject $documentRepository;

    protected function setUp(): void
    {
        $this->documentRepository = $this->createMock(DocumentRepository::class);
    }

    #[Test]
    public function checkIdEncodesTopicKey(): void
    {
        $check = new PolicyTopicPresentCheck($this->documentRepository, 'access_control');
        self::assertSame('policy_topic_access_control_present', $check->getCheckId());
        self::assertSame('iso27001', $check->getStandard());
        self::assertSame('access_control', $check->getTopic());
    }

    #[Test]
    public function emptyTopicIsRejectedAtConstructionTime(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PolicyTopicPresentCheck($this->documentRepository, '');
    }

    #[Test]
    public function passesWhenTopicPolicyIsPublished(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $check = new PolicyTopicPresentCheck($this->documentRepository, 'cryptography');
        $this->documentRepository->method('createQueryBuilder')->willReturn(
            $this->stubScalarQueryBuilder(1),
        );

        $result = $check->run($tenant);

        self::assertTrue($result->passed);
        self::assertSame(100.0, $result->score);
        self::assertSame('cryptography', $result->details['topic']);
        self::assertNull($result->gap);
    }

    #[Test]
    public function failsWhenNoTopicPolicyIsPublished(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $check = new PolicyTopicPresentCheck($this->documentRepository, 'logging');
        $this->documentRepository->method('createQueryBuilder')->willReturn(
            $this->stubScalarQueryBuilder(0),
        );

        $result = $check->run($tenant);

        self::assertFalse($result->passed);
        self::assertSame(0.0, $result->score);
        self::assertNotNull($result->gap);
        self::assertSame('high', $result->gap['priority']);
        self::assertSame('policy_wizard', $result->gap['translation_domain']);
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
