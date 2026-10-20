<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\InternalAudit;
use App\Entity\Tenant;
use App\Service\ScheduledReportService;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Scheduled board reports run in a cron (no user). The management KPIs were
 * computed without a tenant and cached under one shared "global" key, so the
 * second organisation's report in the same run received the first one's
 * figures. Two organisations, one process, warm cache: each report carries
 * its own organisation's audit figures.
 */
#[Group('integration')]
final class ScheduledBoardReportTenantScopeTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    /** @var list<string> */
    private array $cacheKeys = ['management_kpis_global', 'management_kpis_instance'];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        $cache = self::getContainer()->get(CacheInterface::class);
        foreach ($this->cacheKeys as $key) {
            $cache->delete($key);
        }
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->rollback();
        }
        parent::tearDown();
    }

    #[Test]
    public function boardReportsOfTwoOrganisationsCarryTheirOwnKpis(): void
    {
        $tenantA = $this->tenant('a', 2);
        $tenantB = $this->tenant('b', 1);
        $this->em->flush();

        self::assertSame(2, $this->plannedAuditsInBoardReport($tenantA));
        self::assertSame(1, $this->plannedAuditsInBoardReport($tenantB));
    }

    private function plannedAuditsInBoardReport(Tenant $tenant): int
    {
        $this->cacheKeys[] = 'management_kpis_' . $tenant->getId();
        self::getContainer()->get(TenantContext::class)->setCurrentTenant($tenant);
        $service = self::getContainer()->get(ScheduledReportService::class);
        $data = (new \ReflectionMethod($service, 'getBoardReportData'))->invoke($service);

        return (int) ($data['kpis']['audits']['planned_audits']['value'] ?? -1);
    }

    private function tenant(string $label, int $plannedAudits): Tenant
    {
        $suffix = substr(uniqid(), -8);
        $tenant = (new Tenant())->setName('Board ' . $label . ' ' . $suffix)->setCode('board_' . $label . '_' . $suffix);
        $tenant->setIsActive(true);
        $this->em->persist($tenant);
        for ($i = 0; $i < $plannedAudits; ++$i) {
            $audit = new InternalAudit();
            $audit->setTenant($tenant);
            $audit->setAuditNumber('B-' . $suffix . '-' . $i);
            $audit->setTitle('Board audit ' . $i);
            $audit->setPlannedDate(new \DateTime());
            $audit->setStatus('planned'); // @phpstan-ignore lifecycle.directSetStatus (test fixture)
            $this->em->persist($audit);
        }

        return $tenant;
    }
}
