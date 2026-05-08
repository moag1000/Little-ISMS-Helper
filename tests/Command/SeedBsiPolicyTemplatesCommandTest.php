<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\SeedBsiPolicyTemplatesCommand;
use App\Entity\PolicyTemplate;
use App\Repository\PolicyTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Policy-Wizard W5-A — SeedBsiPolicyTemplatesCommand unit tests.
 *
 * Exercises the 28 BSI Pflicht-Richtlinien + 1 Schutzbedarfsfeststellungs-
 * Methodik seed (`app:policy-wizard:seed-bsi`) defined in
 * `docs/plans/policy-wizard/02-bsi-input.md` Anhang A.
 *
 * Three contracts are asserted:
 *   1. Idempotency — re-runs without `--force` are no-ops.
 *   2. Exhaustive coverage — all 29 templates land with the exact key set.
 *   3. Per-row correctness — bsi_tier, linked_bsi_bausteine and
 *      linked_annex_a_controls match Anhang A row-by-row spot-checks.
 */
#[AllowMockObjectsWithoutExpectations]
final class SeedBsiPolicyTemplatesCommandTest extends TestCase
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

    private function makeCommand(): SeedBsiPolicyTemplatesCommand
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

        return new SeedBsiPolicyTemplatesCommand($em, $repo);
    }

    #[Test]
    public function testSeedingIdempotent(): void
    {
        $command = $this->makeCommand();
        $tester = new CommandTester($command);

        // First run: 29 created, 0 skipped.
        $exitCode = $tester->execute([]);
        self::assertSame(0, $exitCode);
        self::assertCount(29, $this->persisted, 'first invocation creates all 29 templates');
        $createdRows = $this->persisted;

        // Pre-populate the repository so the second run finds them.
        foreach ($createdRows as $template) {
            $key = $template->getKey();
            self::assertIsString($key);
            $this->existing[$key] = $template;
        }
        $this->persisted = [];

        // Second run without --force: all 29 skipped.
        $exitCode = $tester->execute([]);
        self::assertSame(0, $exitCode);
        self::assertCount(
            0,
            $this->persisted,
            'second invocation without --force is a no-op (idempotency)',
        );
        $output = $tester->getDisplay();
        self::assertStringContainsString('skipped=29', $output);

        // Third run with --force: all 29 updated in place.
        $tester->execute(['--force' => true]);
        self::assertCount(29, $this->persisted, '--force triggers update on every existing row');
    }

    #[Test]
    public function testAllTwentyNineBsiTemplatesCreated(): void
    {
        $command = $this->makeCommand();
        $tester = new CommandTester($command);

        $tester->execute([]);
        self::assertCount(29, $this->persisted);

        $keys = array_map(
            static fn (PolicyTemplate $t): ?string => $t->getKey(),
            $this->persisted,
        );

        $expected = [
            // 28 Pflicht-Richtlinien (Anhang A)
            'bsi.it_security_policy',
            'bsi.isms_concept',
            'bsi.security_organization',
            'bsi.organization_policy',
            'bsi.personnel_policy',
            'bsi.awareness_policy',
            'bsi.iam',
            'bsi.crypto_concept',
            'bsi.privacy_policy',
            'bsi.backup_concept',
            'bsi.deletion_policy',
            'bsi.foreign_travel_policy',
            'bsi.software_development_policy',
            'bsi.information_exchange_policy',
            'bsi.web_application_policy',
            'bsi.it_administration_policy',
            'bsi.patch_change_management_policy',
            'bsi.malware_protection_policy',
            'bsi.logging_policy',
            'bsi.software_test_release_policy',
            'bsi.teleworking_policy',
            'bsi.remote_maintenance_policy',
            'bsi.cloud_usage_policy',
            'bsi.outsourcing_supplier_policy',
            'bsi.detection_policy',
            'bsi.incident_response',
            'bsi.it_forensics',
            'bsi.emergency_management',
            // +1 Schutzbedarfsfeststellungs-Methodik (§4.1)
            'bsi.protection_needs_methodology',
        ];

        sort($keys);
        sort($expected);
        self::assertSame($expected, $keys);

        // Every template carries standard='bsi' and a non-null bsi_tier.
        foreach ($this->persisted as $template) {
            self::assertSame('bsi', $template->getStandard(), $template->getKey() . ' standard=bsi');
            self::assertNotNull(
                $template->getBsiTier(),
                $template->getKey() . ' must declare a bsi_tier (basis|standard|kern)',
            );
            self::assertContains(
                $template->getBsiTier(),
                PolicyTemplate::BSI_TIERS,
                $template->getKey() . ' bsi_tier ∈ {basis,standard,kern}',
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
    public function testBsiTierAndLinkedBausteineAndAnnexAControls(): void
    {
        $command = $this->makeCommand();
        $tester = new CommandTester($command);

        $tester->execute([]);

        $byKey = [];
        foreach ($this->persisted as $template) {
            $byKey[$template->getKey()] = $template;
        }

        // Anhang A #1 — IT-Sicherheitsleitlinie: ISMS.1.A4 → A.5.1, 2J review.
        $top = $byKey['bsi.it_security_policy'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $top);
        self::assertSame(PolicyTemplate::BSI_TIER_BASIS, $top->getBsiTier());
        self::assertSame('ISMS.1.A4', $top->getNormRef());
        self::assertContains('ISMS.1.A4', $top->getLinkedBsiBausteine() ?? []);
        self::assertContains('ISMS.1.A5', $top->getLinkedBsiBausteine() ?? []);
        self::assertSame(['A.5.1'], $top->getLinkedAnnexAControls());
        self::assertSame(24, $top->getReviewIntervalMonths(), '2J review per Anhang A');
        // linkedBausteine root-level mirror covers ISMS.1
        self::assertSame(['ISMS.1'], $top->getLinkedBausteine());

        // Anhang A #7 — IAM (Basis+Std → tier=standard), ORP.4 → many A.5/A.8.
        $iam = $byKey['bsi.iam'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $iam);
        self::assertSame(
            PolicyTemplate::BSI_TIER_STANDARD,
            $iam->getBsiTier(),
            'IAM is Anhang A only "Basis+Std" entry — must map to standard tier',
        );
        $iamBs = $iam->getLinkedBsiBausteine() ?? [];
        self::assertContains('ORP.4.A1', $iamBs);
        self::assertContains('ORP.4.A22', $iamBs, 'A22 is the Anhang A spec edge case');
        self::assertSame(
            ['A.5.15', 'A.5.16', 'A.5.17', 'A.5.18', 'A.8.2'],
            $iam->getLinkedAnnexAControls(),
        );
        self::assertSame(12, $iam->getReviewIntervalMonths(), '1J review per Anhang A');

        // Anhang A #8 — Kryptokonzept: CON.1.A1-A4,A6 → A.8.24, 2J review.
        $crypto = $byKey['bsi.crypto_concept'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $crypto);
        self::assertSame(PolicyTemplate::BSI_TIER_BASIS, $crypto->getBsiTier());
        self::assertSame('programme', $crypto->getDocumentType());
        $cryptoBs = $crypto->getLinkedBsiBausteine() ?? [];
        self::assertContains('CON.1.A6', $cryptoBs, 'A6 is part of the Anhang A range');
        self::assertNotContains('CON.1.A5', $cryptoBs, 'A5 is intentionally skipped per Anhang A');
        self::assertSame(['A.8.24'], $crypto->getLinkedAnnexAControls());
        self::assertSame(24, $crypto->getReviewIntervalMonths());

        // Anhang A #28 — Notfallmanagement: DER.4 → A.5.29/30, 1J review,
        // mandatiert von BSI 200-4 → BCM approval chain.
        $emergency = $byKey['bsi.emergency_management'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $emergency);
        self::assertSame(PolicyTemplate::BSI_TIER_BASIS, $emergency->getBsiTier());
        self::assertSame(['DER.4.A1', 'DER.4.A2', 'DER.4.A3', 'DER.4.A4'], $emergency->getLinkedBsiBausteine());
        self::assertSame(['A.5.29', 'A.5.30'], $emergency->getLinkedAnnexAControls());
        self::assertContains('ROLE_BCM', $emergency->getApprovalChain() ?? []);

        // §4.1 — Schutzbedarfsfeststellungs-Methodik: methodology doc-type,
        // no Annex A / Baustein anchors (it's a method, not a Richtlinie).
        $method = $byKey['bsi.protection_needs_methodology'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $method);
        self::assertSame('methodology', $method->getDocumentType());
        self::assertSame(PolicyTemplate::BSI_TIER_BASIS, $method->getBsiTier());
        self::assertSame([], $method->getLinkedBsiBausteine() ?? []);
        self::assertNull(
            $method->getLinkedAnnexAControls(),
            'methodology has no Annex A anchor — null over empty',
        );
        self::assertSame(24, $method->getReviewIntervalMonths());

        // DPO-section gating: privacy_policy + deletion_policy + logging_policy
        // + it_forensics + personnel_policy carry dpo_section_required=true.
        self::assertTrue($byKey['bsi.privacy_policy']->isDpoSectionRequired());
        self::assertTrue($byKey['bsi.deletion_policy']->isDpoSectionRequired());
        self::assertTrue($byKey['bsi.logging_policy']->isDpoSectionRequired());
        self::assertTrue($byKey['bsi.it_forensics']->isDpoSectionRequired());
        self::assertTrue($byKey['bsi.personnel_policy']->isDpoSectionRequired());
        // and a non-DPO template should NOT have it set.
        self::assertFalse($byKey['bsi.crypto_concept']->isDpoSectionRequired());
    }
}
