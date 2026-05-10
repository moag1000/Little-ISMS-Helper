<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Entity\Risk;
use App\Entity\Tenant;
use App\Entity\Vulnerability;
use App\EventListener\AutoReactionRiskSkeletonListener;
use App\Service\AutoReactionService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Event\PostPersistEventArgs;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * V3 W2-M5 — AutoReactionRiskSkeletonListener tests.
 *
 * CVSS gating, idempotency via title+tenant lookup, severity heuristic mapping.
 */
#[AllowMockObjectsWithoutExpectations]
class AutoReactionRiskSkeletonListenerTest extends TestCase
{
    private MockObject $reactions;
    private MockObject $logger;
    private AutoReactionRiskSkeletonListener $listener;

    protected function setUp(): void
    {
        $this->reactions = $this->createMock(AutoReactionService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->listener = new AutoReactionRiskSkeletonListener($this->reactions, $this->logger);
    }

    #[Test]
    public function toggleDisabledIsNoOp(): void
    {
        $this->reactions->method('isEnabled')->willReturn(false);

        $vuln = $this->createVuln(1, $this->createTenant(1), 'CVE-X', '7.0');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');

        $args = new PostPersistEventArgs($vuln, $em);
        $this->listener->postPersist($vuln, $args);
    }

    #[Test]
    public function lowCvssIsNoOp(): void
    {
        $this->reactions->method('isEnabled')->willReturn(true);

        $vuln = $this->createVuln(1, $this->createTenant(1), 'CVE-X', '5.0');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');

        $args = new PostPersistEventArgs($vuln, $em);
        $this->listener->postPersist($vuln, $args);
    }

    #[Test]
    public function highCvssCreatesRiskWithTenant(): void
    {
        $this->reactions->method('isEnabled')->willReturn(true);

        $tenant = $this->createTenant(11);
        $vuln = $this->createVuln(99, $tenant, 'CVE-2026-1234', '8.5');
        $vuln->setDescription('Buffer overflow in foo');

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->willReturn(null);

        $persisted = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(Risk::class)->willReturn($repo);
        $em->method('persist')->willReturnCallback(static function ($e) use (&$persisted) { $persisted[] = $e; });
        $em->method('flush');

        $args = new PostPersistEventArgs($vuln, $em);
        $this->listener->postPersist($vuln, $args);

        $riskInstances = array_values(array_filter(
            $persisted,
            static fn($e) => $e instanceof Risk,
        ));
        $this->assertCount(1, $riskInstances);
        /** @var Risk $risk */
        $risk = $riskInstances[0];
        $this->assertSame($tenant, $risk->getTenant());
        $this->assertSame('Vulnerability CVE-2026-1234', $risk->getTitle());
        $this->assertSame(4, $risk->getProbability());
        $this->assertSame(4, $risk->getImpact());
    }

    #[Test]
    public function veryHighCvssMaps5p5i(): void
    {
        $this->reactions->method('isEnabled')->willReturn(true);

        $tenant = $this->createTenant(11);
        $vuln = $this->createVuln(100, $tenant, 'CVE-2026-9999', '9.8');

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->willReturn(null);

        $persisted = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);
        $em->method('persist')->willReturnCallback(static function ($e) use (&$persisted) { $persisted[] = $e; });
        $em->method('flush');

        $args = new PostPersistEventArgs($vuln, $em);
        $this->listener->postPersist($vuln, $args);

        $riskInstances = array_values(array_filter(
            $persisted,
            static fn($e) => $e instanceof Risk,
        ));
        $this->assertCount(1, $riskInstances);
        $this->assertSame(5, $riskInstances[0]->getProbability());
        $this->assertSame(5, $riskInstances[0]->getImpact());
    }

    #[Test]
    public function existingRiskWithSameTitleAndTenantPreventsDuplicate(): void
    {
        $this->reactions->method('isEnabled')->willReturn(true);

        $tenant = $this->createTenant(11);
        $vuln = $this->createVuln(99, $tenant, 'CVE-2026-1234', '8.5');

        $existing = $this->createMock(Risk::class);
        $repo = $this->createMock(EntityRepository::class);
        // Verify lookup is tenant-scoped.
        $repo->expects($this->once())->method('findOneBy')->with($this->callback(static function (array $crit) use ($tenant): bool {
            return ($crit['title'] ?? null) === 'Vulnerability CVE-2026-1234'
                && ($crit['tenant'] ?? null) === $tenant;
        }))->willReturn($existing);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);
        $em->expects($this->never())->method('persist');

        $args = new PostPersistEventArgs($vuln, $em);
        $this->listener->postPersist($vuln, $args);
    }

    private function createTenant(int $id): Tenant
    {
        $tenant = new Tenant();
        $idProperty = (new \ReflectionClass($tenant))->getProperty('id');
        $idProperty->setValue($tenant, $id);
        return $tenant;
    }

    private function createVuln(int $id, Tenant $tenant, string $cve, string $cvssScore): Vulnerability
    {
        $vuln = new Vulnerability();
        $idProperty = (new \ReflectionClass($vuln))->getProperty('id');
        $idProperty->setValue($vuln, $id);
        $vuln->setTenant($tenant);
        $vuln->setCveId($cve);
        $vuln->setCvssScore($cvssScore);
        return $vuln;
    }
}
