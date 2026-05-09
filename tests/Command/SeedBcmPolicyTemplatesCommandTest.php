<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\SeedBcmPolicyTemplatesCommand;
use App\Entity\PolicyTemplate;
use App\Repository\PolicyTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Policy-Wizard W5-B — SeedBcmPolicyTemplatesCommand unit tests.
 *
 * Exercises the 14 BCM PolicyTemplate seed (`app:policy-wizard:seed-bcm`):
 * idempotency, exhaustive row coverage, and per-row field correctness
 * against `docs/plans/policy-wizard/04-bcm-input.md` §1 + §2.1-§2.13.
 */
#[AllowMockObjectsWithoutExpectations]
final class SeedBcmPolicyTemplatesCommandTest extends TestCase
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

    private function makeCommand(): SeedBcmPolicyTemplatesCommand
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

        return new SeedBcmPolicyTemplatesCommand($em, $repo);
    }

    #[Test]
    public function testSeedingIdempotent(): void
    {
        $command = $this->makeCommand();
        $tester = new CommandTester($command);

        // First run: 14 created, 0 skipped.
        $exitCode = $tester->execute([]);
        self::assertSame(0, $exitCode);
        self::assertCount(14, $this->persisted, 'first invocation creates all 14 templates');
        $createdRows = $this->persisted;

        // Pre-populate the repository so the second run finds them.
        foreach ($createdRows as $template) {
            $key = $template->getKey();
            self::assertIsString($key);
            $this->existing[$key] = $template;
        }
        $this->persisted = [];

        // Second run without --force: all 14 skipped.
        $exitCode = $tester->execute([]);
        self::assertSame(0, $exitCode);
        self::assertCount(
            0,
            $this->persisted,
            'second invocation without --force is a no-op (idempotency)',
        );
        $output = $tester->getDisplay();
        self::assertStringContainsString('skipped=14', $output);
    }

    #[Test]
    public function testAllFourteenBcmTemplatesCreated(): void
    {
        $command = $this->makeCommand();
        $tester = new CommandTester($command);

        $tester->execute([]);
        self::assertCount(14, $this->persisted);

        $keys = array_map(
            static fn (PolicyTemplate $t): ?string => $t->getKey(),
            $this->persisted,
        );

        $expected = [
            'bcm.bcms_top_level',
            'bcm.bcms_scope_statement',
            'bcm.bia_methodology',
            'bcm.risk_assessment_methodology_bcm',
            'bcm.bc_strategy',
            'bcm.bc_plans',
            'bcm.incident_response_communication',
            'bcm.crisis_management_plan',
            'bcm.recovery_plans',
            'bcm.exercise_testing_programme',
            'bcm.internal_audit_bcm',
            'bcm.management_review_bcm',
            'bcm.nonconformity_corrective_action_bcm',
            'bcm.notfallhandbuch_bsi_2004',
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

        // Spot-check the apex template: BCMS top-level Notfallleitlinie.
        $top = $byKey['bcm.bcms_top_level'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $top);
        self::assertSame('bcm', $top->getStandard());
        self::assertSame('bcms_top_level', $top->getTopic());
        self::assertSame('policy', $top->getDocumentType());
        self::assertSame('ISO 22301 Cl. 5.2', $top->getNormRef());
        self::assertSame(
            'policy.bcm.bcms_top_level.v1.title',
            $top->getTitleTranslationKey(),
        );
        self::assertSame(
            'policy.bcm.bcms_top_level.v1.body',
            $top->getBodyTranslationKey(),
        );
        self::assertSame(['A.5.29', 'A.5.30'], $top->getLinkedAnnexAControls());
        self::assertNull($top->getLinkedBausteine());
        self::assertSame(12, $top->getReviewIntervalMonths());
        self::assertFalse($top->isDpoSectionRequired());
        self::assertFalse($top->isClimateChangeWording());
        self::assertTrue($top->isActive());
        self::assertSame(1, $top->getVersion());
        self::assertContains('TOP_MGMT', $top->getAffectedFunctions() ?? []);

        // BCMS Scope + BIA Methodology — review_interval_months = 24.
        $scope = $byKey['bcm.bcms_scope_statement'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $scope);
        self::assertSame(24, $scope->getReviewIntervalMonths());

        $bia = $byKey['bcm.bia_methodology'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $bia);
        self::assertSame(24, $bia->getReviewIntervalMonths());
        self::assertSame('methodology', $bia->getDocumentType());

        // Notfallhandbuch — only BSI-anchored template; carries DER.4.
        $nfh = $byKey['bcm.notfallhandbuch_bsi_2004'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $nfh);
        self::assertSame('BSI 200-4 Kap. 7', $nfh->getNormRef());
        self::assertSame(['DER.4'], $nfh->getLinkedBausteine());
        self::assertSame(['A.5.29', 'A.5.30'], $nfh->getLinkedAnnexAControls());

        // Exercise programme is the trigger for the BCExercise auto-seed.
        $exProgramme = $byKey['bcm.exercise_testing_programme'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $exProgramme);
        self::assertSame('programme', $exProgramme->getDocumentType());
        self::assertSame('ISO 22301 Cl. 8.6', $exProgramme->getNormRef());

        // Crisis management plan — `plan` document type.
        $crisis = $byKey['bcm.crisis_management_plan'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $crisis);
        self::assertSame('plan', $crisis->getDocumentType());

        // No BCM template is DPO-cross-check gated (privacy is out of
        // BCM governance scope).
        foreach ($byKey as $template) {
            self::assertFalse(
                $template->isDpoSectionRequired(),
                sprintf('BCM template %s must not require DPO cross-check', $template->getKey() ?? '?'),
            );
            self::assertNull(
                $template->getLinkedDoraArticles(),
                sprintf('BCM template %s must not anchor to DORA', $template->getKey() ?? '?'),
            );
        }
    }
}
