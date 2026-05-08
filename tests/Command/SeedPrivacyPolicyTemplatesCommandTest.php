<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\SeedPrivacyPolicyTemplatesCommand;
use App\Entity\PolicyTemplate;
use App\Repository\PolicyTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Policy-Wizard W6-B — SeedPrivacyPolicyTemplatesCommand unit tests.
 *
 * Exercises the 5 standalone Privacy/DPO templates + 1 thin A.5.34
 * cross-reference host (`app:policy-wizard:seed-privacy`) defined in
 * `docs/plans/policy-wizard/06-dpo-input.md` Decision Matrix v2 (§0)
 * and ISO 27701 §3.1 clause mapping.
 *
 * Three contracts asserted:
 *   1. Idempotency — re-runs without `--force` are no-ops.
 *   2. All 6 templates land with the exact key set + standard='gdpr'.
 *   3. ISO 27701 mapping correct per §3.1 (incl. 2019 → 2025 6.13 delta).
 */
#[AllowMockObjectsWithoutExpectations]
final class SeedPrivacyPolicyTemplatesCommandTest extends TestCase
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

    private function makeCommand(): SeedPrivacyPolicyTemplatesCommand
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

        return new SeedPrivacyPolicyTemplatesCommand($em, $repo);
    }

    #[Test]
    public function testSeedingIdempotent(): void
    {
        $command = $this->makeCommand();
        $tester = new CommandTester($command);

        // First run: 6 created, 0 skipped.
        $exitCode = $tester->execute([]);
        self::assertSame(0, $exitCode);
        self::assertCount(6, $this->persisted, 'first invocation creates all 6 templates');
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

        // Third run with --force: all 6 updated in place.
        $tester->execute(['--force' => true]);
        self::assertCount(6, $this->persisted, '--force triggers update on every existing row');
    }

    #[Test]
    public function testAllSixPrivacyTemplatesCreated(): void
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
            // 5 standalone privacy documents
            'gdpr.privacy_policy',
            'gdpr.ropa_methodology',
            'gdpr.dpia_methodology',
            'gdpr.dsr_procedure',
            'gdpr.data_breach_notification_procedure',
            // 1 thin A.5.34 cross-reference host
            'gdpr.iso_a534_thin_host',
        ];

        sort($keys);
        sort($expected);
        self::assertSame($expected, $keys);

        // Every privacy template carries standard='gdpr', links A.5.34,
        // surfaces in the DPO inbox via affected_functions=['dpo'], and
        // ships v1 active.
        foreach ($this->persisted as $template) {
            self::assertSame('gdpr', $template->getStandard(), $template->getKey() . ' standard=gdpr');
            self::assertSame(['A.5.34'], $template->getLinkedAnnexAControls(), $template->getKey() . ' must link A.5.34');
            self::assertSame(['dpo'], $template->getAffectedFunctions(), $template->getKey() . ' must surface to DPO inbox');
            self::assertSame(1, $template->getVersion());
            self::assertTrue($template->isActive());
            self::assertFalse(
                $template->isClimateChangeWording(),
                'climate-change wording is an ISO 27001 Cl. 5.2 concern — not relevant for privacy docs',
            );
        }

        $byKey = [];
        foreach ($this->persisted as $template) {
            $byKey[$template->getKey()] = $template;
        }

        // dpo_section_required: true for the 5 standalone, false for the
        // thin A.5.34 host (per §0.A — host has no own gated section).
        self::assertTrue($byKey['gdpr.privacy_policy']->isDpoSectionRequired());
        self::assertTrue($byKey['gdpr.ropa_methodology']->isDpoSectionRequired());
        self::assertTrue($byKey['gdpr.dpia_methodology']->isDpoSectionRequired());
        self::assertTrue($byKey['gdpr.dsr_procedure']->isDpoSectionRequired());
        self::assertTrue($byKey['gdpr.data_breach_notification_procedure']->isDpoSectionRequired());
        self::assertFalse(
            $byKey['gdpr.iso_a534_thin_host']->isDpoSectionRequired(),
            'thin A.5.34 host has no own gated section — gated sections live in the 5 standalones',
        );
    }

    #[Test]
    public function testIso27701ClauseMappingCorrectPerSpec(): void
    {
        $command = $this->makeCommand();
        $tester = new CommandTester($command);

        $tester->execute([]);

        $byKey = [];
        foreach ($this->persisted as $template) {
            $byKey[$template->getKey()] = $template;
        }

        // §3.1 — Privacy Policy: Cl. 5.1 + 5.2 (unchanged 2019 → 2025).
        $top = $byKey['gdpr.privacy_policy'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $top);
        self::assertSame(['5.1', '5.2'], $top->getIso27701Clauses2025());
        self::assertSame(['5.1', '5.2'], $top->getIso27701Clauses2019());

        // §3.1 — RoPA Methodology: Cl. 7.2.8 (unchanged).
        $ropa = $byKey['gdpr.ropa_methodology'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $ropa);
        self::assertSame(['7.2.8'], $ropa->getIso27701Clauses2025());
        self::assertSame(['7.2.8'], $ropa->getIso27701Clauses2019());

        // §3.1 — DPIA Methodology: Cl. 6.2 + 7.2.5 (privacy risk treatment + DPIA).
        $dpia = $byKey['gdpr.dpia_methodology'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $dpia);
        self::assertSame(['6.2', '7.2.5'], $dpia->getIso27701Clauses2025());
        self::assertSame(['6.2', '7.2.5'], $dpia->getIso27701Clauses2019());

        // §3.1 — DSR Procedure: full Cl. 7.3.1-7.3.10 range.
        $dsr = $byKey['gdpr.dsr_procedure'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $dsr);
        $dsrClauses2025 = $dsr->getIso27701Clauses2025();
        self::assertNotNull($dsrClauses2025);
        self::assertCount(10, $dsrClauses2025, 'DSR covers Cl. 7.3.1 through 7.3.10');
        self::assertContains('7.3.1', $dsrClauses2025);
        self::assertContains('7.3.10', $dsrClauses2025);

        // §3.1 — Data Breach Notification: 6.13 (2025) was 6.13.1.5 (2019).
        // This is the only template where the 2019 vs 2025 mapping diverges.
        $breach = $byKey['gdpr.data_breach_notification_procedure'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $breach);
        self::assertSame(['6.13'], $breach->getIso27701Clauses2025(), '2025 promoted breach response to top-level Cl. 6.13');
        self::assertSame(['6.13.1.5'], $breach->getIso27701Clauses2019(), '2019 had it as a sub-clause');

        // Thin A.5.34 host carries no PIMS clause mapping (it's a stub).
        $host = $byKey['gdpr.iso_a534_thin_host'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $host);
        self::assertNull(
            $host->getIso27701Clauses2025(),
            'thin A.5.34 host carries no own PIMS clause — empty stored as null',
        );
        self::assertNull($host->getIso27701Clauses2019());

        // Document types per §2.x of the DPO spec.
        self::assertSame('policy', $byKey['gdpr.privacy_policy']->getDocumentType());
        self::assertSame('methodology', $byKey['gdpr.ropa_methodology']->getDocumentType());
        self::assertSame('methodology', $byKey['gdpr.dpia_methodology']->getDocumentType());
        self::assertSame('procedure', $byKey['gdpr.dsr_procedure']->getDocumentType());
        self::assertSame('procedure', $byKey['gdpr.data_breach_notification_procedure']->getDocumentType());
        self::assertSame('policy', $byKey['gdpr.iso_a534_thin_host']->getDocumentType());

        // Top-Mgmt approval required for the high-stakes docs.
        self::assertContains('ROLE_TOP_MGMT', $byKey['gdpr.privacy_policy']->getApprovalChain() ?? []);
        self::assertContains('ROLE_TOP_MGMT', $byKey['gdpr.data_breach_notification_procedure']->getApprovalChain() ?? []);
        // RoPA / DPIA / DSR need DPO + CISO only, not Top-Mgmt.
        self::assertNotContains('ROLE_TOP_MGMT', $byKey['gdpr.ropa_methodology']->getApprovalChain() ?? []);
    }
}
