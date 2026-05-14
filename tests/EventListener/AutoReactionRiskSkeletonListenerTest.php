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
 * V3 W2-M5 / V4-LB-3 — AutoReactionRiskSkeletonListener tests.
 *
 * CVSS gating, idempotency via FK (not title-string), severity heuristic mapping,
 * false-positive guard for similar vulnerability titles.
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
        /** @var Risk $risk */
        $risk = $riskInstances[0];
        $this->assertSame($tenant, $risk->getTenant());
        $this->assertSame('Vulnerability CVE-2026-1234', $risk->getTitle());
        $this->assertSame(4, $risk->getProbability());
        $this->assertSame(4, $risk->getImpact());
        // V4-LB-3: FK must be set on newly created skeleton for future idempotency.
        $this->assertSame($vuln, $risk->getLinkedVulnerability());
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
        // Kept for backward-compat; now verifies FK-based lookup, not title+tenant.
        $this->reactions->method('isEnabled')->willReturn(true);

        $tenant = $this->createTenant(11);
        $vuln = $this->createVuln(99, $tenant, 'CVE-2026-1234', '8.5');

        $existing = $this->createMock(Risk::class);
        $repo = $this->createMock(EntityRepository::class);
        // V4-LB-3: idempotency lookup must use the FK, not title+tenant.
        $repo->expects($this->once())->method('findOneBy')->with($this->callback(static function (array $crit) use ($vuln): bool {
            return ($crit['linkedVulnerability'] ?? null) === $vuln;
        }))->willReturn($existing);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);
        $em->expects($this->never())->method('persist');

        $args = new PostPersistEventArgs($vuln, $em);
        $this->listener->postPersist($vuln, $args);
    }

    /**
     * V4-LB-3: FK-based idempotency — a Risk linked via linkedVulnerability FK
     * prevents a second skeleton even if the Risk title was manually renamed.
     */
    #[Test]
    public function testIdempotencyViaForeignKey(): void
    {
        $this->reactions->method('isEnabled')->willReturn(true);

        $tenant = $this->createTenant(5);
        $vuln = $this->createVuln(42, $tenant, 'CVE-2026-5678', '9.1');

        $existingRisk = $this->createMock(Risk::class);

        $repo = $this->createMock(EntityRepository::class);
        // Simulate: linked Risk found by FK even though its title was renamed.
        $repo->expects($this->once())
            ->method('findOneBy')
            ->with(['linkedVulnerability' => $vuln])
            ->willReturn($existingRisk);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);
        $em->expects($this->never())->method('persist');

        $args = new PostPersistEventArgs($vuln, $em);
        $this->listener->postPersist($vuln, $args);
    }

    /**
     * V4-LB-3: Two vulnerabilities with similar CVE prefixes must each get their
     * own Risk skeleton — no false-positive title-prefix collision.
     */
    #[Test]
    public function testNoTitleStringFalsePositive(): void
    {
        $this->reactions->method('isEnabled')->willReturn(true);

        $tenant = $this->createTenant(7);
        $vuln1 = $this->createVuln(10, $tenant, 'CVE-2026-1', '7.5');
        $vuln2 = $this->createVuln(11, $tenant, 'CVE-2026-10', '7.5');

        // For vuln1: no existing Risk linked to vuln1.
        // For vuln2: no existing Risk linked to vuln2.
        // Both should create their own skeleton (persisted twice total).
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->willReturn(null);

        $persisted = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);
        $em->method('persist')->willReturnCallback(static function ($e) use (&$persisted): void { $persisted[] = $e; });
        $em->method('flush');

        $args1 = new PostPersistEventArgs($vuln1, $em);
        $this->listener->postPersist($vuln1, $args1);

        $args2 = new PostPersistEventArgs($vuln2, $em);
        $this->listener->postPersist($vuln2, $args2);

        $riskInstances = array_values(array_filter($persisted, static fn($e) => $e instanceof Risk));
        $this->assertCount(2, $riskInstances, 'Each vulnerability must produce its own skeleton — no title-prefix false-positive.');

        // Verify each skeleton is linked to its own vulnerability via FK.
        $this->assertSame($vuln1, $riskInstances[0]->getLinkedVulnerability());
        $this->assertSame($vuln2, $riskInstances[1]->getLinkedVulnerability());
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
