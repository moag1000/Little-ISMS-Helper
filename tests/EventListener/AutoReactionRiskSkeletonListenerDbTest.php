<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Entity\Risk;
use App\Entity\Tenant;
use App\Entity\Vulnerability;
use App\Service\TenantContext;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The auto-reaction listeners used to call flush() from postPersist/postUpdate,
 * which re-enters Doctrine's commit. They now queue derived rows in
 * PostFlushWriter, which writes them once the outer flush is done.
 * Real database, real listener chain.
 */
final class AutoReactionRiskSkeletonListenerDbTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $conn;
    private ?Tenant $tenant = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->conn = $this->em->getConnection();

        $uid = uniqid('', true);
        $this->tenant = new Tenant();
        $this->tenant->setName('Risk skeleton ' . $uid);
        $this->tenant->setCode('rskel_' . $uid);
        $this->em->persist($this->tenant);
        $this->em->flush();
        self::getContainer()->get(TenantContext::class)->setCurrentTenant($this->tenant);
    }

    protected function tearDown(): void
    {
        $tenantId = $this->tenant?->getId();
        if ($tenantId !== null) {
            $this->conn->executeStatement('DELETE FROM risk WHERE tenant_id = ?', [$tenantId]);
            $this->conn->executeStatement('DELETE FROM vulnerabilities WHERE tenant_id = ?', [$tenantId]);
            $this->conn->executeStatement('DELETE FROM tenant WHERE id = ?', [$tenantId]);
        }
        $this->tenant = null;

        parent::tearDown();
    }

    #[Test]
    public function criticalVulnerabilityGetsExactlyOneRiskAfterTheFlush(): void
    {
        $vuln = new Vulnerability();
        $vuln->setTenant($this->tenant);
        $vuln->setTitle('OpenSSL remote code execution');
        $vuln->setDescription('Heap overflow in the TLS handshake.');
        $vuln->setSeverity('critical');
        $vuln->setCvssScore('9.8');
        $this->em->persist($vuln);
        $this->em->flush();

        self::assertTrue($this->em->isOpen(), 'the EntityManager stays open');
        $risks = $this->em->getRepository(Risk::class)->findBy(['linkedVulnerability' => $vuln]);
        self::assertCount(1, $risks, 'the skeleton risk is written after the flush');
        self::assertNotNull($risks[0]->getId());
        self::assertSame($this->tenant?->getId(), $risks[0]->getTenant()?->getId());

        // A further write in the same request works and adds no second risk.
        $vuln->setTitle('OpenSSL remote code execution (patched build pending)');
        $this->em->flush();
        self::assertTrue($this->em->isOpen());
        self::assertCount(1, $this->em->getRepository(Risk::class)->findBy(['linkedVulnerability' => $vuln]));
    }
}
