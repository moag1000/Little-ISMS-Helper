<?php

declare(strict_types=1);

namespace App\Tests\Listener;

use App\Entity\CorrectiveAction;
use App\Entity\Incident;
use App\Entity\Tenant;
use App\Enum\IncidentSeverity;
use App\Listener\AutoReactionCorrectiveActionListenerForIncident;
use App\Service\AutoReactionService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Junior-ISB-Audit-2026-05-22 C2-05 — Incident → CorrectiveAction listener tests.
 *
 * Mirrors `AutoReactionCorrectiveActionListenerTest` (AuditFinding path).
 * Covers severity gating, rootCause gating, idempotency, tenant inheritance,
 * postUpdate-promotion (low → high).
 */
#[AllowMockObjectsWithoutExpectations]
class AutoReactionCorrectiveActionListenerForIncidentTest extends TestCase
{
    private MockObject $reactions;
    private MockObject $logger;
    private AutoReactionCorrectiveActionListenerForIncident $listener;

    protected function setUp(): void
    {
        $this->reactions = $this->createMock(AutoReactionService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->listener = new AutoReactionCorrectiveActionListenerForIncident(
            $this->reactions,
            $this->logger,
        );
    }

    #[Test]
    public function toggleDisabledIsNoOp(): void
    {
        $this->reactions->method('isEnabled')->willReturn(false);

        $incident = $this->createIncident(1, $this->createTenant(1), IncidentSeverity::Critical, 'Patch missing for 60 days');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');

        $args = new PostPersistEventArgs($incident, $em);
        $this->listener->postPersist($incident, $args);
    }

    #[Test]
    public function lowSeverityWithRootCauseIsNoOp(): void
    {
        $this->reactions->method('isEnabled')->willReturn(true);

        $incident = $this->createIncident(1, $this->createTenant(1), IncidentSeverity::Low, 'Trivial typo, no real harm');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');

        $args = new PostPersistEventArgs($incident, $em);
        $this->listener->postPersist($incident, $args);
    }

    #[Test]
    public function highSeverityWithEmptyRootCauseIsNoOp(): void
    {
        $this->reactions->method('isEnabled')->willReturn(true);

        $incident = $this->createIncident(2, $this->createTenant(1), IncidentSeverity::High, '');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');

        $args = new PostPersistEventArgs($incident, $em);
        $this->listener->postPersist($incident, $args);
    }

    #[Test]
    public function highSeverityWithWhitespaceOnlyRootCauseIsNoOp(): void
    {
        $this->reactions->method('isEnabled')->willReturn(true);

        $incident = $this->createIncident(2, $this->createTenant(1), IncidentSeverity::High, "   \t\n  ");

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');

        $args = new PostPersistEventArgs($incident, $em);
        $this->listener->postPersist($incident, $args);
    }

    #[Test]
    public function highSeverityWithRootCauseCreatesCorrectiveActionWithTenant(): void
    {
        $this->reactions->method('isEnabled')->willReturn(true);

        $tenant = $this->createTenant(7);
        $incident = $this->createIncident(42, $tenant, IncidentSeverity::High, 'Unpatched VPN concentrator (CVE-2026-1234) exploited via dictionary attack.');
        $incident->setTitle('VPN-Concentrator-Breach');

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->willReturn(null);

        $persisted = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);
        $em->method('persist')->willReturnCallback(static function ($e) use (&$persisted) { $persisted[] = $e; });
        $em->method('flush');

        $args = new PostPersistEventArgs($incident, $em);
        $this->listener->postPersist($incident, $args);

        $caInstances = array_values(array_filter(
            $persisted,
            static fn($e) => $e instanceof CorrectiveAction,
        ));
        $this->assertCount(1, $caInstances);
        /** @var CorrectiveAction $ca */
        $ca = $caInstances[0];
        $this->assertSame($tenant, $ca->getTenant());
        $this->assertSame($incident, $ca->getSourceIncident());
        $this->assertSame(CorrectiveAction::SOURCE_TYPE_INCIDENT, $ca->getSourceType());
        $this->assertSame(CorrectiveAction::STATUS_PLANNED, $ca->getStatus());
        $this->assertSame(CorrectiveAction::ACTION_TYPE_CORRECTIVE, $ca->getActionType());
        $this->assertStringContainsString('VPN-Concentrator-Breach', (string) $ca->getTitle());
        $this->assertStringContainsString('Unpatched VPN', (string) $ca->getRootCauseAnalysis());
        $this->assertNotNull($ca->getPlannedCompletionDate());
    }

    #[Test]
    public function criticalSeverityTriggersCa(): void
    {
        $this->reactions->method('isEnabled')->willReturn(true);

        $tenant = $this->createTenant(7);
        $incident = $this->createIncident(43, $tenant, IncidentSeverity::Critical, 'Ransomware encrypted production DB; offline backup restored from yesterday.');
        $incident->setTitle('PROD-DB-Ransomware');

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->willReturn(null);

        $persisted = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);
        $em->method('persist')->willReturnCallback(static function ($e) use (&$persisted) { $persisted[] = $e; });
        $em->method('flush');

        $args = new PostPersistEventArgs($incident, $em);
        $this->listener->postPersist($incident, $args);

        $caInstances = array_values(array_filter(
            $persisted,
            static fn($e) => $e instanceof CorrectiveAction,
        ));
        $this->assertCount(1, $caInstances);
    }

    #[Test]
    public function postUpdatePromotionFromLowToHighCreatesCa(): void
    {
        $this->reactions->method('isEnabled')->willReturn(true);

        $tenant = $this->createTenant(7);
        // Initially logged as low — re-triage upgraded to high after impact assessment.
        $incident = $this->createIncident(44, $tenant, IncidentSeverity::High, 'Re-assessed: bypass technique works against entire fleet.');
        $incident->setTitle('Re-triaged-bypass');

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->willReturn(null);

        $persisted = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);
        $em->method('persist')->willReturnCallback(static function ($e) use (&$persisted) { $persisted[] = $e; });
        $em->method('flush');

        $args = new PostUpdateEventArgs($incident, $em);
        $this->listener->postUpdate($incident, $args);

        $caInstances = array_values(array_filter(
            $persisted,
            static fn($e) => $e instanceof CorrectiveAction,
        ));
        $this->assertCount(1, $caInstances);
    }

    #[Test]
    public function existingCorrectiveActionPreventsDuplicate(): void
    {
        $this->reactions->method('isEnabled')->willReturn(true);

        $incident = $this->createIncident(45, $this->createTenant(7), IncidentSeverity::High, 'Some root cause already analysed.');

        $existing = $this->createMock(CorrectiveAction::class);
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->willReturn($existing);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);
        $em->expects($this->never())->method('persist');

        $args = new PostUpdateEventArgs($incident, $em);
        $this->listener->postUpdate($incident, $args);
    }

    private function createTenant(int $id): Tenant
    {
        $tenant = new Tenant();
        $idProperty = (new \ReflectionClass($tenant))->getProperty('id');
        $idProperty->setValue($tenant, $id);
        return $tenant;
    }

    private function createIncident(int $id, Tenant $tenant, IncidentSeverity $severity, string $rootCause): Incident
    {
        $incident = new Incident();
        $idProperty = (new \ReflectionClass($incident))->getProperty('id');
        $idProperty->setValue($incident, $id);
        $incident->setTenant($tenant);
        $incident->setSeverity($severity);
        $incident->setRootCause($rootCause);
        return $incident;
    }
}
