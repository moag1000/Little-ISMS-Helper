<?php

declare(strict_types=1);

namespace App\Tests\Service\Authority;

use App\Entity\DataBreach;
use App\Entity\Tenant;
use App\Repository\DataBreachRepository;
use App\Service\Authority\OverdueAuthorityNotificationResolver;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The resolver is the single source of truth for "which breaches are overdue
 * for a supervisory-authority notification" (GDPR Art. 33). It is shared by the
 * Alva hint and the notification index focus filter.
 */
#[AllowMockObjectsWithoutExpectations]
final class OverdueAuthorityNotificationResolverTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
    }

    #[Test]
    public function emptyWhenNoBreaches(): void
    {
        $resolver = $this->makeResolver([], exportCount: 0);
        self::assertSame([], $resolver->findOverdueBreaches($this->tenant));
    }

    #[Test]
    public function ignoresLowSeverity(): void
    {
        $resolver = $this->makeResolver(
            [$this->breach('low', new DateTimeImmutable('-30 hours'))],
            exportCount: 0,
        );
        self::assertSame([], $resolver->findOverdueBreaches($this->tenant));
    }

    #[Test]
    public function ignoresAlreadyNotified(): void
    {
        $breach = $this->breach('critical', new DateTimeImmutable('-30 hours'));
        $breach->setSupervisoryAuthorityNotifiedAt(new DateTimeImmutable());

        $resolver = $this->makeResolver([$breach], exportCount: 0);
        self::assertSame([], $resolver->findOverdueBreaches($this->tenant));
    }

    #[Test]
    public function ignoresWithinThreshold(): void
    {
        $resolver = $this->makeResolver(
            [$this->breach('high', new DateTimeImmutable('-10 hours'))],
            exportCount: 0,
        );
        self::assertSame([], $resolver->findOverdueBreaches($this->tenant));
    }

    #[Test]
    public function ignoresWhenExportLogged(): void
    {
        $resolver = $this->makeResolver(
            [$this->breach('critical', new DateTimeImmutable('-30 hours'))],
            exportCount: 1,
        );
        self::assertSame([], $resolver->findOverdueBreaches($this->tenant));
    }

    #[Test]
    public function returnsOverdueCriticalBreach(): void
    {
        $breach = $this->breach('critical', new DateTimeImmutable('-30 hours'));
        $resolver = $this->makeResolver([$breach], exportCount: 0);

        $overdue = $resolver->findOverdueBreaches($this->tenant);
        self::assertCount(1, $overdue);
        self::assertSame($breach, $overdue[0]);
    }

    private function breach(string $severity, DateTimeImmutable $detectedAt): DataBreach
    {
        $breach = new DataBreach();
        $breach->setSeverity($severity);
        $breach->setDetectedAt($detectedAt);
        $breach->setReferenceNumber('BREACH-TEST-001');
        $breach->setTitle('Test Breach');
        $breach->setBreachNature('Test');
        $breach->setLikelyConsequences('Test');
        $breach->setMeasuresTaken('Test');
        $breach->setDataCategories(['PII']);
        $breach->setDataSubjectCategories(['employees']);
        return $breach;
    }

    /**
     * @param DataBreach[] $breaches
     */
    private function makeResolver(array $breaches, int $exportCount): OverdueAuthorityNotificationResolver
    {
        $repo = $this->createMock(DataBreachRepository::class);
        $repo->method('findByTenant')->willReturn($breaches);

        $query = $this->createMock(Query::class);
        $query->method('getSingleScalarResult')->willReturn($exportCount);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('innerJoin')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('createQueryBuilder')->willReturn($qb);

        return new OverdueAuthorityNotificationResolver($repo, $em);
    }
}
