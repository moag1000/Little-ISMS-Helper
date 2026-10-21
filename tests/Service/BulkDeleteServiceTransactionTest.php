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
            $this->conn->executeStatement('DELETE FROM processing_activity WHERE tenant_id = ?', [$tenantId]);
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
