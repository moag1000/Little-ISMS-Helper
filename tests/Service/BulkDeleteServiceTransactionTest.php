<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ProcessingActivity;
use App\Entity\Tenant;
use App\Repository\ProcessingActivityRepository;
use App\Service\BulkDeleteService;
use App\Service\ProcessingActivityService;
use App\Service\TenantContext;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Real-DB atomicity of the bulk delete: domain deleters run their no-flush
 * path, the batch has one flush + commit, and one failing delete in the
 * middle leaves every record and no audit row behind.
 */
final class BulkDeleteServiceTransactionTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $conn;
    private BulkDeleteService $bulkDelete;
    private ProcessingActivityService $paService;
    private ProcessingActivityRepository $paRepository;
    private ?Tenant $tenant = null;
    private int $auditLogHighWater = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->conn = $this->em->getConnection();
        $this->bulkDelete = $container->get(BulkDeleteService::class);
        $this->paService = $container->get(ProcessingActivityService::class);
        $this->paRepository = $container->get(ProcessingActivityRepository::class);

        $uid = uniqid('', true);
        $this->tenant = new Tenant();
        $this->tenant->setName('Bulk delete tx ' . $uid);
        $this->tenant->setCode('bdt_' . $uid);
        $this->em->persist($this->tenant);
        $this->em->flush();
        $container->get(TenantContext::class)->setCurrentTenant($this->tenant);

        $this->auditLogHighWater = (int) $this->conn->fetchOne('SELECT COALESCE(MAX(id), 0) FROM audit_log');
    }

    protected function tearDown(): void
    {
        $tenantId = $this->tenant?->getId();
        if ($tenantId !== null) {
            foreach (['processing_activity', 'data_breach', 'data_protection_impact_assessment', 'data_subject_request', 'sla_deadline_monitor'] as $table) {
                $this->conn->executeStatement(sprintf('DELETE FROM %s WHERE tenant_id = ?', $table), [$tenantId]);
            }
            $this->conn->executeStatement('DELETE FROM audit_log WHERE id > ?', [$this->auditLogHighWater]);
            $this->conn->executeStatement('DELETE FROM tenant WHERE id = ?', [$tenantId]);
        }
        $this->tenant = null;

        parent::tearDown();
    }

    #[Test]
    public function oneFailingDeleteInTheMiddleDeletesNothingAndWritesNoAuditRow(): void
    {
        $ids = [$this->activity('First'), $this->activity('Second'), $this->activity('Third')];
        $calls = 0;

        $result = $this->bulkDelete->delete(
            'ProcessingActivity',
            $ids,
            fn (int $id): ?object => $this->paRepository->find($id),
            deleter: function (object $pa) use (&$calls): void {
                if (++$calls === 2) {
                    throw new \RuntimeException('simulated failure');
                }
                \assert($pa instanceof ProcessingActivity);
                $this->paService->delete($pa, flush: false);
            },
        );

        self::assertSame(2, $calls, 'the batch stops at the failing delete');
        self::assertSame(0, $result['deleted']);
        self::assertFalse($result['success']);
        self::assertSame(3, $this->countActivities($ids), 'nothing was deleted');
        self::assertSame(0, $this->countNewAuditRows(), 'no audit row survives the rollback');
    }

    #[Test]
    public function domainDeletersDoNotFlushBeforeTheWholeBatchIsAccepted(): void
    {
        $ids = [$this->activity('First'), $this->activity('Second'), $this->activity('Third')];
        $stillInDatabase = [];

        $result = $this->bulkDelete->delete(
            'ProcessingActivity',
            $ids,
            fn (int $id): ?object => $this->paRepository->find($id),
            deleter: function (object $pa) use ($ids, &$stillInDatabase): void {
                \assert($pa instanceof ProcessingActivity);
                $this->paService->delete($pa, flush: false);
                // Same connection, same transaction: a flush would already
                // have removed the row.
                $stillInDatabase[] = $this->countActivities($ids);
            },
        );

        self::assertSame([3, 3, 3], $stillInDatabase, 'no flush inside the batch');
        self::assertSame(3, $result['deleted']);
        self::assertSame(0, $this->countActivities($ids));
        self::assertNotNull($result['batch_id']);
        self::assertSame(
            4,
            (int) $this->conn->fetchOne(
                "SELECT COUNT(*) FROM audit_log WHERE id > ? AND entity_type = 'ProcessingActivity'",
                [$this->auditLogHighWater],
            ),
            'one logBulk batch: 1 batch entry + 3 per-entity entries',
        );
    }

    /**
     * #1059 fix round 1: the per-entity bulk row carries the domain snapshot
     * of the GDPR record (deletionAuditValues) — the breach register key,
     * the DPIA reference, the kind of data-subject request.
     */
    #[Test]
    public function gdprBulkDeleteRowsCarryTheDomainReference(): void
    {
        $container = self::getContainer();
        $breaches = $container->get(\App\Service\DataBreachService::class);
        $dpias = $container->get(\App\Service\DataProtectionImpactAssessmentService::class);
        $dsrs = $container->get(\App\Service\DataSubjectRequestService::class);

        $breach = $this->breach();
        $dpia = $this->dpia();
        $dsr = $this->dsr();

        foreach ([
            ['DataBreach', \App\Entity\DataBreach::class, $breach, $breaches],
            ['DataProtectionImpactAssessment', \App\Entity\DataProtectionImpactAssessment::class, $dpia, $dpias],
            ['DataSubjectRequest', \App\Entity\DataSubjectRequest::class, $dsr, $dsrs],
        ] as [$type, $class, $id, $service]) {
            $result = $this->bulkDelete->delete(
                $type,
                [$id],
                fn (int $i): ?object => $this->em->find($class, $i),
                deleter: static fn (object $e) => $service->delete($e, flush: false),
                auditValues: static fn (object $e): array => $service->deletionAuditValues($e),
            );
            self::assertSame(1, $result['deleted'], $type . ': ' . implode(', ', $result['errors']));
        }

        self::assertSame('DB-BDT-1', $this->auditOldValues('DataBreach', $breach)['reference_number'] ?? null);
        self::assertSame('DPIA-BDT-1', $this->auditOldValues('DataProtectionImpactAssessment', $dpia)['reference_number'] ?? null);
        $dsrValues = $this->auditOldValues('DataSubjectRequest', $dsr);
        self::assertSame('access', $dsrValues['request_type'] ?? null);
        self::assertArrayNotHasKey('data_subject_name', $dsrValues, 'no personal data of the erased request');
    }

    /**
     * A real database rollback: the removals are flushed and the audit rows
     * of the batch are written (both inside the transaction) before the audit
     * write fails. Everything must be undone and the EntityManager usable.
     */
    #[Test]
    public function auditFailureAfterTheFlushRollsBackDeletionsAndAuditRows(): void
    {
        $container = self::getContainer();
        $realLogger = $container->get(\App\Service\AuditLogger::class);
        $failingLogger = $this->createStub(\App\Service\AuditLogger::class);
        $failingLogger->method('logBulk')->willReturnCallback(static function (...$args) use ($realLogger): string {
            $realLogger->logBulk(...$args); // rows reach the DB inside the transaction
            throw new \RuntimeException('audit storage failed');
        });
        $service = new BulkDeleteService(
            $this->em,
            $failingLogger,
            $container->get(\Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface::class),
            $container->get(\Symfony\Contracts\Translation\TranslatorInterface::class),
            $container->get(TenantContext::class),
            $container->get('doctrine'),
        );

        $ids = [$this->activity('First'), $this->activity('Second'), $this->activity('Third')];
        $flushedBeforeFailure = null;
        $this->em->getEventManager()->addEventListener(\Doctrine\ORM\Events::postFlush, new class ($ids, $flushedBeforeFailure, $this) {
            /** @param list<int> $ids */
            public function __construct(private array $ids, private mixed &$seen, private BulkDeleteServiceTransactionTest $test)
            {
            }

            public function postFlush(): void
            {
                $this->seen ??= $this->test->countActivitiesPublic($this->ids);
            }
        });

        $result = $service->delete(
            'ProcessingActivity',
            $ids,
            fn (int $id): ?object => $this->paRepository->find($id),
            deleter: fn (object $pa) => $this->paService->delete($pa, flush: false),
        );

        self::assertSame(0, $flushedBeforeFailure, 'the deletions really reached the database before the failure');
        self::assertSame(0, $result['deleted']);
        self::assertStringContainsString('audit storage failed', $result['errors'][0]);
        self::assertSame(3, $this->countActivities($ids), 'the DELETEs were rolled back');
        self::assertSame(0, $this->countNewAuditRows(), 'the audit rows written inside the transaction were rolled back');
        $em = $container->get('doctrine')->getManager();
        self::assertTrue($em->isOpen());
        self::assertNotNull($em->find(\App\Entity\ProcessingActivity::class, $ids[0]), 'the EntityManager is usable afterwards');
    }

    /** @param list<int> $ids */
    public function countActivitiesPublic(array $ids): int
    {
        return $this->countActivities($ids);
    }

    /** @return array<string, mixed> */
    private function auditOldValues(string $entityType, int $id): array
    {
        $json = $this->conn->fetchOne(
            "SELECT old_values FROM audit_log WHERE id > ? AND entity_type = ? AND entity_id = ? AND action = 'delete' ORDER BY id DESC LIMIT 1",
            [$this->auditLogHighWater, $entityType, $id],
        );
        self::assertIsString($json, sprintf('per-entity audit row for %s #%d', $entityType, $id));

        return (array) json_decode($json, true);
    }

    private function breach(): int
    {
        $b = new \App\Entity\DataBreach();
        $b->setTenant($this->tenant);
        $b->setReferenceNumber('DB-BDT-1');
        $b->setTitle('Bulk delete breach');
        $b->setSeverity('high');
        $b->setDetectedAt(new \DateTimeImmutable('-1 hour'));
        $b->setDataCategories(['contact_data']);
        $b->setDataSubjectCategories(['customers']);
        $b->setBreachNature('confidentiality');
        $b->setLikelyConsequences('n/a');
        $b->setMeasuresTaken('n/a');
        $this->em->persist($b);
        $this->em->flush();

        return (int) $b->getId();
    }

    private function dpia(): int
    {
        $d = new \App\Entity\DataProtectionImpactAssessment();
        $d->setTenant($this->tenant);
        $d->setTitle('Bulk delete DPIA');
        $d->setReferenceNumber('DPIA-BDT-1');
        $d->setProcessingDescription('n/a');
        $d->setProcessingPurposes('n/a');
        $d->setDataCategories(['contact_data']);
        $d->setDataSubjectCategories(['customers']);
        $d->setNecessityAssessment('n/a');
        $d->setProportionalityAssessment('n/a');
        $d->setLegalBasis('contract');
        $d->setIdentifiedRisks([]);
        $d->setRiskLevel('low');
        $d->setTechnicalMeasures('n/a');
        $d->setOrganizationalMeasures('n/a');
        $this->em->persist($d);
        $this->em->flush();

        return (int) $d->getId();
    }

    private function dsr(): int
    {
        $r = new \App\Entity\DataSubjectRequest();
        $r->setTenant($this->tenant);
        $r->setRequestType('access');
        $r->setDataSubjectName('Erika Mustermann');
        $r->setDescription('n/a');
        $r->setReceivedAt(new \DateTimeImmutable('-1 day'));
        $r->setDeadlineAt(new \DateTimeImmutable('+29 days'));
        $this->em->persist($r);
        $this->em->flush();

        return (int) $r->getId();
    }

    private function activity(string $name): int
    {
        $pa = new ProcessingActivity();
        $pa->setName($name . ' ' . uniqid('', true));
        $pa->setPurposes(['Bulk delete test']);
        // Not a vulnerable category: keeps the auto-DPIA listener out of the fixture.
        $pa->setDataSubjectCategories(['customers']);
        $pa->setPersonalDataCategories(['contact_data']);
        $pa->setLegalBasis('contract');
        $pa->setTenant($this->tenant);
        $this->em->persist($pa);
        $this->em->flush();

        return (int) $pa->getId();
    }

    /** @param list<int> $ids */
    private function countActivities(array $ids): int
    {
        return (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM processing_activity WHERE id IN (?, ?, ?)',
            $ids,
        );
    }

    private function countNewAuditRows(): int
    {
        return (int) $this->conn->fetchOne(
            "SELECT COUNT(*) FROM audit_log WHERE id > ? AND entity_type = 'ProcessingActivity'",
            [$this->auditLogHighWater],
        );
    }
}
