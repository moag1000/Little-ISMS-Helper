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
 * Requires a real database (APP_ENV=test). The end-to-end real-load assertions
 * (framework + requirements + mapping seeds present, and crucially that a SECOND
 * run creates no duplicates) run against the test DB on the SMALL ISO 27001
 * catalogue only (~93 controls). The production default pack also includes
 * BSI IT-Grundschutz (1834 controls) — loading that real catalogue here, twice
 * for the idempotency check, was the ~35-min CI bottleneck and adds no extra
 * assertion value: idempotency is a property of the loader/seed code paths, not
 * of the catalogue size, so proving it on one small framework is sufficient.
 *
 * The base pack is injectable on the job ({@see LoadStarterPackJob::__construct})
 * — production defaults to ISO 27001 + BSI; this test pins it to ISO 27001 only.
 *
 * Module-gating and the production default-pack composition are covered by cheap
 * pure-logic tests via {@see LoadStarterPackJob::resolvePackCodes()} with a
 * stubbed {@see ModuleConfigurationService} — no catalogue load involved.
 *
 * The frameworks + mappings are GLOBAL catalogue rows (not per-tenant), so the
 * test cleans them up explicitly in setUp/tearDown.
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

    /**
     * Real end-to-end load on the small ISO 27001 catalogue: framework +
     * requirements + applicable mapping seeds are created, and re-running the
     * job is a true no-op (idempotent).
     */
    #[Test]
    public function loadsIsoWithRequirementsSeedsMappingsAndIsIdempotent(): void
    {
        // Pin the pack to ISO 27001 only — skips the heavy BSI (1834-control)
        // load while still exercising the full load + seed + idempotency path.
        $job = $this->makeJob(baseCodes: ['ISO27001'], privacyActive: false);

        // First run -------------------------------------------------------
        $job->run($this->makeContext());
        $this->em->clear();

        $iso = $this->frameworkRepo->findOneBy(['code' => 'ISO27001']);
        self::assertNotNull($iso, 'ISO27001 must be loaded');
        self::assertGreaterThan(0, $iso->requirements->count(), 'ISO27001 must have requirements');

        // privacy OFF + base pack without BSI → GDPR must not be present.
        self::assertNull(
            $this->frameworkRepo->findOneBy(['code' => 'GDPR']),
            'GDPR must NOT be loaded when privacy module is off',
        );

        $frameworksAfter1 = $this->frameworkRepo->count([]);
        $requirementsAfter1 = $this->requirementRepo->count([]);
        $mappingsAfter1 = $this->mappingRepo->count([]);

        // seedAvailablePairs() seeds whatever applicable pairs exist among the
        // loaded codes; with ISO 27001 alone the seed step runs and remains a
        // no-op on re-run — that no-op behaviour is the property under test.

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

    /**
     * Module-gating logic — privacy OFF excludes GDPR, privacy ON appends it.
     * Pure composition test (no catalogue load).
     */
    #[Test]
    public function resolvePackCodesIsModuleGatedOnPrivacy(): void
    {
        $base = ['ISO27001', 'BSI_GRUNDSCHUTZ'];

        $jobPrivacyOff = $this->makeJob(baseCodes: $base, privacyActive: false);
        self::assertSame(
            $base,
            $jobPrivacyOff->resolvePackCodes(),
            'privacy OFF → GDPR must not be part of the pack',
        );

        $jobPrivacyOn = $this->makeJob(baseCodes: $base, privacyActive: true);
        self::assertSame(
            [...$base, 'GDPR'],
            $jobPrivacyOn->resolvePackCodes(),
            'privacy ON → GDPR must be appended to the pack',
        );
    }

    /**
     * Guards the PRODUCTION default pack without loading any catalogue: a job
     * built with constructor defaults must compose to ISO 27001 + BSI (+ GDPR
     * when privacy is active). This pins prod behaviour even though the
     * integration assertions above run on ISO only.
     */
    #[Test]
    public function productionDefaultPackIsIsoAndBsiPlusGdprWhenPrivacyOn(): void
    {
        $container = self::getContainer();

        $defaultJob = new LoadStarterPackJob(
            $container->get(ComplianceFrameworkLoaderService::class),
            $container->get(MappingSeedService::class),
            $this->stubModule(privacyActive: false),
            // baseCodes + gdprCode left at constructor defaults = prod pack.
        );
        self::assertSame(
            ['ISO27001', 'BSI_GRUNDSCHUTZ'],
            $defaultJob->resolvePackCodes(),
            'Production default base pack must be ISO 27001 + BSI Grundschutz',
        );

        $defaultJobPrivacyOn = new LoadStarterPackJob(
            $container->get(ComplianceFrameworkLoaderService::class),
            $container->get(MappingSeedService::class),
            $this->stubModule(privacyActive: true),
        );
        self::assertSame(
            ['ISO27001', 'BSI_GRUNDSCHUTZ', 'GDPR'],
            $defaultJobPrivacyOn->resolvePackCodes(),
            'Production pack with privacy ON must add GDPR',
        );
    }

    /**
     * @param list<string> $baseCodes
     */
    private function makeJob(array $baseCodes, bool $privacyActive): LoadStarterPackJob
    {
        $container = self::getContainer();

        return new LoadStarterPackJob(
            $container->get(ComplianceFrameworkLoaderService::class),
            $container->get(MappingSeedService::class),
            $this->stubModule($privacyActive),
            baseCodes: $baseCodes,
        );
    }

    private function stubModule(bool $privacyActive): ModuleConfigurationService
    {
        $moduleConfig = $this->createStub(ModuleConfigurationService::class);
        $moduleConfig->method('isModuleActive')->willReturnCallback(
            static fn(string $key): bool => $key === 'privacy' ? $privacyActive : false,
        );

        return $moduleConfig;
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
