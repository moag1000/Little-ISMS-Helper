<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\MigrateCapaToCanonicalCommand;
use App\Entity\ChangeRequest;
use App\Entity\CorrectiveAction;
use App\Entity\Incident;
use App\Entity\Tenant;
use App\Service\AuditLogger;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Expr;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Junior-ISB-Audit-2026-05-22 M-07 Phase-1 — backfill command smoke tests.
 *
 * Covers:
 *  - Command registration (kernel-level)
 *  - inferSourceType() logic across all 4 FK combinations
 *  - Dry-run mode does NOT write
 *  - --apply mode writes + invokes AuditLogger::logBulk
 *  - Idempotency: re-running --apply does nothing on already-correct rows
 *
 * @see src/Command/MigrateCapaToCanonicalCommand.php
 * @see docs/decisions/2026-05-23-capa-canonical-process.md
 */
#[AllowMockObjectsWithoutExpectations]
final class MigrateCapaToCanonicalCommandTest extends KernelTestCase
{
    /**
     * Kernel-level: verify the command is registered with the expected name.
     */
    #[Test]
    public function commandIsRegisteredInKernel(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:migrate-capa');
        $this->assertSame('app:migrate-capa', $command->getName());
        $this->assertNotEmpty($command->getDescription());
    }

    /**
     * Unit-level: inferSourceType — finding FK populated → audit_finding.
     */
    #[Test]
    public function inferSourceTypeReturnsAuditFindingWhenFindingFkPopulated(): void
    {
        $ca = new CorrectiveAction();
        $finding = $this->makeAuditFindingStub(1);
        $ca->setFinding($finding);

        $inferred = $this->callInferSourceType($ca);
        $this->assertSame(CorrectiveAction::SOURCE_TYPE_AUDIT_FINDING, $inferred);
    }

    /**
     * Unit-level: inferSourceType — sourceIncident FK populated → incident.
     */
    #[Test]
    public function inferSourceTypeReturnsIncidentWhenSourceIncidentFkPopulated(): void
    {
        $ca = new CorrectiveAction();
        $incident = new Incident();
        $ca->setSourceIncident($incident);

        $inferred = $this->callInferSourceType($ca);
        $this->assertSame(CorrectiveAction::SOURCE_TYPE_INCIDENT, $inferred);
    }

    /**
     * Unit-level: inferSourceType — sourceChangeRequest FK populated → change_request.
     */
    #[Test]
    public function inferSourceTypeReturnsChangeRequestWhenSourceCrFkPopulated(): void
    {
        $ca = new CorrectiveAction();
        $cr = new ChangeRequest();
        $ca->setSourceChangeRequest($cr);

        $inferred = $this->callInferSourceType($ca);
        $this->assertSame(CorrectiveAction::SOURCE_TYPE_CHANGE_REQUEST, $inferred);
    }

    /**
     * Unit-level: inferSourceType — all FKs null → manual.
     */
    #[Test]
    public function inferSourceTypeReturnsManualWhenNoFkPopulated(): void
    {
        $ca = new CorrectiveAction();

        $inferred = $this->callInferSourceType($ca);
        $this->assertSame(CorrectiveAction::SOURCE_TYPE_MANUAL, $inferred);
    }

    /**
     * Unit-level: dry-run mode (default) does NOT write.
     * 3 CAPAs: 1 finding-FK, 1 incident-FK, 1 manual.
     */
    #[Test]
    public function dryRunModeDoesNotWrite(): void
    {
        $tenant = $this->makeTenant(1);

        // 1 audit-finding-sourced, 1 incident-sourced, 1 manual. All have wrong sourceType
        // (default 'audit_finding') so all three should be flagged as "would-be-updated"
        // except the audit-finding one (already correct).
        $caFinding = $this->makeCaWithFinding($tenant);
        $caIncident = $this->makeCaWithIncident($tenant);
        $caManual = $this->makeCaManual($tenant);

        $em = $this->makeEntityManagerMock([$tenant], [$caFinding, $caIncident, $caManual]);
        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->expects($this->never())->method('logBulk');

        // No flush should be issued in dry-run.
        $em->expects($this->never())->method('flush');

        $command = new MigrateCapaToCanonicalCommand($em, $auditLogger);
        $app = new ConsoleApplication();
        $app->addCommand($command);

        $tester = new CommandTester($app->find('app:migrate-capa'));
        $tester->execute([]);

        $output = $tester->getDisplay();
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('DRY-RUN', $output);
        // 2 of 3 rows have wrong sourceType (incident + manual both default to 'audit_finding')
        $this->assertStringContainsString('would be updated', $output);
    }

