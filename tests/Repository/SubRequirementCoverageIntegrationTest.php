<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use App\Repository\ComplianceMappingRepository;
use App\Repository\ComplianceRequirementRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Regression tests for the two CRITICAL correctness bugs the EU-mapping
 * sub-level decomposition import introduced into compliance coverage:
 *
 *  BUG 1 — coverage dilution: framework compliance-% denominators must count
 *          ONLY top-level requirements (requirementType IN ('core','detailed'),
 *          parentRequirement IS NULL). Imported `sub_requirement` rows roll up
 *          via their parent and must NOT be separate denominator entries.
 *
 *  BUG 2 — draft mappings must not drive coverage: only mappings whose
 *          lifecycleState is operational (approved/published) may contribute.
 *          The ~7000 imported `draft` mappings must be excluded.
 *
 * Requires a real database (APP_ENV=test with configured DATABASE_URL).
 * Run with: php bin/phpunit --group integration tests/Repository/SubRequirementCoverageIntegrationTest.php
 */
#[Group('integration')]
class SubRequirementCoverageIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ComplianceRequirementRepository $requirementRepository;
    private ComplianceMappingRepository $mappingRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->requirementRepository = self::getContainer()->get(ComplianceRequirementRepository::class);
        $this->mappingRepository = self::getContainer()->get(ComplianceMappingRepository::class);

        // Wrap each test in a transaction so DB state is always rolled back.
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->rollback();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // (c) findTopLevelByFramework returns only parentless core/detailed reqs
    // -------------------------------------------------------------------------

    #[Test]
    public function findTopLevelByFrameworkExcludesSubRequirements(): void
    {
        $framework = $this->createFramework('TOPLEVEL');

        $core = $this->createRequirement($framework, 'CORE-1', 'core');
        $detailed = $this->createRequirement($framework, 'DET-1', 'detailed');
        // Two imported sub-requirements nested under the core requirement.
        $this->createRequirement($framework, 'SUB-1', 'sub_requirement', $core);
        $this->createRequirement($framework, 'SUB-2', 'sub_requirement', $core);
        $this->em->flush();

        $topLevel = $this->requirementRepository->findTopLevelByFramework($framework);
        $ids = array_map(static fn(ComplianceRequirement $r): ?int => $r->getId(), $topLevel);

        self::assertCount(2, $topLevel, 'Only core + detailed requirements are top-level');
        self::assertContains($core->getId(), $ids);
        self::assertContains($detailed->getId(), $ids);

        // countTopLevelByFramework agrees with the list method.
        self::assertSame(2, $this->requirementRepository->countTopLevelByFramework($framework));

        // findByFramework (detail/hierarchy view) still returns everything.
        self::assertCount(4, $this->requirementRepository->findByFramework($framework));
    }

    // -------------------------------------------------------------------------
    // (a) framework compliance-% is unaffected by adding sub_requirements
    // -------------------------------------------------------------------------

    #[Test]
    public function compliancePercentageDenominatorIgnoresSubRequirements(): void
    {
        $framework = $this->createFramework('PCT');

        $core1 = $this->createRequirement($framework, 'PCT-CORE-1', 'core');
        $this->createRequirement($framework, 'PCT-CORE-2', 'core');
        $this->em->flush();

        // Baseline: 2 top-level requirements only.
        $baseline = $framework->getCompliancePercentage();
        $baselineCount = $this->requirementRepository->countTopLevelByFramework($framework);
        self::assertSame(2, $baselineCount);

        // Now flood the framework with 50 imported sub_requirements.
        for ($i = 1; $i <= 50; $i++) {
            $this->createRequirement($framework, sprintf('PCT-SUB-%d', $i), 'sub_requirement', $core1);
        }
        $this->em->flush();
        // Refresh the in-memory requirements collection so getCompliancePercentage()
        // iterates the newly-added rows too.
        $this->em->refresh($framework);

        // Denominator must NOT have grown: still 2 top-level requirements.
        self::assertSame(
            $baselineCount,
            $this->requirementRepository->countTopLevelByFramework($framework),
            'Top-level count must be invariant to sub_requirement imports',
        );
        self::assertSame(
            $baseline,
            $framework->getCompliancePercentage(),
            'Compliance % must be invariant to sub_requirement imports (no dilution)',
        );

        // Sanity: the raw collection really did grow (proves the fix is the filter,
        // not an empty import).
        self::assertSame(52, $framework->getRequirements()->count());
    }

    // -------------------------------------------------------------------------
    // (b) coverage excludes draft mappings, includes published
    // -------------------------------------------------------------------------

    #[Test]
    public function crossFrameworkCoverageExcludesDraftMappingsIncludesPublished(): void
    {
        $source = $this->createFramework('COV-SRC');
        $target = $this->createFramework('COV-TGT');

        $src = $this->createRequirement($source, 'COV-S-1', 'core');
        $tgtPublished = $this->createRequirement($target, 'COV-T-1', 'core');
        $tgtDraft = $this->createRequirement($target, 'COV-T-2', 'core');
        $this->em->flush();

        // One published mapping (operational → counts) and one draft mapping
        // (unreviewed → must NOT count).
        $this->createMapping($src, $tgtPublished, 100, 'published');
        $this->createMapping($src, $tgtDraft, 100, 'draft');
        $this->em->flush();

        $mappings = $this->mappingRepository->findCrossFrameworkMappings($source, $target);
        self::assertCount(1, $mappings, 'Only the operational (published) mapping is returned');
        self::assertSame('published', $mappings[0]->getLifecycleState());
        self::assertSame($tgtPublished->getId(), $mappings[0]->getTargetRequirement()->getId());

        // findMappingsToRequirement (inheritance/coverage inbound walk) likewise
        // excludes the draft and includes the published.
        self::assertCount(1, $this->mappingRepository->findMappingsToRequirement($tgtPublished));
        self::assertCount(0, $this->mappingRepository->findMappingsToRequirement($tgtDraft));

        // Coverage % denominator = top-level target requirements (2 here); only
        // 1 is covered by an operational mapping → 50%, not diluted/inflated.
        $coverage = $this->mappingRepository->calculateFrameworkCoverage($source, $target);
        self::assertSame(2, $coverage['total_target_requirements']);
        self::assertSame(1, $coverage['covered_requirements']);
        self::assertEqualsWithDelta(50.0, $coverage['coverage_percentage'], 0.01);
    }

    #[Test]
    public function operationalStateFilterAlsoRejectsReviewAndDeprecated(): void
    {
        $source = $this->createFramework('LC-SRC');
        $target = $this->createFramework('LC-TGT');
        $src = $this->createRequirement($source, 'LC-S-1', 'core');
        $tgt = $this->createRequirement($target, 'LC-T-1', 'core');
        $this->em->flush();

        $this->createMapping($src, $tgt, 100, 'review');
        $this->createMapping($src, $tgt, 100, 'deprecated');
        $this->createMapping($src, $tgt, 100, 'approved'); // operational
        $this->em->flush();

        $mappings = $this->mappingRepository->findCrossFrameworkMappings($source, $target);
        self::assertCount(1, $mappings, 'Only approved is operational; review + deprecated excluded');
        self::assertSame('approved', $mappings[0]->getLifecycleState());
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    private function createFramework(string $codeSuffix): ComplianceFramework
    {
        $framework = new ComplianceFramework();
        $framework->setCode('TEST-' . $codeSuffix . '-' . uniqid())
            ->setName('Test Framework ' . $codeSuffix)
            ->setDescription('Integration-test framework')
            ->setVersion('1.0')
            ->setApplicableIndustry('all')
            ->setRegulatoryBody('Test')
            ->setMandatory(false)
            ->setActive(true);
        $this->em->persist($framework);

        return $framework;
    }

    private function createRequirement(
        ComplianceFramework $framework,
        string $requirementId,
        string $type,
        ?ComplianceRequirement $parent = null,
    ): ComplianceRequirement {
        $req = new ComplianceRequirement();
        $req->setFramework($framework)
            ->setRequirementId($requirementId)
            ->setTitle('Req ' . $requirementId)
            ->setDescription('Test requirement ' . $requirementId)
            ->setPriority('medium')
            ->setRequirementType($type);
        if ($parent instanceof ComplianceRequirement) {
            $req->setParentRequirement($parent);
            $parent->addDetailedRequirement($req);
        }
        $framework->addRequirement($req);
        $this->em->persist($req);

        return $req;
    }

    private function createMapping(
        ComplianceRequirement $source,
        ComplianceRequirement $target,
        int $percentage,
        string $lifecycleState,
    ): ComplianceMapping {
        $mapping = new ComplianceMapping();
        $mapping->setSourceRequirement($source)
            ->setTargetRequirement($target)
            ->setMappingPercentage($percentage)
            ->setMappingType('full')
            ->setConfidence('high')
            ->setLifecycleState($lifecycleState);
        // validFrom in the past so isValidAt(now) is true for inheritance paths.
        $mapping->setValidFrom(new DateTimeImmutable('-1 day'));
        $this->em->persist($mapping);

        return $mapping;
    }
}
