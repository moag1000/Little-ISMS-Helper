<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ProcessingActivity;
use App\Entity\Tenant;
use App\Service\DataImportService;
use App\Service\TenantContext;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * #1059 round 3: DataImportService recovers a closed EntityManager between
 * sample imports. In a tenant user's request the recovery must keep the
 * tenant scope — a bare resetManager() dropped the tenant filter, and every
 * later query of the import read across all organisations.
 */
final class DataImportServiceRecoveryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $conn;
    /** @var list<int> */
    private array $tenantIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->conn = $this->em->getConnection();
    }

    protected function tearDown(): void
    {
        foreach ($this->tenantIds as $tenantId) {
            $this->conn->executeStatement('DELETE FROM processing_activity WHERE tenant_id = ?', [$tenantId]);
            $this->conn->executeStatement('DELETE FROM tenant WHERE id = ?', [$tenantId]);
        }
        $this->tenantIds = [];

        parent::tearDown();
    }

    #[Test]
    public function afterAFailedFlushTheImportStaysTenantScoped(): void
    {
        $own = $this->tenant('own');
        $foreign = $this->tenant('foreign');
        $ownActivity = $this->activity($own, 'contract');
        $foreignActivity = $this->activity($foreign, 'contract');
        self::getContainer()->get(TenantContext::class)->setCurrentTenant($own); // the tenant request

        // A flush the database really rejects (legal_basis is VARCHAR(50)).
        try {
            $this->activity($own, str_repeat('x', 80));
            self::fail('the insert must be rejected');
        } catch (\Doctrine\DBAL\Exception) {
        }
        self::assertFalse($this->em->isOpen());

        // The service recovers before touching the database again
        // (repairUnresolvedReferences → resetEntityManagerIfClosed()).
        self::getContainer()->get(DataImportService::class)->importSampleData([], [], $own);

        self::assertTrue($this->em->isOpen(), 'the import recovered the manager');
        $visible = array_map(
            static fn (ProcessingActivity $pa): ?int => $pa->getId(),
            $this->em->getRepository(ProcessingActivity::class)->findBy(['id' => [$ownActivity, $foreignActivity]]),
        );
        self::assertSame([$ownActivity], $visible, 'later queries are still tenant-scoped');
    }

    private function tenant(string $label): Tenant
    {
        $uid = uniqid('', true);
        $tenant = new Tenant();
        $tenant->setName('Import recovery ' . $label . ' ' . $uid);
        $tenant->setCode('irec_' . $label . '_' . $uid);
        $this->em->persist($tenant);
        $this->em->flush();
        $this->tenantIds[] = (int) $tenant->getId();

        return $tenant;
    }

    private function activity(Tenant $tenant, string $legalBasis): int
    {
        $pa = new ProcessingActivity();
        $pa->setName('Import recovery probe ' . uniqid());
        $pa->setPurposes(['x']);
        $pa->setDataSubjectCategories(['customers']);
        $pa->setPersonalDataCategories(['contact_data']);
        $pa->setLegalBasis($legalBasis);
        $pa->setTenant($tenant);
        $this->em->persist($pa);
        $this->em->flush();

        return (int) $pa->getId();
    }
}
