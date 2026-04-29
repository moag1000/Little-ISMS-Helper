<?php

declare(strict_types=1);

namespace App\Tests\E2e;

use App\Command\LoadIso27001RequirementsCommand;
use App\Command\LoadNis2RequirementsCommand;
use App\Entity\ComplianceFramework;
use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use App\Entity\ComplianceRequirementFulfillment;
use App\Entity\FulfillmentInheritanceLog;
use App\Entity\ImportRowEvent;
use App\Entity\ImportSession;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\ComplianceRequirementFulfillmentRepository;
use App\Repository\ImportRowEventRepository;
use App\Service\ComplianceInheritanceService;
use App\Service\GapEffortCalculator;
use App\Service\Import\ImportSessionRecorder;
use App\Service\PortfolioReportService;
use App\Service\TenantContext;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use PHPUnit\Framework\Attributes\Test;

/**
 * End-to-end acceptance test for the DATA_REUSE_IMPROVEMENT_PLAN v1.1 journey.
 *
 * Covers the six-step CISO acceptance gate described in
 * docs/DATA_REUSE_IMPROVEMENT_PLAN.md lines 463-471:
 *
 *   1. Fresh tenant with core + assets + controls + compliance modules active
 *   2. Load ISO 27001 framework, set 30 requirements implemented + 20 partial
 *   3. Activate NIS2 framework -> >=40 fulfillments get derivedFrom set
 *   4. Cross-framework portfolio report shows both frameworks
 *   5. Override 5 fulfillments -> inheritance metadata preserved, reason stored
 *   6. Gap report total effort sum plausible (>0, < absurd cap)
 *
 * The whole journey runs in one test method (per plan wording) and is
 * wrapped in a transaction that rolls back on tearDown for DB hermeticity.
 */
final class ComplianceReuseJourneyTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Tenant $tenant;
    private User $actor;
    private User $approver;

    protected function setUp(): void
    {
        // Dark-launched inheritance flag — bypass by setting env.
        // Services read via CompliancePolicyService defaults; this flag is
        // checked by ComplianceFrameworkActivationService (not exercised
        // directly in this test: we call ComplianceInheritanceService
        // directly, which has no activation gate).
        $_ENV['COMPLIANCE_MAPPING_INHERITANCE_ENABLED'] = '1';
        $_SERVER['COMPLIANCE_MAPPING_INHERITANCE_ENABLED'] = '1';

        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em = $em;

        // Transaction for hermeticity — rolled back on tearDown().
        $this->em->getConnection()->beginTransaction();

        // Unique tenant code keeps concurrent runs safe even if rollback skipped.
        $suffix = substr((string) bin2hex(random_bytes(4)), 0, 8);

        $this->tenant = (new Tenant())
            ->setCode('e2e-reuse-' . $suffix)
            ->setName('E2E Reuse Journey Tenant ' . $suffix)
            ->setDescription('Tenant for ComplianceReuseJourneyTest — core+assets+controls+compliance modules active')
            ->setSettings([
                'active_modules' => ['core', 'assets', 'controls', 'compliance'],
            ]);
        $this->em->persist($this->tenant);

        $this->actor = (new User())
            ->setEmail('ciso-e2e-' . $suffix . '@example.test')
            ->setFirstName('CISO')
            ->setLastName('Acceptance')
            ->setRoles(['ROLE_ADMIN'])
            ->setTenant($this->tenant)
            ->setAuthProvider('local');
        $this->em->persist($this->actor);

        $this->approver = (new User())
            ->setEmail('approver-e2e-' . $suffix . '@example.test')
            ->setFirstName('Second')
            ->setLastName('Approver')
            ->setRoles(['ROLE_ADMIN'])
            ->setTenant($this->tenant)
            ->setAuthProvider('local');
        $this->em->persist($this->approver);

        $this->em->flush();

        // TenantContext is used by FourEyesApprovalService during override.
        /** @var TenantContext $tenantContext */
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->setCurrentTenant($this->tenant);
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            $connection = $this->em->getConnection();
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $this->em->clear();
        }

        parent::tearDown();
    }

    #[Test]
    public function testFullReuseJourney(): void
    {
        // ── Step 1 — Fresh tenant + modules already set up in setUp() ─────────
        self::assertSame(
            ['core', 'assets', 'controls', 'compliance'],
            $this->tenant->getSettings()['active_modules'] ?? [],
            'Tenant should have core+assets+controls+compliance modules active.',
        );

        // ── Step 2 — Load ISO 27001 framework + 30 implemented + 20 partial ──
        $iso = $this->loadIsoFramework();
        $isoRequirements = $this->em->getRepository(ComplianceRequirement::class)
            ->findBy(['framework' => $iso], ['requirementId' => 'ASC']);
        self::assertGreaterThanOrEqual(
            70,
            count($isoRequirements),
            'ISO 27001 loader must produce Annex A (expected >=70 requirements).',
        );

        $implementedIsoReqs = array_slice($isoRequirements, 0, 30);
        $partialIsoReqs = array_slice($isoRequirements, 30, 20);

        foreach ($implementedIsoReqs as $req) {
            $this->upsertFulfillment($req, percentage: 100, applicable: true);
        }
        foreach ($partialIsoReqs as $req) {
            $this->upsertFulfillment($req, percentage: 50, applicable: true);
        }
        $this->em->flush();

        // ── Step 3 — Activate NIS2 framework + wire mappings (ISO27001 → NIS2)
        $nis2 = $this->loadNis2Framework();
        $nis2Requirements = $this->em->getRepository(ComplianceRequirement::class)
            ->findBy(['framework' => $nis2], ['requirementId' => 'ASC']);
        self::assertGreaterThanOrEqual(
            40,
            count($nis2Requirements),
            'NIS2 loader must produce enough requirements for the inheritance batch (>=40).',
        );

        // Seed WS-6 effort estimates on NIS2 requirements so the gap report
        // returns a non-zero remaining_effort_days total.
        foreach ($nis2Requirements as $index => $req) {
            $req->setBaseEffortDays(($index % 5) + 2); // 2..6 person-days
        }
        $this->em->flush();

        // Create mappings with the direction the inheritance service expects:
        // source = ISO27001 requirement, target = NIS2 requirement.
        // (Fixture CSVs encode the reverse direction, which wouldn't trigger
        // the NIS2-as-target iteration inside createInheritanceSuggestions.)
        $fulfilledIsoPool = array_merge($implementedIsoReqs, $partialIsoReqs);
        $mappingsCreated = 0;
        foreach ($nis2Requirements as $i => $nis2Req) {
            $isoReq = $fulfilledIsoPool[$i % count($fulfilledIsoPool)];
            $mapping = (new ComplianceMapping())
                ->setSourceRequirement($isoReq)
                ->setTargetRequirement($nis2Req)
                ->setMappingPercentage(80)
                ->setMappingType('partial')
                ->setConfidence('high')
                ->setBidirectional(false)
                ->setMappingRationale('E2E test mapping: ISO27001 → NIS2 inheritance candidate.')
                ->setSource('e2e_reuse_journey_test')
                ->setVersion(1)
                ->setValidFrom(new DateTimeImmutable('-1 day'));
            $this->em->persist($mapping);
            $mappingsCreated++;
        }
        $this->em->flush();

        // Run the inheritance service directly — bypasses the activation-gate
        // feature flag (ComplianceFrameworkActivationService) and exercises
        // the behaviour the plan requires.
        /** @var ComplianceInheritanceService $inheritanceService */
        $inheritanceService = self::getContainer()->get(ComplianceInheritanceService::class);
        $result = $inheritanceService->createInheritanceSuggestions($this->tenant, $nis2, $this->actor);

        self::assertGreaterThanOrEqual(
            40,
            $result['created'],
            sprintf(
                'Plan requires >=40 NIS2 fulfillments with derivedFrom after activation (got %d, mappings=%d).',
                $result['created'],
                $mappingsCreated,
            ),
        );

        // Assert derivedFrom actually stored on the logs.
        $pendingLogs = $this->em->getRepository(FulfillmentInheritanceLog::class)
            ->findBy(['tenant' => $this->tenant, 'reviewStatus' => FulfillmentInheritanceLog::STATUS_PENDING_REVIEW]);
        self::assertGreaterThanOrEqual(40, count($pendingLogs));
        foreach ($pendingLogs as $log) {
            self::assertInstanceOf(
                ComplianceMapping::class,
                $log->getDerivedFromMapping(),
                'Each inheritance log must carry a derivedFromMapping reference.',
            );
        }

        // ── Step 4 — Cross-framework portfolio matrix ────────────────────────
        /** @var PortfolioReportService $portfolioService */
        $portfolioService = self::getContainer()->get(PortfolioReportService::class);
        $matrix = $portfolioService->buildMatrix($this->tenant, new DateTimeImmutable('now'));

        $frameworkCodes = array_column($matrix['frameworks'], 'code');
        self::assertContains('ISO27001', $frameworkCodes, 'Portfolio matrix must list ISO27001.');
        self::assertContains('NIS2', $frameworkCodes, 'Portfolio matrix must list NIS2.');

        // At least one cell with a plausible percentage (0..100) must exist for ISO27001.
        $anyPlausibleIsoPct = false;
        foreach ($matrix['rows'] as $row) {
            $cell = $row['cells']['ISO27001'] ?? null;
            if ($cell !== null && $cell['count'] > 0) {
                self::assertGreaterThanOrEqual(0, $cell['pct']);
                self::assertLessThanOrEqual(100, $cell['pct']);
                $anyPlausibleIsoPct = true;
            }
        }
        self::assertTrue(
            $anyPlausibleIsoPct,
            'At least one ISO27001 matrix cell must report a plausible percentage.',
        );

        // ── Step 5 — Override 5 fulfillments, preserve derivedFrom metadata ──
        $logsToOverride = array_slice($pendingLogs, 0, 5);
        self::assertCount(5, $logsToOverride, 'Need 5 pending logs to simulate the consultant override.');

        $overrideReason = 'Override via consultant Excel import — evidence collected in audit folder, WS-2 import stepper row 42.';

        foreach ($logsToOverride as $log) {
            $mappingBefore = $log->getDerivedFromMapping();
            self::assertInstanceOf(ComplianceMapping::class, $mappingBefore);

            $inheritanceService->overrideInheritance(
                log: $log,
                reviewer: $this->actor,
                newValue: 75,
                reason: $overrideReason,
                fourEyesApprover: $this->approver,
            );

            self::assertSame(
                FulfillmentInheritanceLog::STATUS_OVERRIDDEN,
                $log->getReviewStatus(),
                'Override must transition the log to STATUS_OVERRIDDEN.',
            );
            self::assertSame(
                $overrideReason,
                $log->getOverrideReason(),
                'Override reason must be persisted on the log.',
            );
            self::assertSame(75, $log->getOverrideValue());
            self::assertSame(
                $mappingBefore->getId(),
                $log->getDerivedFromMapping()?->getId(),
                'derivedFromMapping metadata must be preserved through override.',
            );
        }
        $this->em->flush();
        $this->em->clear();

        // Re-fetch a sample and re-assert after clear() to ensure persistence.
        $reloaded = $this->em->getRepository(FulfillmentInheritanceLog::class)
            ->findBy(['tenant' => $this->em->getReference(Tenant::class, $this->tenant->getId()),
                      'reviewStatus' => FulfillmentInheritanceLog::STATUS_OVERRIDDEN]);
        self::assertCount(5, $reloaded, 'Exactly 5 logs should be in STATUS_OVERRIDDEN after the batch.');
        foreach ($reloaded as $log) {
            self::assertNotNull(
                $log->getDerivedFromMapping(),
                'Reloaded overridden log must still carry the derivedFromMapping.',
            );
            self::assertSame($overrideReason, $log->getOverrideReason());
        }

        // ── Step 6 — Gap report total effort plausible ───────────────────────
        /** @var GapEffortCalculator $gapCalculator */
        $gapCalculator = self::getContainer()->get(GapEffortCalculator::class);
        $nis2Reloaded = $this->em->getRepository(ComplianceFramework::class)->find($nis2->id);
        self::assertInstanceOf(ComplianceFramework::class, $nis2Reloaded);

        $totals = $gapCalculator->calculateTotalEffort(
            $this->em->getRepository(Tenant::class)->find($this->tenant->getId()),
            $nis2Reloaded,
        );

        self::assertGreaterThan(
            0.0,
            $totals['remaining_effort_days'],
            'Gap report total effort must be > 0 (NIS2 requirements have base_effort_days seeded).',
        );
        self::assertLessThan(
            10000.0,
            $totals['remaining_effort_days'],
            'Gap report total effort must be within a sane cap (<10000 person-days).',
        );
        self::assertGreaterThan(0, $totals['estimated_count']);

        // ── Step 7 — ISB MINOR-1 per-row audit trail retrievable ─────────────
        // Simulate a tiny import for one of the existing mappings and verify
        // the ImportRowEvent is queryable via findByTarget().
        // Reload tenant + actor after the earlier em->clear().
        $tenantReloaded = $this->em->getRepository(Tenant::class)->find($this->tenant->getId());
        $actorReloaded = $this->em->getRepository(User::class)->find($this->actor->getId());
        self::assertInstanceOf(Tenant::class, $tenantReloaded);
        self::assertInstanceOf(User::class, $actorReloaded);

        $sampleMapping = $this->em->getRepository(ComplianceMapping::class)
            ->findOneBy(['source' => 'e2e_reuse_journey_test']);
        self::assertInstanceOf(
            ComplianceMapping::class,
            $sampleMapping,
            'Expected at least one e2e-seeded ComplianceMapping to exist.',
        );

        /** @var ImportSessionRecorder $recorder */
        $recorder = self::getContainer()->get(ImportSessionRecorder::class);
        $fixtureDir = sys_get_temp_dir() . '/lih-e2e-import-' . bin2hex(random_bytes(3));
        if (!is_dir($fixtureDir)) {
            mkdir($fixtureDir, 0700, true);
        }
        $fixtureFile = $fixtureDir . '/e2e-minor1.csv';
        file_put_contents($fixtureFile, "source_framework\nISO27001\n");

        $importSession = $recorder->openSession(
            $fixtureFile,
            ImportSession::FORMAT_CSV,
            'e2e-minor1.csv',
            $actorReloaded,
            $tenantReloaded,
        );
        $recorder->recordRow(
            $importSession, 1, ImportRowEvent::DECISION_IMPORT,
            'ComplianceMapping', $sampleMapping->getId(),
            null,
            ['mapping_percentage' => $sampleMapping->getMappingPercentage()],
            ['source_framework' => 'ISO27001'],
            null,
        );
        $recorder->closeSession($importSession, ImportSession::STATUS_COMMITTED);

        /** @var ImportRowEventRepository $eventRepo */
        $eventRepo = $this->em->getRepository(ImportRowEvent::class);
        $matches = $eventRepo->findByTarget('ComplianceMapping', (int) $sampleMapping->getId());
        self::assertNotEmpty(
            $matches,
            'ISB MINOR-1: findByTarget() must return the recorded ImportRowEvent.',
        );
        self::assertSame(
            1,
            $matches[0]->getLineNumber(),
            'ISB MINOR-1: line number must be preserved on the row event.',
        );

        @unlink($fixtureFile);
        @rmdir($fixtureDir);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function loadIsoFramework(): ComplianceFramework
    {
        /** @var LoadIso27001RequirementsCommand $cmd */
        $cmd = self::getContainer()->get(LoadIso27001RequirementsCommand::class);
        $io = new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
        $cmd->__invoke(false, $io);

        $framework = $this->em->getRepository(ComplianceFramework::class)->findOneBy(['code' => 'ISO27001']);
        self::assertInstanceOf(ComplianceFramework::class, $framework, 'ISO27001 framework must exist after loader.');

        return $framework;
    }

    private function loadNis2Framework(): ComplianceFramework
    {
        /** @var LoadNis2RequirementsCommand $cmd */
        $cmd = self::getContainer()->get(LoadNis2RequirementsCommand::class);
        $io = new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
        $cmd->__invoke(false, $io);

        $framework = $this->em->getRepository(ComplianceFramework::class)->findOneBy(['code' => 'NIS2']);
        self::assertInstanceOf(ComplianceFramework::class, $framework, 'NIS2 framework must exist after loader.');

        return $framework;
    }

    private function upsertFulfillment(
        ComplianceRequirement $requirement,
        int $percentage,
        bool $applicable,
    ): ComplianceRequirementFulfillment {
        /** @var ComplianceRequirementFulfillmentRepository $repo */
        $repo = $this->em->getRepository(ComplianceRequirementFulfillment::class);
        $existing = $repo->findOneBy(['tenant' => $this->tenant, 'requirement' => $requirement]);
        if ($existing instanceof ComplianceRequirementFulfillment) {
            $existing->setFulfillmentPercentage($percentage);
            $existing->setApplicable($applicable);
            return $existing;
        }

        $f = (new ComplianceRequirementFulfillment())
            ->setTenant($this->tenant)
            ->setRequirement($requirement)
            ->setApplicable($applicable)
            ->setFulfillmentPercentage($percentage)
            ->setStatus($percentage >= 100 ? 'implemented' : 'in_progress');
        $this->em->persist($f);

        return $f;
    }
}
