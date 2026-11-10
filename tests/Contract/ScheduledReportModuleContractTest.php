<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use App\Entity\ScheduledReport;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\ModuleConfigurationService;
use App\Service\Report\ScheduledReportRunner;
use App\Service\Report\ScheduledReportSkippedException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * S32 durable test: a scheduled report never outlives its module. For every type in ScheduledReport::TYPE_MODULES
 * (a new type with a module is covered by itself), switching the module off for the organisation makes the next run
 * a skip with a note — nothing generated, nothing mailed, next run the regular one — and the preview refuses too.
 * Besides: one organisation's failing report does not stop another's, and a failure waits with back-off.
 */
#[Group('integration')]
final class ScheduledReportModuleContractTest extends KernelTestCase
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

    /** @return iterable<string, array{string, string}> */
    public static function typesWithModule(): iterable
    {
        foreach (ScheduledReport::TYPE_MODULES as $type => $module) {
            yield $type => [$type, $module];
        }
    }

    #[Test]
    #[DataProvider('typesWithModule')]
    public function aReportOfASwitchedOffModuleIsSkipped(string $type, string $module): void
    {
        $modules = self::getContainer()->get(ModuleConfigurationService::class);
        self::assertArrayHasKey($module, $modules->getAllModules(), sprintf('TYPE_MODULES names unknown module "%s".', $module));

        $tenant = $this->tenant(array_values(array_diff(array_keys($modules->getAllModules()), [$module])));
        $report = $this->report($tenant, $type, ['nobody@example.test']);
        $this->em->flush();

        $runner = self::getContainer()->get(ScheduledReportRunner::class);
        [$outcome, $message] = $runner->run($report);

        self::assertSame(ScheduledReportRunner::OUTCOME_SKIPPED, $outcome);
        self::assertSame(ScheduledReportRunner::SKIP_MODULE, $message);
        self::assertSame(ScheduledReport::RUN_SKIPPED, $report->getLastRunStatus());
        self::assertSame(0, $report->getFailedRuns());
        self::assertGreaterThan(new \DateTime(), $report->getNextRunAt(), 'the skipped report waits for its regular run');

        $this->expectException(ScheduledReportSkippedException::class);
        $runner->preview($report);
    }

    #[Test]
    public function aFailureInOneOrganisationDoesNotStopAnother(): void
    {
        $modules = array_keys(self::getContainer()->get(ModuleConfigurationService::class)->getAllModules());
        $failing = $this->report($this->tenant($modules), ScheduledReport::TYPE_EXECUTIVE, ['ghost@example.test']);
        $skipped = $this->report($this->tenant([]), ScheduledReport::TYPE_RISK, ['ghost@example.test']);
        $this->em->flush();

        $results = self::getContainer()->get(ScheduledReportRunner::class)->processDueReports();
        $byId = array_column($results['details'], 'status', 'id');

        self::assertSame(ScheduledReportRunner::OUTCOME_FAILED, $byId[$failing->getId()] ?? null);
        self::assertSame(ScheduledReportRunner::OUTCOME_SKIPPED, $byId[$skipped->getId()] ?? null);

        $failing = $this->em->find(ScheduledReport::class, $failing->getId());
        self::assertSame(ScheduledReportRunner::MSG_NO_RECIPIENTS, $failing->getLastRunMessage(), 'no exception text');
        self::assertSame(1, $failing->getFailedRuns());
        self::assertLessThanOrEqual((new \DateTime())->modify('+1 hour +1 minute'), $failing->getNextRunAt(), 'first retry after an hour');
        self::assertGreaterThan(new \DateTime(), $failing->getNextRunAt(), 'not again in this run');
    }

    #[Test]
    public function aCreatorWhoLostTheManagerRoleStopsTheSchedule(): void
    {
        $modules = array_keys(self::getContainer()->get(ModuleConfigurationService::class)->getAllModules());
        $tenant = $this->tenant($modules);
        $creator = (new User())->setEmail('s32_' . uniqid() . '@example.test')->setFirstName('C')->setLastName('R')
            ->setRoles(['ROLE_USER'])->setPassword('x')->setTenant($tenant)->setIsActive(true);
        $this->em->persist($creator);
        $report = $this->report($tenant, ScheduledReport::TYPE_EXECUTIVE, ['ghost@example.test'])->setCreatedBy($creator);
        $this->em->flush();

        self::assertSame(
            ScheduledReportRunner::SKIP_CREATOR,
            self::getContainer()->get(ScheduledReportRunner::class)->skipReason($report),
        );
    }

    /** @param list<string> $modules */
    private function tenant(array $modules): Tenant
    {
        $suffix = substr(uniqid(), -8);
        $tenant = (new Tenant())->setName('S32 ' . $suffix)->setCode('s32_' . $suffix);
        $tenant->setIsActive(true);
        $tenant->setActiveModules($modules);
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    /** @param list<string> $recipients */
    private function report(Tenant $tenant, string $type, array $recipients): ScheduledReport
    {
        $report = (new ScheduledReport())
            ->setName('S32 ' . $type)
            ->setReportType($type)
            ->setSchedule(ScheduledReport::SCHEDULE_DAILY)
            ->setFormat(ScheduledReport::FORMAT_PDF)
            ->setRecipients($recipients)
            ->setIsActive(true)
            ->setTenantId($tenant->getId())
            ->setNextRunAt((new \DateTime())->modify('-5 minutes'));
        $this->em->persist($report);

        return $report;
    }
}
