<?php

declare(strict_types=1);

namespace App\Tests\Service\DataRepair;

use App\Entity\Tag;
use App\Entity\Tenant;
use App\Service\DataRepair\OrphanReassigner;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * #1059 re-review 2, m-R4: the orphan loops (AssignOrphansJob,
 * FixAllOrphansJob, DataRepairController::fixAllOrphans) wrote an
 * `orphan_reassigned` audit row and counted the entity even when the change
 * was never stored (failed flush, or an entity of the dead unit of work after
 * a reset). OrphanReassigner commits the change and its audit row together.
 * Real database, real rejection (tag.name is VARCHAR(100)).
 */
final class OrphanReassignerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $conn;
    private ?Tenant $tenant = null;
    /** @var list<int> */
    private array $tagIds = [];
    private int $auditLogHighWater = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->conn = $this->em->getConnection();
        $this->auditLogHighWater = (int) $this->conn->fetchOne('SELECT COALESCE(MAX(id), 0) FROM audit_log');

        $this->tenant = new Tenant();
        $this->tenant->setName('Orphan reassign ' . uniqid());
        $this->tenant->setCode('orph_' . uniqid());
        $this->em->persist($this->tenant);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        foreach ($this->tagIds as $id) {
            $this->conn->executeStatement('DELETE FROM tag WHERE id = ?', [$id]);
        }
        $this->conn->executeStatement('DELETE FROM audit_log WHERE id > ?', [$this->auditLogHighWater]);
        if ($this->tenant?->getId() !== null) {
            $this->conn->executeStatement('DELETE FROM tenant WHERE id = ?', [$this->tenant->getId()]);
        }
        $this->tenant = null;
        $this->tagIds = [];

        parent::tearDown();
    }

    #[Test]
    public function onlyStoredReassignmentsAreAuditedAndCounted(): void
    {
        $reassigner = self::getContainer()->get(OrphanReassigner::class);
        $first = $this->orphanTag();
        $broken = $this->orphanTag();
        $afterTheFailure = $this->orphanTag(); // loaded into the unit of work BEFORE the failure

        self::assertTrue($reassigner->reassign($first, $this->tenant));

        $broken->setName(str_repeat('x', 150)); // the database rejects this flush
        self::assertFalse($reassigner->reassign($broken, $this->tenant), 'a rejected change is not counted');
        self::assertTrue($this->em->isOpen(), 'the closed manager was recovered');

        // An entity of the dead unit of work is re-loaded and really stored.
        self::assertTrue($reassigner->reassign($afterTheFailure, $this->tenant));

        $tenantId = (string) $this->tenant?->getId();
        self::assertSame($tenantId, (string) $this->tenantOf($first));
        self::assertNull($this->tenantOf($broken), 'the rejected reassignment is not stored');
        self::assertSame($tenantId, (string) $this->tenantOf($afterTheFailure));

        self::assertSame(1, $this->auditRows($first));
        self::assertSame(0, $this->auditRows($broken), 'no audit row for a change that was not stored');
        self::assertSame(1, $this->auditRows($afterTheFailure));
    }

    private function orphanTag(): Tag
    {
        $tag = new Tag();
        $tag->setName('orphan ' . uniqid('', true));
        $tag->setType('custom');
        $tag->setColor('secondary');
        $tag->setTenant(null);
        $this->em->persist($tag);
        $this->em->flush();
        $this->tagIds[] = (int) $tag->getId();

        return $tag;
    }

    private function tenantOf(Tag $tag): mixed
    {
        return $this->conn->fetchOne('SELECT tenant_id FROM tag WHERE id = ?', [$tag->getId()]);
    }

    private function auditRows(Tag $tag): int
    {
        return (int) $this->conn->fetchOne(
            "SELECT COUNT(*) FROM audit_log WHERE id > ? AND action = 'admin.data_repair.orphan_reassigned' AND entity_type = 'Tag' AND entity_id = ?",
            [$this->auditLogHighWater, $tag->getId()],
        );
    }
}
