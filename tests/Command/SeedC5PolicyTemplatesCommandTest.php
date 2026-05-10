<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\SeedC5PolicyTemplatesCommand;
use App\Entity\PolicyTemplate;
use App\Repository\PolicyTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Policy-Wizard — SeedC5PolicyTemplatesCommand unit tests.
 *
 * Exercises the 12 BSI C5:2026 cloud-security policy templates seed
 * (`app:policy-wizard:seed-c5`).
 *
 * Three contracts are asserted:
 *   1. Idempotency — re-runs without `--force` are no-ops.
 *   2. Exhaustive coverage — all 12 templates land with the exact key set.
 *   3. Per-row correctness — bsi_tier, linked_bsi_bausteine and
 *      linked_annex_a_controls match expected values.
 */
#[AllowMockObjectsWithoutExpectations]
final class SeedC5PolicyTemplatesCommandTest extends TestCase
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

    private function makeCommand(): SeedC5PolicyTemplatesCommand
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

        return new SeedC5PolicyTemplatesCommand($em, $repo);
    }

    #[Test]
    public function testSeedingIdempotent(): void
    {
        $command = $this->makeCommand();
        $tester = new CommandTester($command);

        // First run: 12 created, 0 skipped.
        $exitCode = $tester->execute([]);
        self::assertSame(0, $exitCode);
        self::assertCount(12, $this->persisted, 'first invocation creates all 12 templates');
        $createdRows = $this->persisted;

        // Pre-populate the repository so the second run finds them.
        foreach ($createdRows as $template) {
            $key = $template->getKey();
            self::assertIsString($key);
            $this->existing[$key] = $template;
        }
        $this->persisted = [];

        // Second run without --force: all 12 skipped.
        $exitCode = $tester->execute([]);
        self::assertSame(0, $exitCode);
        self::assertCount(
            0,
            $this->persisted,
            'second invocation without --force is a no-op (idempotency)',
        );
        $output = $tester->getDisplay();
        self::assertStringContainsString('skipped=12', $output);

        // Third run with --force: all 12 updated in place.
        $tester->execute(['--force' => true]);
        self::assertCount(12, $this->persisted, '--force triggers update on every existing row');
    }

    #[Test]
    public function testAllTwelveC5TemplatesCreated(): void
    {
        $command = $this->makeCommand();
        $tester = new CommandTester($command);

        $tester->execute([]);
        self::assertCount(12, $this->persisted);

        $keys = array_map(
            static fn (PolicyTemplate $t): ?string => $t->getKey(),
            $this->persisted,
        );

        $expected = [
            'c5.organisation_information_security',
            'c5.security_policies_procedures',
            'c5.personnel_security',
            'c5.asset_management',
            'c5.physical_security',
            'c5.operations_security',
            'c5.identity_access_management',
            'c5.cryptography_key_management',
            'c5.communication_security',
            'c5.portability_interoperability',
            'c5.procurement_supplier_management',
            'c5.compliance_audit',
        ];

        sort($keys);
        sort($expected);
        self::assertSame($expected, $keys);

        // Every template carries standard='c5' and a non-null bsi_tier.
        foreach ($this->persisted as $template) {
            self::assertSame('c5', $template->getStandard(), $template->getKey() . ' standard=c5');
            self::assertNotNull(
                $template->getBsiTier(),
                $template->getKey() . ' must declare a bsi_tier (basis|standard|kern)',
            );
            self::assertContains(
                $template->getBsiTier(),
                PolicyTemplate::BSI_TIERS,
                $template->getKey() . ' bsi_tier in {basis,standard,kern}',
            );
            self::assertSame(1, $template->getVersion());
            self::assertTrue($template->isActive());
            self::assertFalse(
                $template->isClimateChangeWording(),
                'climate-change wording is an ISO 27001 Cl. 5.2 concern only',
            );
        }
    }

    #[Test]
    public function testTierAndLinkedAnchorsAndAnnexAControls(): void
    {
        $command = $this->makeCommand();
        $tester = new CommandTester($command);

        $tester->execute([]);

        $byKey = [];
        foreach ($this->persisted as $template) {
            $byKey[$template->getKey()] = $template;
        }

        // OIS — basis tier, top-level governance with Annex A.5.1-A.5.4
        $ois = $byKey['c5.organisation_information_security'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $ois);
        self::assertSame(PolicyTemplate::BSI_TIER_BASIS, $ois->getBsiTier());
        self::assertSame('C5 OIS-01', $ois->getNormRef());
        self::assertContains('C5:OIS-01', $ois->getLinkedBsiBausteine() ?? []);
        self::assertContains('C5:OIS-07', $ois->getLinkedBsiBausteine() ?? []);
        self::assertSame(['A.5.1', 'A.5.2', 'A.5.3', 'A.5.4'], $ois->getLinkedAnnexAControls());
        self::assertSame(24, $ois->getReviewIntervalMonths());
        // Roots: linkedBausteine collapses C5:OIS-01..07 to single C5:OIS root, plus OPS.2.2
        $roots = $ois->getLinkedBausteine() ?? [];
        self::assertContains('C5:OIS', $roots);
        self::assertContains('OPS.2.2', $roots);

        // KOS (Cryptography + Key Management) — standard tier (Zusatzkriterien
        // covering BYOK/HYOK/PQC), document_type=programme.
        $crypto = $byKey['c5.cryptography_key_management'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $crypto);
        self::assertSame(PolicyTemplate::BSI_TIER_STANDARD, $crypto->getBsiTier());
        self::assertSame('programme', $crypto->getDocumentType());
        self::assertSame(['A.8.24'], $crypto->getLinkedAnnexAControls());
        self::assertContains('C5:CRY-01', $crypto->getLinkedBsiBausteine() ?? []);

        // PI — standard tier (cloud lock-in avoidance + exit strategy is
        // Zusatzkriterium territory), 24-month review.
        $pi = $byKey['c5.portability_interoperability'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $pi);
        self::assertSame(PolicyTemplate::BSI_TIER_STANDARD, $pi->getBsiTier());
        self::assertSame(24, $pi->getReviewIntervalMonths());
        self::assertSame(['A.5.23', 'A.5.30'], $pi->getLinkedAnnexAControls());

        // BPM (Procurement + Supplier) — basis tier, includes DPO section
        // because Sub-Auftragsverarbeiter touches GDPR Art. 28.
        $bpm = $byKey['c5.procurement_supplier_management'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $bpm);
        self::assertSame(PolicyTemplate::BSI_TIER_BASIS, $bpm->getBsiTier());
        self::assertTrue($bpm->isDpoSectionRequired());
        self::assertContains('ROLE_PROCUREMENT', $bpm->getApprovalChain() ?? []);

        // COM (Compliance + Audit) — basis tier, DPO section required
        // (compliance domain covers GDPR), top management approval.
        $com = $byKey['c5.compliance_audit'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $com);
        self::assertSame(PolicyTemplate::BSI_TIER_BASIS, $com->getBsiTier());
        self::assertTrue($com->isDpoSectionRequired());
        self::assertContains('ROLE_TOP_MGMT', $com->getApprovalChain() ?? []);
        self::assertSame(24, $com->getReviewIntervalMonths());

        // PSS (Personnel) — DPO section required (employee PII per
        // ISO 27018), HR + CSO approval chain.
        $pss = $byKey['c5.personnel_security'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $pss);
        self::assertTrue($pss->isDpoSectionRequired());
        self::assertContains('ROLE_HR', $pss->getApprovalChain() ?? []);

        // AM — basis tier, 12-month review (asset register is high-cadence).
        $am = $byKey['c5.asset_management'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $am);
        self::assertSame(PolicyTemplate::BSI_TIER_BASIS, $am->getBsiTier());
        self::assertSame(12, $am->getReviewIntervalMonths());
        self::assertContains('A.5.9', $am->getLinkedAnnexAControls() ?? []);

        // Title and body translation keys follow the policy.c5.<topic>.v1
        // pattern (must match policy_c5.{de,en}.yaml home domain).
        foreach ($this->persisted as $template) {
            $titleKey = $template->getTitleTranslationKey() ?? '';
            $bodyKey = $template->getBodyTranslationKey() ?? '';
            self::assertStringStartsWith('policy.c5.', $titleKey);
            self::assertStringEndsWith('.v1.title', $titleKey);
            self::assertStringStartsWith('policy.c5.', $bodyKey);
            self::assertStringEndsWith('.v1.body', $bodyKey);
        }
    }
}
