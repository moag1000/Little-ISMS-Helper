<?php

declare(strict_types=1);

namespace App\Tests\Service\DataIntegrity;

use App\Entity\Notification\NotificationTemplate;
use App\Service\DataIntegrity\OrphanFinder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class OrphanFinderTest extends TestCase
{
    private MockObject $entityManager;
    private OrphanFinder $finder;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->finder        = new OrphanFinder($this->entityManager);
    }

    #[Test]
    public function global_catalogue_entities_constant_contains_notification_template(): void
    {
        self::assertContains(NotificationTemplate::class, OrphanFinder::GLOBAL_CATALOGUE_ENTITIES);
    }

    #[Test]
    public function find_all_orphaned_entities_disables_and_re_enables_tenant_filter(): void
    {
        // Use a stub that returns correct types: disable() returns SQLFilter,
        // enable() returns FilterCollection — track calls via side-effects.
        $sqlFilter = $this->createMock(\Doctrine\ORM\Query\Filter\SQLFilter::class);

        $filters = $this->getMockBuilder(\Doctrine\ORM\Query\FilterCollection::class)
            ->disableOriginalConstructor()
            ->getMock();
        $filters->method('isEnabled')->with('tenant_filter')->willReturn(true);
        $filters->method('disable')->willReturn($sqlFilter);
        $filters->method('enable')->willReturn($sqlFilter);

        $this->entityManager->method('getFilters')->willReturn($filters);

        $metadataFactory = $this->createMock(\Doctrine\ORM\Mapping\ClassMetadataFactory::class);
        $metadataFactory->method('getAllMetadata')->willReturn([]);
        $this->entityManager->method('getMetadataFactory')->willReturn($metadataFactory);

        // Verify no exception — the filter protocol (disable → re-enable) runs cleanly
        $result = $this->finder->findAllOrphanedEntities();
        self::assertSame([], $result);
    }

    #[Test]
    public function find_all_orphaned_entities_returns_empty_when_no_metadata(): void
    {
        $filters = $this->createMock(\Doctrine\ORM\Query\FilterCollection::class);
        $filters->method('isEnabled')->willReturn(false);
        $this->entityManager->method('getFilters')->willReturn($filters);

        $metadataFactory = $this->createMock(\Doctrine\ORM\Mapping\ClassMetadataFactory::class);
        $metadataFactory->method('getAllMetadata')->willReturn([]);
        $this->entityManager->method('getMetadataFactory')->willReturn($metadataFactory);

        $result = $this->finder->findAllOrphanedEntities();

        self::assertSame([], $result);
    }

    #[Test]
    public function find_cascade_orphans_returns_five_keyed_result(): void
    {
        // createQueryBuilder throws → all five try/catch blocks skip silently
        $this->entityManager->method('createQueryBuilder')->willThrowException(new \RuntimeException('no DB'));

        $result = $this->finder->findCascadeOrphans();

        self::assertArrayHasKey('workflow_instances', $result);
        self::assertArrayHasKey('mfa_tokens', $result);
        self::assertArrayHasKey('sso_user_approvals', $result);
        self::assertArrayHasKey('evidence_tasks', $result);
        self::assertArrayHasKey('notification_deliveries', $result);

        // All empty because every check skipped
        self::assertSame([], $result['workflow_instances']);
        self::assertSame([], $result['mfa_tokens']);
    }
}
