<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Risk;
use App\Entity\Tenant;
use App\Enum\RiskStatus;
use App\Service\ReviewReminderService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Regression: ReviewReminderService read risks (findAll) and the other
 * entities (findBy without tenant) unscoped, and the cron command called it
 * once without a tenant loop. Outside an HTTP request the tenant_filter is
 * not armed, so reminders and statistics mixed every organisation's records.
 * The service now takes an explicit tenant and the command loops active
 * tenants.
 */
#[Group('integration')]
final class SendReviewRemindersTenantScopeTest extends KernelTestCase
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
    public function overdueRiskReviewsStayWithinTheirTenant(): void
    {
        [$tenantA, $riskA] = $this->seedTenantWithOverdueRisk('A');
        [, $riskB] = $this->seedTenantWithOverdueRisk('B');

        $service = self::getContainer()->get(ReviewReminderService::class);
        $overdue = $service->getOverdueRiskReviews($tenantA);
        $ids = array_map(static fn(Risk $r): ?int => $r->getId(), array_values($overdue));

        self::assertContains($riskA->getId(), $ids);
        self::assertNotContains($riskB->getId(), $ids);
        self::assertSame(1, $service->getDashboardStatistics($tenantA)['by_type']['risks']);
    }

    #[Test]
    public function commandReportsEachTenantSeparately(): void
    {
        [$tenantA] = $this->seedTenantWithOverdueRisk('A');
        [$tenantB] = $this->seedTenantWithOverdueRisk('B');

        $tester = new CommandTester((new Application(self::$kernel))->find('app:review:send-reminders'));
        $exitCode = $tester->execute(['--stats-only' => true]);
        $display = $tester->getDisplay();

        self::assertSame(0, $exitCode, $display);
        self::assertStringContainsString('By Organisation', $display);
        self::assertMatchesRegularExpression('/' . preg_quote((string) $tenantA->getName(), '/') . '\s+1\s+0/', $display);
        self::assertMatchesRegularExpression('/' . preg_quote((string) $tenantB->getName(), '/') . '\s+1\s+0/', $display);
    }

    /**
     * @return array{Tenant, Risk}
     */
    private function seedTenantWithOverdueRisk(string $label): array
    {
        $suffix = $label . substr(uniqid(), -6);
        $tenant = new Tenant();
        $tenant->setName('Reminder ' . $suffix);
        $tenant->setCode('rem_' . strtolower($suffix));
        $tenant->setIsActive(true);
        $this->em->persist($tenant);

        $risk = new Risk();
        $risk->setTitle('Overdue ' . $suffix)->setCategory('security')->setProbability(1)->setImpact(1)
            ->setDescription('d')->setStatus(RiskStatus::Assessed)->setTenant($tenant)
            ->setReviewDate(new \DateTime('-10 days'));
        $this->em->persist($risk);
        $this->em->flush();

        return [$tenant, $risk];
    }
}
