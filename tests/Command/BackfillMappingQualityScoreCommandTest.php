<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use App\Repository\ComplianceMappingRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Integration tests for app:backfill-mqs + the "waiting" count semantics.
 *
 * Verifies (Bug 4):
 *  - MQS is computed for metadata-rich mappings that lack a qualityScore
 *  - the command is idempotent (a second run scores nothing more)
 *  - only eligible rows are scored (metadata-poor / already-scored are skipped)
 *  - getQualityStatistics() does NOT count MQS-scored rows as "waiting"
 *
 * Requires a real database (APP_ENV=test). Wrapped in a transaction that is
 * rolled back so no state leaks between tests.
 *
 * Run: php bin/phpunit --group integration tests/Command/BackfillMappingQualityScoreCommandTest.php
 */
#[Group('integration')]
class BackfillMappingQualityScoreCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ComplianceMappingRepository $repository;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->repository = self::getContainer()->get(ComplianceMappingRepository::class);

        $application = new Application($kernel);
        $command = $application->find('app:backfill-mqs');
        $this->commandTester = new CommandTester($command);

        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->rollback();
        parent::tearDown();
    }

    #[Test]
    public function backfillScoresOnlyMetadataRichUnscoredMappings(): void
    {
        $fwA = $this->createFramework('TEST-FW-A-' . uniqid());
        $fwB = $this->createFramework('TEST-FW-B-' . uniqid());

        // Eligible: rich metadata (provenanceUrl + confidence), no qualityScore.
        $eligible = $this->createMapping($fwA, $fwB, [
            'confidence' => 'high',
            'provenanceUrl' => 'https://example.org/crosswalk',
            'lifecycleState' => 'draft',
        ]);

        // NOT eligible — already has a qualityScore.
        $alreadyScored = $this->createMapping($fwA, $fwB, [
            'confidence' => 'high',
            'provenanceUrl' => 'https://example.org/crosswalk',
            'qualityScore' => 42,
        ]);

        // NOT eligible — metadata-poor (no provenanceUrl).
        $metadataPoor = $this->createMapping($fwA, $fwB, [
            'confidence' => 'high',
            'provenanceUrl' => null,
        ]);

        $this->em->flush();

        $eligibleId = $eligible->getId();
        $alreadyScoredId = $alreadyScored->getId();
        $metadataPoorId = $metadataPoor->getId();

        $this->commandTester->execute([]);
        $this->commandTester->assertCommandIsSuccessful();

        $this->em->clear();

        // Eligible row got an MQS + breakdown.
        $eligibleFresh = $this->repository->find($eligibleId);
        self::assertNotNull($eligibleFresh->getQualityScore(), 'Eligible mapping should be scored');
        self::assertGreaterThan(0, $eligibleFresh->getQualityScore());
        self::assertNotNull($eligibleFresh->getMqsBreakdown());
        self::assertArrayHasKey('total', $eligibleFresh->getMqsBreakdown());
        // Honest review-pending reflection: lifecycle stays draft.
        self::assertSame('draft', $eligibleFresh->getLifecycleState());

        // Already-scored row was NOT touched (still 42).
        self::assertSame(42, $this->repository->find($alreadyScoredId)->getQualityScore());

        // Metadata-poor row was NOT scored.
        self::assertNull($this->repository->find($metadataPoorId)->getQualityScore());
    }

    #[Test]
    public function backfillIsIdempotent(): void
    {
        $fwA = $this->createFramework('TEST-FW-A-' . uniqid());
        $fwB = $this->createFramework('TEST-FW-B-' . uniqid());

        $this->createMapping($fwA, $fwB, [
            'confidence' => 'medium',
            'provenanceUrl' => 'https://example.org/crosswalk',
        ]);
        $this->em->flush();

        // First run scores it.
        $this->commandTester->execute([]);
        $this->commandTester->assertCommandIsSuccessful();
        $this->em->clear();

        $afterFirst = $this->repository->countMqsBackfillCandidates();
        self::assertSame(0, $afterFirst, 'No candidates should remain after first run');

        // Second run is a no-op.
        $this->commandTester->execute([]);
        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('No mappings require an MQS backfill', $output);
    }

    #[Test]
    public function dryRunDoesNotPersist(): void
    {
        $fwA = $this->createFramework('TEST-FW-A-' . uniqid());
        $fwB = $this->createFramework('TEST-FW-B-' . uniqid());

        $m = $this->createMapping($fwA, $fwB, [
            'confidence' => 'high',
            'provenanceUrl' => 'https://example.org/crosswalk',
        ]);
        $this->em->flush();
        $id = $m->getId();

        $this->commandTester->execute(['--dry-run' => true]);
        $this->commandTester->assertCommandIsSuccessful();
        $this->em->clear();

        self::assertNull($this->repository->find($id)->getQualityScore(), 'Dry-run must not persist a score');
    }

    #[Test]
    public function waitingCountExcludesMqsScoredMappings(): void
    {
        $fwA = $this->createFramework('TEST-FW-A-' . uniqid());
        $fwB = $this->createFramework('TEST-FW-B-' . uniqid());

        $statsBefore = $this->repository->getQualityStatistics();
        $baseWaiting = $statsBefore['unanalyzed_mappings'];

        // One MQS-scored (must NOT count as waiting), one genuinely poor (must).
        $this->createMapping($fwA, $fwB, [
            'confidence' => 'high',
            'provenanceUrl' => 'https://example.org/crosswalk',
            'qualityScore' => 80,
            'calculatedPercentage' => null,
        ]);
        $this->createMapping($fwA, $fwB, [
            'confidence' => 'low',
            'provenanceUrl' => null,
            'qualityScore' => null,
            'calculatedPercentage' => null,
        ]);
        $this->em->flush();
        $this->em->clear();

        $statsAfter = $this->repository->getQualityStatistics();

        // Only the metadata-poor row should have increased the waiting count.
        self::assertSame($baseWaiting + 1, $statsAfter['unanalyzed_mappings']);
        // scored_mappings is exposed and counts the MQS-scored row.
        self::assertArrayHasKey('scored_mappings', $statsAfter);
        self::assertGreaterThanOrEqual(1, $statsAfter['scored_mappings']);
    }

    // -------------------------------------------------------------------------

    private function createFramework(string $code): ComplianceFramework
    {
        $fw = new ComplianceFramework();
        $fw->setCode($code)
            ->setName('Test ' . $code)
            ->setVersion('1.0')
            ->setApplicableIndustry('all')
            ->setRegulatoryBody('Test')
            ->setMandatory(false)
            ->setActive(true);
        $this->em->persist($fw);

        return $fw;
    }

    private function createRequirement(ComplianceFramework $fw, string $reqId): ComplianceRequirement
    {
        $req = new ComplianceRequirement();
        $req->setFramework($fw)
            ->setRequirementId($reqId)
            ->setTitle('Req ' . $reqId)
            ->setDescription('desc')
            ->setPriority('medium');
        $this->em->persist($req);

        return $req;
    }

    /**
     * @param array<string, mixed> $opts
     */
    private function createMapping(ComplianceFramework $fwA, ComplianceFramework $fwB, array $opts): ComplianceMapping
    {
        $uid = uniqid();
        $src = $this->createRequirement($fwA, 'S-' . $uid);
        $tgt = $this->createRequirement($fwB, 'T-' . $uid);

        $m = new ComplianceMapping();
        $m->setSourceRequirement($src)
            ->setTargetRequirement($tgt)
            ->setMappingPercentage(75)
            ->setRelationship('subset')
            ->setConfidence($opts['confidence'] ?? 'medium')
            ->setSource('test_fixture')
            ->setLifecycleState($opts['lifecycleState'] ?? 'draft')
            ->setProvenanceSource('Test crosswalk');

        if (array_key_exists('provenanceUrl', $opts)) {
            $m->setProvenanceUrl($opts['provenanceUrl']);
        }
        if (array_key_exists('qualityScore', $opts) && $opts['qualityScore'] !== null) {
            $m->setQualityScore($opts['qualityScore']);
        }
        if (array_key_exists('calculatedPercentage', $opts) && $opts['calculatedPercentage'] !== null) {
            $m->setCalculatedPercentage($opts['calculatedPercentage']);
        }

        $this->em->persist($m);

        return $m;
    }
}
