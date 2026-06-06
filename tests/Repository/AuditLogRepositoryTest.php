<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\AuditLog;
use App\Repository\AuditLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Verifies that filtered audit-log pagination reports the real total and pages
 * correctly — previously the controller counted only the capped page rows, so
 * any filter matching >50 entries collapsed to a single page.
 */
class AuditLogRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AuditLogRepository $repo;
    private string $probe;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repo = static::getContainer()->get(AuditLogRepository::class);
        $this->probe = 'PaginationProbe_' . uniqid('', true);

        for ($i = 0; $i < 60; $i++) {
            $log = new AuditLog();
            $log->setEntityType($this->probe);
            $log->setAction('create');
            $log->setUserName('seed@example.com');
            $log->setCreatedAt(new \DateTimeImmutable("-{$i} minutes"));
            $this->em->persist($log);
        }
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        foreach ($this->repo->findBy(['entityType' => $this->probe]) as $log) {
            $this->em->remove($log);
        }
        $this->em->flush();
        parent::tearDown();
    }

    #[Test]
    public function testCountSearchReturnsRealTotalWhileSearchPaginates(): void
    {
        $filters = ['entityType' => $this->probe];

        // Real total — not capped by page size.
        self::assertSame(60, $this->repo->countSearch($filters));

        // Page 1: first 50.
        $page1 = $this->repo->search($filters + ['limit' => 50, 'offset' => 0]);
        self::assertCount(50, $page1);

        // Page 2: the remaining 10 (offset must actually move the window).
        $page2 = $this->repo->search($filters + ['limit' => 50, 'offset' => 50]);
        self::assertCount(10, $page2);

        // No overlap between the two pages.
        $ids1 = array_map(static fn(AuditLog $l): ?int => $l->getId(), $page1);
        $ids2 = array_map(static fn(AuditLog $l): ?int => $l->getId(), $page2);
        self::assertSame([], array_intersect($ids1, $ids2));
    }
}
