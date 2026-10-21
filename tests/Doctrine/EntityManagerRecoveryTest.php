<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Doctrine\EntityManagerRecovery;
use App\Entity\ProcessingActivity;
use App\Entity\Tenant;
use App\Service\AuditLogger;
use App\Service\TenantContext;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * #1059 re-review N-1: a flush that really fails on the database closes the
 * EntityManager. A bare ManagerRegistry::resetManager() then leaves the
 * tenant filter without its parameter (every query reads across all
 * organisations) and TenantContext's cached tenant unmanaged (every later
 * audit row fails). EntityManagerRecovery restores both.
 */
final class EntityManagerRecoveryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $conn;
    /** @var list<int> */
    private array $tenantIds = [];
    private int $auditLogHighWater = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->conn = $this->em->getConnection();
        $this->auditLogHighWater = (int) $this->conn->fetchOne('SELECT COALESCE(MAX(id), 0) FROM audit_log');
    }

    protected function tearDown(): void
    {
        foreach ($this->tenantIds as $tenantId) {
            $this->conn->executeStatement('DELETE FROM processing_activity WHERE tenant_id = ?', [$tenantId]);
        }
        $this->conn->executeStatement('DELETE FROM audit_log WHERE id > ?', [$this->auditLogHighWater]);
        foreach ($this->tenantIds as $tenantId) {
            $this->conn->executeStatement('DELETE FROM tenant WHERE id = ?', [$tenantId]);
        }
        $this->tenantIds = [];

        parent::tearDown();
    }

    #[Test]
    public function afterARealFlushFailureQueriesStayTenantScopedAndAuditRowsAreWritten(): void
    {
        $own = $this->tenant('own');
        $foreign = $this->tenant('foreign');
        $ownActivity = $this->activity($own);
        $foreignActivity = $this->activity($foreign);

        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->setCurrentTenant($own); // arms the tenant filter for "own"

        // A flush the database really rejects: legal_basis is VARCHAR(50).
        $broken = new ProcessingActivity();
        $broken->setName('broken');
        $broken->setPurposes(['x']);
        $broken->setDataSubjectCategories(['customers']);
        $broken->setPersonalDataCategories(['contact_data']);
        $broken->setLegalBasis(str_repeat('x', 80));
        $broken->setTenant($own);
        $this->em->persist($broken);
        try {
            $this->em->flush();
            self::fail('the insert must be rejected by the database');
        } catch (\Doctrine\DBAL\Exception $e) {
        }
        self::assertFalse($this->em->isOpen(), 'the failed flush closed the EntityManager');

        self::getContainer()->get(EntityManagerRecovery::class)->reset();

        self::assertTrue($this->em->isOpen());
        $found = $this->em->getRepository(ProcessingActivity::class)->findBy(['id' => [$ownActivity, $foreignActivity]]);
        self::assertSame(
            [$ownActivity],
            array_map(static fn (ProcessingActivity $pa): ?int => $pa->getId(), $found),
            'the foreign organisation\'s row is not returned — the tenant filter is armed again',
        );

        $tenant = $tenantContext->getCurrentTenant();
        self::assertSame($own->getId(), $tenant?->getId());
        self::assertTrue($this->em->contains($tenant), 'the cached tenant belongs to the new unit of work');

        self::getContainer()->get(AuditLogger::class)->log('recovery_probe', 'ProcessingActivity', $ownActivity, null, ['ok' => true], 'probe', strict: true);
        self::assertSame(
            (string) $own->getId(),
            (string) $this->conn->fetchOne("SELECT tenant_id FROM audit_log WHERE id > ? AND action = 'recovery_probe'", [$this->auditLogHighWater]),
            'an audit row can be written after the recovery, with the right organisation',
        );
    }

    #[Test]
    public function aManagerThatIsStillOpenIsLeftAlone(): void
    {
        self::assertFalse(self::getContainer()->get(EntityManagerRecovery::class)->resetIfClosed());
        self::assertTrue($this->em->isOpen());
    }

    private function tenant(string $label): Tenant
    {
        $uid = uniqid('', true);
        $tenant = new Tenant();
        $tenant->setName('Recovery ' . $label . ' ' . $uid);
        $tenant->setCode('rec_' . $label . '_' . $uid);
        $this->em->persist($tenant);
        $this->em->flush();
        $this->tenantIds[] = (int) $tenant->getId();

        return $tenant;
    }

    private function activity(Tenant $tenant): int
    {
        $pa = new ProcessingActivity();
        $pa->setName('Recovery probe ' . uniqid());
        $pa->setPurposes(['x']);
        $pa->setDataSubjectCategories(['customers']);
        $pa->setPersonalDataCategories(['contact_data']);
        $pa->setLegalBasis('contract');
        $pa->setTenant($tenant);
        $this->em->persist($pa);
        $this->em->flush();

        return (int) $pa->getId();
    }
}
