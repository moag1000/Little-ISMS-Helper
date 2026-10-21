<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Entity\Tenant;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261021190000_SlaMonitorUniqueKey;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * #1059: Version20261021190000_SlaMonitorUniqueKey against the real test
 * database (MySQL/MariaDB — the dedupe is a multi-table DELETE). Duplicate
 * monitor rows are inserted while the unique key is absent, the migration's
 * up() SQL runs, and exactly one row per (entity_type, entity_id,
 * deadline_type) remains — the lowest id — and the unique index exists.
 */
final class SlaMonitorUniqueKeyMigrationTest extends KernelTestCase
{
    private const string INDEX = 'uniq_sla_entity_deadline';

    private Connection $conn;
    private ?int $tenantId = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->conn = $em->getConnection();

        $tenant = new Tenant();
        $tenant->setName('SLA unique migration ' . uniqid());
        $tenant->setCode('slau_' . uniqid());
        $em->persist($tenant);
        $em->flush();
        $this->tenantId = (int) $tenant->getId();
    }

    protected function tearDown(): void
    {
        if ($this->tenantId !== null) {
            $this->conn->executeStatement('DELETE FROM sla_deadline_monitor WHERE tenant_id = ?', [$this->tenantId]);
            $this->conn->executeStatement('DELETE FROM tenant WHERE id = ?', [$this->tenantId]);
        }
        // Never leave the shared test DB without the unique key.
        if (!$this->indexExists()) {
            $this->conn->executeStatement(sprintf(
                'CREATE UNIQUE INDEX %s ON sla_deadline_monitor (entity_type, entity_id, deadline_type)',
                self::INDEX,
            ));
        }
        $this->tenantId = null;

        parent::tearDown();
    }

    #[Test]
    public function dedupesToTheLowestIdPerKeyAndCreatesTheUniqueIndex(): void
    {
        if ($this->indexExists()) {
            $this->conn->executeStatement(sprintf('DROP INDEX %s ON sla_deadline_monitor', self::INDEX));
        }

        $a1 = $this->monitor('Incident', 900000001, 'nis2_24h');
        $a2 = $this->monitor('Incident', 900000001, 'nis2_24h');
        $a3 = $this->monitor('Incident', 900000001, 'nis2_24h');
        $other = $this->monitor('Incident', 900000001, 'nis2_72h'); // same record, other clock
        $b1 = $this->monitor('DataBreach', 900000002, 'gdpr_72h');
        $b2 = $this->monitor('DataBreach', 900000002, 'gdpr_72h');
        self::assertCount(6, $this->ids(), 'duplicates are really in the table');

        // Migrations are not autoloaded (no PSR-4 entry for DoctrineMigrations).
        require_once \dirname(__DIR__, 2) . '/migrations/Version20261021190000_SlaMonitorUniqueKey.php';
        $migration = new Version20261021190000_SlaMonitorUniqueKey($this->conn, new NullLogger());
        self::assertFalse($migration->isTransactional());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->conn->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }

        self::assertSame([$a1, $other, $b1], $this->ids(), 'one row per key, the lowest id kept');
        self::assertNotContains($a2, $this->ids());
        self::assertNotContains($a3, $this->ids());
        self::assertNotContains($b2, $this->ids());
        self::assertTrue($this->indexExists(), 'the unique index exists');

        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        $this->monitor('DataBreach', 900000002, 'gdpr_72h');
    }

    private function monitor(string $type, int $entityId, string $deadlineType): int
    {
        $this->conn->insert('sla_deadline_monitor', [
            'tenant_id' => $this->tenantId,
            'entity_type' => $type,
            'entity_id' => $entityId,
            'deadline_type' => $deadlineType,
            'triggered_at' => '2026-10-01 10:00:00',
            'deadline_at' => '2026-10-04 10:00:00',
            'notify_at_checkpoints' => '[]',
            'status' => 'active',
        ]);

        return (int) $this->conn->lastInsertId();
    }

    /** @return list<int> */
    private function ids(): array
    {
        return array_map('intval', $this->conn->fetchFirstColumn(
            'SELECT id FROM sla_deadline_monitor WHERE tenant_id = ? ORDER BY id',
            [$this->tenantId],
        ));
    }

    private function indexExists(): bool
    {
        return (int) $this->conn->fetchOne(
            "SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = 'sla_deadline_monitor' AND index_name = ? AND non_unique = 0",
            [self::INDEX],
        ) > 0;
    }
}