    /**
     * Unit-level: --apply mode writes and audit-logs the bulk-batch.
     */
    #[Test]
    public function applyModeWritesAndAuditLogsBatch(): void
    {
        $tenant = $this->makeTenant(1);
        $caFinding = $this->makeCaWithFinding($tenant);    // already correct (audit_finding)
        $caIncident = $this->makeCaWithIncident($tenant);  // needs update → incident
        $caManual = $this->makeCaManual($tenant);          // needs update → manual

        $em = $this->makeEntityManagerMock([$tenant], [$caFinding, $caIncident, $caManual]);
        // In --apply mode the command flushes once at the end of the tenant batch.
        $em->expects($this->atLeastOnce())->method('flush');

        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->expects($this->once())
            ->method('logBulk')
            ->with(
                $this->equalTo('capa.source_type_backfill'),
                $this->equalTo('CorrectiveAction'),
                $this->callback(static function ($batch) {
                    return is_array($batch) && ($batch['sprint'] ?? null) === 'M-07 Phase-1';
                }),
                $this->callback(static function ($perEntity) {
                    return is_array($perEntity) && count($perEntity) === 2; // 2 rows updated
                }),
                $this->anything()
            )
            ->willReturn('batch-uuid-stub');

        $command = new MigrateCapaToCanonicalCommand($em, $auditLogger);
        $app = new ConsoleApplication();
        $app->addCommand($command);

        $tester = new CommandTester($app->find('app:migrate-capa'));
        $tester->execute(['--apply' => true]);

        $output = $tester->getDisplay();
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('APPLY', $output);
        $this->assertStringContainsString('updated', $output);

        // Verify the post-condition: the in-memory entities now carry the inferred values.
        $this->assertSame(CorrectiveAction::SOURCE_TYPE_AUDIT_FINDING, $caFinding->getSourceType());
        $this->assertSame(CorrectiveAction::SOURCE_TYPE_INCIDENT, $caIncident->getSourceType());
        $this->assertSame(CorrectiveAction::SOURCE_TYPE_MANUAL, $caManual->getSourceType());
    }

    /**
     * Unit-level: re-running --apply on already-canonical rows is a no-op.
     */
    #[Test]
    public function applyModeIsIdempotent(): void
    {
        $tenant = $this->makeTenant(1);
        // All three already carry the correct sourceType.
        $caFinding = $this->makeCaWithFinding($tenant);
        $caIncident = $this->makeCaWithIncident($tenant);
        $caIncident->setSourceType(CorrectiveAction::SOURCE_TYPE_INCIDENT);
        $caManual = $this->makeCaManual($tenant);
        $caManual->setSourceType(CorrectiveAction::SOURCE_TYPE_MANUAL);

        $em = $this->makeEntityManagerMock([$tenant], [$caFinding, $caIncident, $caManual]);

        $auditLogger = $this->createMock(AuditLogger::class);
        // Zero updates → no logBulk call.
        $auditLogger->expects($this->never())->method('logBulk');

        $command = new MigrateCapaToCanonicalCommand($em, $auditLogger);
        $app = new ConsoleApplication();
        $app->addCommand($command);

        $tester = new CommandTester($app->find('app:migrate-capa'));
        $tester->execute(['--apply' => true]);

        $output = $tester->getDisplay();
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('updated', $output);
    }

    // ─────────────────────────── Helpers ───────────────────────────

