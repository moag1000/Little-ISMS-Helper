<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\AuditFinding;
use App\Entity\InternalAudit;
use App\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A finding belongs to its audit's organisation. The real write paths —
 * setAudit()/setTenant() in any order and PrePersist — keep that
 * consistent in the database; there is no PreUpdate hook (Doctrine would
 * not persist an association changed there).
 */
final class AuditFindingTenantConsistencyTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->rollback();
        }
        parent::tearDown();
    }

    #[Test]
    public function auditTenantWinsInEitherSetterOrderAndIsPersisted(): void
    {
        [$audit, $other] = $this->seed();

        $tenantFirst = $this->finding('A');
        $tenantFirst->setTenant($other)->setAudit($audit);
        $auditFirst = $this->finding('B');
        $auditFirst->setAudit($audit)->setTenant($other);
        $this->em->persist($tenantFirst);
        $this->em->persist($auditFirst);
        $this->em->flush();
        $this->em->clear();

        foreach ([$tenantFirst, $auditFirst] as $finding) {
            $reloaded = $this->em->find(AuditFinding::class, $finding->getId());
            self::assertSame($audit->getTenant()->getId(), $reloaded->getTenant()->getId());
        }
    }

    #[Test]
    public function prePersistFixesAFindingWhoseAuditWasSetViaTheCollection(): void
    {
        [$audit, $other] = $this->seed();
        $finding = $this->finding('C');
        $finding->setTenant($other);
        $audit->addStructuredFinding($finding);
        $this->em->persist($finding);
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->em->find(AuditFinding::class, $finding->getId());
        self::assertSame($audit->getTenant()->getId(), $reloaded->getTenant()->getId());
    }

    #[Test]
    public function thereIsNoMisleadingPreUpdateHook(): void
    {
        $method = new \ReflectionMethod(AuditFinding::class, 'syncTenantFromAudit');
        $events = array_map(static fn (\ReflectionAttribute $a): string => $a->getName(), $method->getAttributes());

        self::assertContains(\Doctrine\ORM\Mapping\PrePersist::class, $events);
        self::assertNotContains(\Doctrine\ORM\Mapping\PreUpdate::class, $events);
    }

    private function finding(string $n): AuditFinding
    {
        return (new AuditFinding())->setFindingNumber('F-' . $n . uniqid())->setTitle('t')->setDescription('d');
    }

    /** @return array{InternalAudit, Tenant} */
    private function seed(): array
    {
        $uid = substr(uniqid('', true), -8);
        $own = (new Tenant())->setName('Own ' . $uid)->setCode('own_' . $uid);
        $other = (new Tenant())->setName('Other ' . $uid)->setCode('oth_' . $uid);
        $this->em->persist($own);
        $this->em->persist($other);
        $audit = new InternalAudit();
        $audit->setTenant($own);
        $audit->setAuditNumber('AUD-' . $uid);
        $audit->setTitle('Audit ' . $uid);
        $audit->setScope('ISMS');
        $audit->setPlannedDate(new \DateTime());
        $this->em->persist($audit);
        $this->em->flush();

        return [$audit, $other];
    }
}
