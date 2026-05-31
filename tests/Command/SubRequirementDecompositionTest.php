<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use App\Service\Compliance\SubRequirementResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * End-to-end coverage for the sub-requirement decomposition pipeline:
 *   app:seed-sub-requirements  → app:import-sub-mappings
 *
 * The commands glob the real fixtures (fixtures/library/decompositions/*.json).
 * We seed only the frameworks the GDPR↔ISO27701 + BDSG↔GDPR fixtures touch so the
 * assertions stay deterministic regardless of which other catalogues happen to be
 * loaded in the test DB. Everything runs inside a transaction that tearDown rolls
 * back, so the test is fully isolated and idempotent across CI runs.
 */
final class SubRequirementDecompositionTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->getConnection()->beginTransaction();

        // Ensure the frameworks the GDPR/BDSG fixtures reference exist. We only
        // need these three for the assertions below.
        $this->ensureFramework('GDPR');
        $this->ensureFramework('ISO27701');
        $this->ensureFramework('BDSG');
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        if ($conn->isTransactionActive()) {
            $conn->rollBack();
        }
        parent::tearDown();
    }

    private function ensureFramework(string $code): ComplianceFramework
    {
        $repo = $this->em->getRepository(ComplianceFramework::class);
        $fw = $repo->findOneBy(['code' => $code]);
        if ($fw instanceof ComplianceFramework) {
            return $fw;
        }

        $fw = new ComplianceFramework();
        $fw->setCode($code)
            ->setName($code . ' (test)')
            ->setVersion('test')
            ->setApplicableIndustry('all_sectors')
            ->setRegulatoryBody('test')
            ->setMandatory(false)
            ->setActive(true);
        $this->em->persist($fw);

        return $fw;
    }

    private function runSeeder(array $opts = []): CommandTester
    {
        $app = new Application(self::$kernel);
        $tester = new CommandTester($app->find('app:seed-sub-requirements'));
        $tester->execute($opts);

        return $tester;
    }

    private function runImporter(array $opts = []): CommandTester
    {
        $app = new Application(self::$kernel);
        $tester = new CommandTester($app->find('app:import-sub-mappings'));
        $tester->execute($opts);

        return $tester;
    }

    private function gdpr(): ComplianceFramework
    {
        return $this->em->getRepository(ComplianceFramework::class)->findOneBy(['code' => 'GDPR']);
    }

    private function bdsg(): ComplianceFramework
    {
        return $this->em->getRepository(ComplianceFramework::class)->findOneBy(['code' => 'BDSG']);
    }

    /**
     * @return ComplianceRequirement[]
     */
    private function subReqs(ComplianceFramework $fw): array
    {
        return $this->em->getRepository(ComplianceRequirement::class)
            ->findBy(['framework' => $fw, 'requirementType' => 'sub_requirement']);
    }

    // ---------------------------------------------------------------- seeder

    #[Test]
    public function seederCreatesSubRequirementsWithParentLink(): void
    {
        $this->runSeeder();

        $subs = $this->subReqs($this->gdpr());
        self::assertNotEmpty($subs, 'GDPR sub-requirements should have been seeded');

        foreach ($subs as $sub) {
            self::assertSame('sub_requirement', $sub->getRequirementType());
            self::assertInstanceOf(
                ComplianceRequirement::class,
                $sub->getParentRequirement(),
                sprintf('sub-req %s must always have a parent (hierarchy integrity)', $sub->getRequirementId()),
            );
            self::assertLessThanOrEqual(
                50,
                mb_strlen((string) $sub->getRequirementId()),
                'requirementId must fit the length-50 column',
            );
        }
    }

    #[Test]
    public function seederIsIdempotent(): void
    {
        $this->runSeeder();
        $countAfterFirst = count($this->subReqs($this->gdpr()));
        self::assertGreaterThan(0, $countAfterFirst);

        // Detach so the second run resolves rows from the DB, not the identity map.
        $this->em->clear();
        $this->runSeeder();

        self::assertCount(
            $countAfterFirst,
            $this->subReqs($this->gdpr()),
            'Re-running the seeder must not create duplicates',
        );
    }

    #[Test]
    public function seederSeedsBdsgLedSourceButReportsLedCount(): void
    {
        $tester = $this->runSeeder();
        $display = $tester->getDisplay();

        // BDSG fixture carries 40 LED rows (BDSG Teil 3 / Directive 2016/680).
        self::assertStringContainsString('LED rows', $display);

        // The BDSG source sub-req IS seeded (e.g. §45 / "BDSG-45").
        $bdsgSubs = $this->subReqs($this->bdsg());
        $ids = array_map(static fn (ComplianceRequirement $r) => $r->getRequirementId(), $bdsgSubs);
        self::assertContains('BDSG-45', $ids, 'BDSG LED source sub-requirement should still be seeded');
    }

    #[Test]
    public function seederNeverMaterialisesNaPlaceholderTarget(): void
    {
        $this->runSeeder();

        // LED rows have target="n/a" — that must never become a GDPR requirement.
        $bogus = $this->em->getRepository(ComplianceRequirement::class)
            ->findOneBy(['framework' => $this->gdpr(), 'requirementId' => 'n/a']);
        self::assertNull($bogus, '"n/a" placeholder must not be seeded as a requirement');
    }

    #[Test]
    public function seederDryRunWritesNothing(): void
    {
        $this->runSeeder(['--dry-run' => true]);

        self::assertSame([], $this->subReqs($this->gdpr()), 'dry-run must not persist sub-requirements');
    }

    // -------------------------------------------------------------- importer

    #[Test]
    public function importerCreatesDraftMappingsWithCorrectPercentages(): void
    {
        $this->runSeeder();
        $this->runImporter();

        $mappings = $this->em->getRepository(ComplianceMapping::class)
            ->findBy(['source' => 'subreq_decomposition_2026_05']);
        self::assertNotEmpty($mappings, 'Importer should have created draft mappings');

        $percentByRelationship = [
            'equivalent' => 100,
            'superset' => 90,
            'subset' => 75,
            'partial_overlap' => 60,
            'related' => 40,
        ];

        foreach ($mappings as $m) {
            self::assertSame('draft', $m->getLifecycleState(), 'mappings must be draft (review gate)');
            self::assertSame('high', $m->getConfidence());
            $rel = $m->getRelationship();
            self::assertArrayHasKey($rel, $percentByRelationship, 'unexpected relationship ' . (string) $rel);
            self::assertSame(
                $percentByRelationship[$rel],
                $m->getMappingPercentage(),
                sprintf('relationship %s must map to %d%%', (string) $rel, $percentByRelationship[$rel]),
            );
        }
    }

    #[Test]
    public function importerIsIdempotent(): void
    {
        $this->runSeeder();
        $this->runImporter();
        $first = count($this->em->getRepository(ComplianceMapping::class)
            ->findBy(['source' => 'subreq_decomposition_2026_05']));
        self::assertGreaterThan(0, $first);

        $this->em->clear();
        $this->runImporter();

        $second = count($this->em->getRepository(ComplianceMapping::class)
            ->findBy(['source' => 'subreq_decomposition_2026_05']));
        self::assertSame($first, $second, 'Re-running the importer must not duplicate mappings');
    }

    #[Test]
    public function importerSkipsLedRowsEntirely(): void
    {
        $this->runSeeder();
        $tester = $this->runImporter();

        // No mapping may originate from a BDSG LED source (§45..§84, all target "n/a").
        $bdsgLedSource = $this->em->getRepository(ComplianceRequirement::class)
            ->findOneBy(['framework' => $this->bdsg(), 'requirementId' => 'BDSG-45']);
        self::assertInstanceOf(ComplianceRequirement::class, $bdsgLedSource);

        $mappingsFromLed = $this->em->getRepository(ComplianceMapping::class)
            ->findBy(['sourceRequirement' => $bdsgLedSource]);
        self::assertSame([], $mappingsFromLed, 'LED source rows must not produce a cross-mapping');

        self::assertStringContainsString('LED', $tester->getDisplay());
    }

    #[Test]
    public function importerDryRunWritesNothing(): void
    {
        $this->runSeeder();
        $this->runImporter(['--dry-run' => true]);

        self::assertSame(
            [],
            $this->em->getRepository(ComplianceMapping::class)->findBy(['source' => 'subreq_decomposition_2026_05']),
            'dry-run must not persist mappings',
        );
    }

    // -------------------------------------------------------------- resolver

    #[Test]
    public function resolverDerivesCoarseParents(): void
    {
        /** @var SubRequirementResolver $resolver */
        $resolver = self::getContainer()->get(SubRequirementResolver::class);

        self::assertSame('GDPR-5.1', $resolver->deriveParentId('GDPR-5.1.b'));
        self::assertSame('A.1.2', $resolver->deriveParentId('A.1.2.3'));
        self::assertSame('1.1', $resolver->deriveParentId('1.1.1'));
        self::assertSame('Art.10(2)', $resolver->deriveParentId('Art.10(2)(a)'));
        self::assertSame('Art.20(1)', $resolver->deriveParentId('Art.20(1)-governance-approval'));
        // Coarsest level: nothing to strip.
        self::assertNull($resolver->deriveParentId('5'));
    }

    #[Test]
    public function resolverCompactsLongIdsWithinColumnLimit(): void
    {
        /** @var SubRequirementResolver $resolver */
        $resolver = self::getContainer()->get(SubRequirementResolver::class);

        $long = 'Art.9(1) — establish, implement, document, maintain risk management system';
        $compact = $resolver->compactId($long);
        self::assertLessThanOrEqual(50, mb_strlen($compact));
        // Deterministic across calls.
        self::assertSame($compact, $resolver->compactId($long));
        // Short, already-clean ids pass through untouched.
        self::assertSame('GDPR-5.1.b', $resolver->compactId('GDPR-5.1.b'));
    }
}
