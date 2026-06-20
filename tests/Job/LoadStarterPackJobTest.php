<?php

declare(strict_types=1);

namespace App\Tests\Job;

use App\Job\JobContext;
use App\Job\LoadStarterPackJob;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceMappingRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Service\Compliance\MappingSeedService;
use App\Service\ComplianceFrameworkLoaderService;
use App\Service\Job\JobStatusService;
use App\Service\ModuleConfigurationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration test for {@see LoadStarterPackJob}.
 *
 * Requires a real database (APP_ENV=test). The framework loaders and the
 * mapping seed commands run for real against the test DB — this is the whole
 * point of the test: it proves the pack loads end-to-end and, crucially, that
 * a SECOND run creates no duplicate frameworks / requirements / mappings.
 *
 * The frameworks + mappings are GLOBAL catalogue rows (not per-tenant), so the
 * test cleans them up explicitly in tearDown instead of relying on a wrapping
 * transaction — the framework loaders flush internally and a sub-Application
 * console run does not necessarily stay inside an outer test transaction.
 *
 * The `privacy` module gate is controlled by injecting a stubbed
 * {@see ModuleConfigurationService} (the real one reads a YAML config file,
 * which is impractical to toggle per-test). The privacy-OFF and privacy-ON
 * composition paths are asserted separately.
 */
#[Group('integration')]
class LoadStarterPackJobTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ComplianceFrameworkRepository $frameworkRepo;
    private ComplianceRequirementRepository $requirementRepo;
    private ComplianceMappingRepository $mappingRepo;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->frameworkRepo = $container->get(ComplianceFrameworkRepository::class);
        $this->requirementRepo = $container->get(ComplianceRequirementRepository::class);
        $this->mappingRepo = $container->get(ComplianceMappingRepository::class);

        $this->purgeComplianceCatalogue();
    }

    protected function tearDown(): void
    {
        $this->purgeComplianceCatalogue();
        parent::tearDown();
    }

    #[Test]
    public function privacyOffLoadsIsoAndBsiButNotGdprAndIsIdempotent(): void
    {
        $job = $this->makeJob(privacyActive: false);

        // First run -------------------------------------------------------
        $job->run($this->makeContext());
        $this->em->clear();

        $iso = $this->frameworkRepo->findOneBy(['code' => 'ISO27001']);
        $bsi = $this->frameworkRepo->findOneBy(['code' => 'BSI_GRUNDSCHUTZ']);
        $gdpr = $this->frameworkRepo->findOneBy(['code' => 'GDPR']);

        self::assertNotNull($iso, 'ISO27001 must be loaded');
        self::assertNotNull($bsi, 'BSI_GRUNDSCHUTZ must be loaded');
        self::assertNull($gdpr, 'GDPR must NOT be loaded when privacy module is off');

        self::assertGreaterThan(0, $iso->requirements->count(), 'ISO27001 must have requirements');
        self::assertGreaterThan(0, $bsi->requirements->count(), 'BSI must have requirements');

        $frameworksAfter1 = $this->frameworkRepo->count([]);
        $requirementsAfter1 = $this->requirementRepo->count([]);
        $mappingsAfter1 = $this->mappingRepo->count([]);

        self::assertGreaterThan(0, $mappingsAfter1, 'BSI↔ISO mappings must be seeded');

        // Second run — must be a no-op (idempotent) -----------------------
        $job->run($this->makeContext());
        $this->em->clear();

        self::assertSame(
            $frameworksAfter1,
            $this->frameworkRepo->count([]),
            'Re-running the job must not create duplicate frameworks',
        );
        self::assertSame(
            $requirementsAfter1,
            $this->requirementRepo->count([]),
            'Re-running the job must not create duplicate requirements',
        );
        self::assertSame(
            $mappingsAfter1,
            $this->mappingRepo->count([]),
            'Re-running the job must not create duplicate mappings',
        );
    }

    #[Test]
    public function privacyOnAlsoLoadsGdprAndSeedsGdprIsoMappings(): void
    {
        $job = $this->makeJob(privacyActive: true);

        $job->run($this->makeContext());
        $this->em->clear();

        $gdpr = $this->frameworkRepo->findOneBy(['code' => 'GDPR']);
        self::assertNotNull($gdpr, 'GDPR must be loaded when privacy module is active');
        self::assertGreaterThan(0, $gdpr->requirements->count(), 'GDPR must have requirements');

        // GDPR↔ISO mappings should now exist (source = GDPR requirement).
        $iso = $this->frameworkRepo->findOneBy(['code' => 'ISO27001']);
        self::assertNotNull($iso);

        $gdprSourceMappings = 0;
        // findAllGlobal(): catalogue mappings are global (not tenant-scoped).
        foreach ($this->mappingRepo->findAllGlobal() as $mapping) {
            $srcFramework = $mapping->getSourceRequirement()?->getFramework();
            if ($srcFramework !== null && $srcFramework->getCode() === 'GDPR') {
                $gdprSourceMappings++;
            }
        }
        self::assertGreaterThan(0, $gdprSourceMappings, 'GDPR↔ISO mappings must be seeded when GDPR is loaded');
    }

    private function makeJob(bool $privacyActive): LoadStarterPackJob
    {
        $container = self::getContainer();

        $moduleConfig = $this->createStub(ModuleConfigurationService::class);
        $moduleConfig->method('isModuleActive')->willReturnCallback(
            static fn(string $key): bool => $key === 'privacy' ? $privacyActive : false,
        );

        return new LoadStarterPackJob(
            $container->get(ComplianceFrameworkLoaderService::class),
            $container->get(MappingSeedService::class),
            $moduleConfig,
        );
    }

    private function makeContext(): JobContext
    {
        $statusService = self::getContainer()->get(JobStatusService::class);
        // create() mints a valid UUID v4 and a status record the context writes to.
        $jobId = $statusService->create('test.starter_pack');

        return new JobContext($jobId, $statusService, []);
    }

    /**
     * Remove the global compliance catalogue rows this test touches so the
     * test is self-contained and re-runnable. Order respects FK constraints:
     * mappings → requirements → frameworks.
     */
    private function purgeComplianceCatalogue(): void
    {
        $conn = $this->em->getConnection();
        $codes = ['ISO27001', 'BSI_GRUNDSCHUTZ', 'GDPR'];

        $conn->executeStatement(
            'DELETE m FROM compliance_mapping m
             JOIN compliance_requirement r ON m.source_requirement_id = r.id
             JOIN compliance_framework f ON r.framework_id = f.id
             WHERE f.code IN (:codes)',
            ['codes' => $codes],
            ['codes' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
        $conn->executeStatement(
            'DELETE m FROM compliance_mapping m
             JOIN compliance_requirement r ON m.target_requirement_id = r.id
             JOIN compliance_framework f ON r.framework_id = f.id
             WHERE f.code IN (:codes)',
            ['codes' => $codes],
            ['codes' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
        $conn->executeStatement(
            'DELETE r FROM compliance_requirement r
             JOIN compliance_framework f ON r.framework_id = f.id
             WHERE f.code IN (:codes)',
            ['codes' => $codes],
            ['codes' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
        $conn->executeStatement(
            'DELETE FROM compliance_framework WHERE code IN (:codes)',
            ['codes' => $codes],
            ['codes' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );

        $this->em->clear();
    }
}