    /**
     * Reflection helper to call the private inferSourceType() method directly.
     */
    private function callInferSourceType(CorrectiveAction $ca): string
    {
        $command = new MigrateCapaToCanonicalCommand(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(AuditLogger::class),
        );
        $ref = new ReflectionClass($command);
        $method = $ref->getMethod('inferSourceType');
        return (string) $method->invoke($command, $ca);
    }

    private function makeTenant(int $id): Tenant
    {
        $tenant = new Tenant();
        $idProperty = (new ReflectionClass($tenant))->getProperty('id');
        $idProperty->setValue($tenant, $id);
        $tenant->setName('Test-Tenant-' . $id);
        return $tenant;
    }

    private function makeAuditFindingStub(int $id): \App\Entity\AuditFinding
    {
        $finding = new \App\Entity\AuditFinding();
        $idProperty = (new ReflectionClass($finding))->getProperty('id');
        $idProperty->setValue($finding, $id);
        return $finding;
    }

    private function makeCaWithFinding(Tenant $tenant): CorrectiveAction
    {
        $ca = new CorrectiveAction();
        $ca->setTenant($tenant);
        $ca->setTitle('Test CA — Finding-sourced');
        $ca->setFinding($this->makeAuditFindingStub(100));
        // Default sourceType is 'audit_finding' — this one is already correct.
        return $ca;
    }

    private function makeCaWithIncident(Tenant $tenant): CorrectiveAction
    {
        $ca = new CorrectiveAction();
        $ca->setTenant($tenant);
        $ca->setTitle('Test CA — Incident-sourced');
        $ca->setSourceIncident(new Incident());
        // sourceType remains default 'audit_finding' — should be flagged for update.
        return $ca;
    }

    private function makeCaManual(Tenant $tenant): CorrectiveAction
    {
        $ca = new CorrectiveAction();
        $ca->setTenant($tenant);
        $ca->setTitle('Test CA — Manual');
        // No FKs set — sourceType remains default 'audit_finding' — should flag as 'manual'.
        return $ca;
    }

    /**
     * Build an EntityManager mock that:
     *   - returns the given list of tenants from Tenant::findAll()
     *   - returns the given CorrectiveActions from CorrectiveAction::findBy(['tenant' => ...])
     *   - returns 0 for the Incident / ChangeRequest count queries (we don't care here)
     *
     * @param list<Tenant> $tenants
     * @param list<CorrectiveAction> $correctiveActions
     */
    private function makeEntityManagerMock(array $tenants, array $correctiveActions): MockObject&EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);

        $tenantRepo = $this->createMock(EntityRepository::class);
        $tenantRepo->method('findAll')->willReturn($tenants);
        $tenantRepo->method('find')->willReturnCallback(static function ($id) use ($tenants) {
            foreach ($tenants as $t) {
                if ($t->getId() === (int) $id) {
                    return $t;
                }
            }
            return null;
        });

        $caRepo = $this->createMock(EntityRepository::class);
        $caRepo->method('findBy')->willReturn($correctiveActions);

        $em->method('getRepository')->willReturnCallback(static function (string $class) use ($tenantRepo, $caRepo) {
            return match ($class) {
                Tenant::class => $tenantRepo,
                CorrectiveAction::class => $caRepo,
                default => $tenantRepo, // Fallback (won't be hit in normal flow)
            };
        });

        // Stub QueryBuilder for the count queries (countIncidentCandidates, countChangeRequests).
        // QueryBuilder::getQuery() declares return type \Doctrine\ORM\Query (not the
        // abstract parent), so we mock that concrete class.
        $query = $this->createMock(Query::class);
        $query->method('getSingleScalarResult')->willReturn(0);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('expr')->willReturn(new Expr());
        $qb->method('getQuery')->willReturn($query);

        $em->method('createQueryBuilder')->willReturn($qb);
        // clear() is called when the per-batch flush boundary is hit; never reached with 3 rows.
        $em->method('clear');

        /** @var MockObject&EntityManagerInterface $em */
        return $em;
    }
}
