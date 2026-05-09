<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\SeedDoraPolicyTemplatesCommand;
use App\Entity\PolicyTemplate;
use App\Repository\PolicyTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Policy-Wizard W4-A — SeedDoraPolicyTemplatesCommand unit tests.
 *
 * Exercises the 6 NEW DORA PolicyTemplate seed (`app:policy-wizard:seed-dora`):
 * idempotency, exhaustive row coverage, and per-row field correctness
 * against `docs/plans/policy-wizard/03-dora-input.md` §2 + §10.
 */
#[AllowMockObjectsWithoutExpectations]
final class SeedDoraPolicyTemplatesCommandTest extends TestCase
{
    /** @var list<PolicyTemplate> */
    private array $persisted = [];

    /** @var array<string, PolicyTemplate> */
    private array $existing = [];

    protected function setUp(): void
    {
        $this->persisted = [];
        $this->existing = [];
    }

    private function makeCommand(): SeedDoraPolicyTemplatesCommand
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof PolicyTemplate) {
                $this->persisted[] = $entity;
            }
        });
        $em->method('flush');

        $repo = $this->createMock(PolicyTemplateRepository::class);
        $repo->method('findOneByKey')->willReturnCallback(
            fn (string $key): ?PolicyTemplate => $this->existing[$key] ?? null,
        );

        return new SeedDoraPolicyTemplatesCommand($em, $repo);
    }

    #[Test]
    public function testSeedingIdempotent(): void
    {
        $command = $this->makeCommand();
        $tester = new CommandTester($command);

        // First run: 6 created, 0 skipped.
        $exitCode = $tester->execute([]);
        self::assertSame(0, $exitCode);
        self::assertCount(6, $this->persisted, 'first invocation creates all six templates');
        $createdRows = $this->persisted;

        // Pre-populate the repository so the second run finds them.
        foreach ($createdRows as $template) {
            $key = $template->getKey();
            self::assertIsString($key);
            $this->existing[$key] = $template;
        }
        $this->persisted = [];

        // Second run without --force: all 6 skipped.
        $exitCode = $tester->execute([]);
        self::assertSame(0, $exitCode);
        self::assertCount(
            0,
            $this->persisted,
            'second invocation without --force is a no-op (idempotency)',
        );
        $output = $tester->getDisplay();
        self::assertStringContainsString('skipped=6', $output);
    }

    #[Test]
    public function testAllSixDoraTemplatesCreated(): void
    {
        $command = $this->makeCommand();
        $tester = new CommandTester($command);

        $tester->execute([]);
        self::assertCount(6, $this->persisted);

        $keys = array_map(
            static fn (PolicyTemplate $t): ?string => $t->getKey(),
            $this->persisted,
        );

        $expected = [
            'dora.ict_risk_management_framework',
            'dora.ict_risk_tolerance',
            'dora.detection_anomalous_activities',
            'dora.response_recovery',
            'dora.learning_evolving',
            'dora.communication_ict_incidents',
        ];

        sort($keys);
        sort($expected);
        self::assertSame($expected, $keys);
    }

    #[Test]
    public function testCorrectFieldsPopulated(): void
    {
        $command = $this->makeCommand();
        $tester = new CommandTester($command);

        $tester->execute([]);

        $byKey = [];
        foreach ($this->persisted as $template) {
            $byKey[$template->getKey()] = $template;
        }

        // Spot-check the canonical row: Art. 6 ICT Risk Management Framework.
        $rmf = $byKey['dora.ict_risk_management_framework'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $rmf);
        self::assertSame('dora', $rmf->getStandard());
        self::assertSame('ict_risk_management_framework', $rmf->getTopic());
        self::assertSame('policy', $rmf->getDocumentType());
        self::assertSame('Art. 6', $rmf->getNormRef());
        self::assertSame(
            'policy.dora.ict_risk_management_framework.v1.title',
            $rmf->getTitleTranslationKey(),
        );
        self::assertSame(
            'policy.dora.ict_risk_management_framework.v1.body',
            $rmf->getBodyTranslationKey(),
        );
        self::assertSame(['Art. 6', 'Art. 6.8'], $rmf->getLinkedDoraArticles());
        self::assertContains('IT_OPERATIONS', $rmf->getAffectedFunctions() ?? []);
        self::assertSame(12, $rmf->getReviewIntervalMonths());
        self::assertFalse(
            $rmf->isDpoSectionRequired(),
            'DORA standalone templates do not require DPO cross-check by default',
        );
        self::assertFalse(
            $rmf->isClimateChangeWording(),
            'climate-change wording is an ISO 27001 Cl. 5.2 concern only',
        );
        self::assertTrue($rmf->isActive());
        self::assertSame(1, $rmf->getVersion());
        self::assertNull(
            $rmf->getLinkedAnnexAControls(),
            'NEW DORA templates do not anchor to Annex A; extension catalogue handles overlap',
        );

        // Spot-check Art. 14 Communication template.
        $comm = $byKey['dora.communication_ict_incidents'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $comm);
        self::assertSame('Art. 14', $comm->getNormRef());
        self::assertSame(['Art. 14'], $comm->getLinkedDoraArticles());
    }
}
