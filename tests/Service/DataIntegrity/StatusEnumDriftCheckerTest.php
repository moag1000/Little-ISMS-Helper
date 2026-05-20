<?php

declare(strict_types=1);

namespace App\Tests\Service\DataIntegrity;

use App\Service\DataIntegrity\StatusEnumDriftChecker;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class StatusEnumDriftCheckerTest extends TestCase
{
    private MockObject $entityManager;
    private StatusEnumDriftChecker $checker;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->checker = new StatusEnumDriftChecker($this->entityManager);
    }

    #[Test]
    public function testFindDriftIssuesReturnsEmptyArrayWhenMetadataFactoryThrows(): void
    {
        // All entity classes are mapped in the application but the unit-test
        // EM mock's metadata factory does not know them — every getMetadataFor()
        // call throws, so the checker must skip silently and return [].
        $metadataFactory = $this->getMockBuilder(\Doctrine\ORM\Mapping\ClassMetadataFactory::class)
            ->disableOriginalConstructor()
            ->getMock();
        $metadataFactory->method('getMetadataFor')
            ->willThrowException(new \RuntimeException('not mapped in unit test'));
        $this->entityManager->method('getMetadataFactory')->willReturn($metadataFactory);

        $result = $this->checker->findDriftIssues();

        $this->assertIsArray($result);
        $this->assertSame([], $result);
    }

    #[Test]
    public function testGetEntityEnumPairsReturnsNonEmptyArray(): void
    {
        $pairs = $this->checker->getEntityEnumPairs();

        $this->assertIsArray($pairs);
        $this->assertNotEmpty($pairs);

        // Spot-check a well-known pair to ensure the constant was not accidentally emptied.
        $this->assertArrayHasKey(\App\Entity\Risk::class, $pairs);
        $this->assertSame(\App\Enum\RiskStatus::class, $pairs[\App\Entity\Risk::class]);
    }

    #[Test]
    public function testFindDriftIssuesResultEntriesHaveExpectedShape(): void
    {
        // Simulate a metadata factory that reports entity has 'status' field
        // and a QueryBuilder that returns a value not in the enum.
        $metadata = $this->getMockBuilder(\Doctrine\ORM\Mapping\ClassMetadata::class)
            ->disableOriginalConstructor()
            ->getMock();
        $metadata->method('hasField')->with('status')->willReturn(true);

        $metadataFactory = $this->getMockBuilder(\Doctrine\ORM\Mapping\ClassMetadataFactory::class)
            ->disableOriginalConstructor()
            ->getMock();
        $metadataFactory->method('getMetadataFor')->willReturn($metadata);

        $query = $this->createMock(\Doctrine\ORM\Query::class);
        // Return one unknown value 'legacy_draft' with count 3.
        $query->method('getArrayResult')->willReturn([['status' => 'legacy_draft', 'cnt' => 3]]);

        $qb = $this->getMockBuilder(\Doctrine\ORM\QueryBuilder::class)
            ->disableOriginalConstructor()
            ->getMock();
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('groupBy')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $this->entityManager->method('getMetadataFactory')->willReturn($metadataFactory);
        $this->entityManager->method('createQueryBuilder')->willReturn($qb);

        $result = $this->checker->findDriftIssues();

        // At least one entry must be present (an entity 'legacy_draft' is unknown).
        $this->assertNotEmpty($result);
        $firstEntry = $result[0];
        $this->assertArrayHasKey('entity', $firstEntry);
        $this->assertArrayHasKey('enum', $firstEntry);
        $this->assertArrayHasKey('unknown_values', $firstEntry);
        $this->assertIsString($firstEntry['entity']);
        $this->assertIsString($firstEntry['enum']);
        $this->assertIsArray($firstEntry['unknown_values']);
    }
}
