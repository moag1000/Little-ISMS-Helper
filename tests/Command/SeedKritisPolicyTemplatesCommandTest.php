<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\SeedKritisPolicyTemplatesCommand;
use App\Entity\PolicyTemplate;
use App\Repository\PolicyTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Policy-Wizard W5-K — SeedKritisPolicyTemplatesCommand unit tests.
 *
 * Exercises the 8 KRITIS-sektorspezifischen B3S-Sektorprofile
 * (`app:policy-wizard:seed-kritis`) — covers BSI-KritisV §§ 2-7
 * (Energie, Wasser, IT/TK, Finanz, Gesundheit, Transport, Ernaehrung)
 * sowie BSIG § 8 (Staat/Verwaltung).
 *
 * Drei Vertraege:
 *   1. Idempotenz — Re-Run ohne `--force` ist No-Op.
 *   2. Vollstaendige Abdeckung — alle 8 Templates landen mit dem
 *      exakten Key-Set + standard='kritis' + bsi_tier='standard'.
 *   3. Per-Row-Korrektheit — norm_ref, linked_bsi_bausteine,
 *      linked_annex_a_controls und DPO-Section-Gating spot-check.
 */
#[AllowMockObjectsWithoutExpectations]
final class SeedKritisPolicyTemplatesCommandTest extends TestCase
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

    private function makeCommand(): SeedKritisPolicyTemplatesCommand
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

        return new SeedKritisPolicyTemplatesCommand($em, $repo);
    }

    #[Test]
    public function testSeedingIdempotent(): void
    {
        $command = $this->makeCommand();
        $tester = new CommandTester($command);

        // First run: 8 created, 0 skipped.
        $exitCode = $tester->execute([]);
        self::assertSame(0, $exitCode);
        self::assertCount(8, $this->persisted, 'first invocation creates all 8 sector profiles');
        $createdRows = $this->persisted;

        foreach ($createdRows as $template) {
            $key = $template->getKey();
            self::assertIsString($key);
            $this->existing[$key] = $template;
        }
        $this->persisted = [];

        // Second run without --force: all 8 skipped.
        $exitCode = $tester->execute([]);
        self::assertSame(0, $exitCode);
        self::assertCount(
            0,
            $this->persisted,
            'second invocation without --force is a no-op (idempotency)',
        );
        $output = $tester->getDisplay();
        self::assertStringContainsString('skipped=8', $output);

        // Third run with --force: all 8 updated in place.
        $tester->execute(['--force' => true]);
        self::assertCount(8, $this->persisted, '--force triggers update on every existing row');
    }

    #[Test]
    public function testAllEightKritisTemplatesCreated(): void
    {
        $command = $this->makeCommand();
        $tester = new CommandTester($command);

        $tester->execute([]);
        self::assertCount(8, $this->persisted);

        $keys = array_map(
            static fn (PolicyTemplate $t): ?string => $t->getKey(),
            $this->persisted,
        );

        $expected = [
            'kritis.kritis_energie_b3s',
            'kritis.kritis_wasser_b3s',
            'kritis.kritis_itk_b3s',
            'kritis.kritis_finanz_b3s',
            'kritis.kritis_gesundheit_b3s',
            'kritis.kritis_transport_logistik_b3s',
            'kritis.kritis_ernaehrung_b3s',
            'kritis.kritis_staat_verwaltung_b3s',
        ];

        sort($keys);
        sort($expected);
        self::assertSame($expected, $keys);

        // Every template carries standard='kritis', tier='standard',
        // 24-month review interval (BSIG § 8a Abs. 3 Pflichtintervall),
        // and document_type='sector_profile'.
        foreach ($this->persisted as $template) {
            self::assertSame('kritis', $template->getStandard(), $template->getKey() . ' standard=kritis');
            self::assertSame(
                PolicyTemplate::BSI_TIER_STANDARD,
                $template->getBsiTier(),
                $template->getKey() . ' KRITIS-Pflicht qualifies as Standard-Absicherung',
            );
            self::assertSame(
                24,
                $template->getReviewIntervalMonths(),
                $template->getKey() . ' 24-month review per BSIG § 8a Abs. 3',
            );
            self::assertSame(
                'sector_profile',
                $template->getDocumentType(),
                $template->getKey() . ' is a sector profile (B3S), not a policy/programme',
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
    public function testSectorSpecificContent(): void
    {
        $command = $this->makeCommand();
        $tester = new CommandTester($command);

        $tester->execute([]);

        $byKey = [];
        foreach ($this->persisted as $template) {
            $byKey[$template->getKey()] = $template;
        }

        // 1 — Energie: EnWG § 11.1b + BSI-KritisV § 2 + IND-Bausteine
        // (Smart-Grid OT-IT-Konvergenz)
        $energie = $byKey['kritis.kritis_energie_b3s'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $energie);
        self::assertStringContainsString('EnWG', $energie->getNormRef() ?? '');
        self::assertStringContainsString('BSI-KritisV § 2', $energie->getNormRef() ?? '');
        $energieBausteine = $energie->getLinkedBsiBausteine() ?? [];
        self::assertContains('IND.1.A1', $energieBausteine, 'Energie braucht IND-Baustein für OT');
        self::assertContains('NET.3.2.A1', $energieBausteine, 'Energie braucht NET.3.2 Firewall');
        // ROLE_BCM ist in approval_chain weil Schwarzfall-Konzept BCM-relevant
        self::assertContains('ROLE_BCM', $energie->getApprovalChain() ?? []);

        // 4 — Finanz: DORA + MaRisk + KWG cross-references
        $finanz = $byKey['kritis.kritis_finanz_b3s'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $finanz);
        self::assertStringContainsString('DORA', $finanz->getNormRef() ?? '');
        self::assertStringContainsString('KWG', $finanz->getNormRef() ?? '');
        self::assertStringContainsString('MaRisk', $finanz->getNormRef() ?? '');
        // Outsourcing-Bausteine wegen MaRisk AT 11.2 und DORA Art. 28-30
        $finanzBausteine = $finanz->getLinkedBsiBausteine() ?? [];
        self::assertContains('OPS.2.2.A1', $finanzBausteine, 'Finanz: Cloud-Nutzung');
        self::assertContains('OPS.2.3.A1', $finanzBausteine, 'Finanz: Outsourcing');
        // DPO-section verpflichtend (DSGVO Art. 9 Bonitätsdaten + Art. 22)
        self::assertTrue($finanz->isDpoSectionRequired());

        // 5 — Gesundheit: § 75c SGB V + DSGVO Art. 9 + KIM-Konnektor
        $gesundheit = $byKey['kritis.kritis_gesundheit_b3s'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $gesundheit);
        self::assertStringContainsString('SGB V', $gesundheit->getNormRef() ?? '');
        self::assertStringContainsString('KHZG', $gesundheit->getNormRef() ?? '');
        // Patientendaten = besondere Kategorie -> A.5.34 Privacy obligatorisch
        self::assertContains('A.5.34', $gesundheit->getLinkedAnnexAControls() ?? []);
        self::assertTrue($gesundheit->isDpoSectionRequired(), 'Patientendaten DSGVO Art. 9');

        // 8 — Staat/Verwaltung: BSIG § 8 + UP Bund + OZG (NICHT § 8a, weil
        // Bundesverwaltung von KRITIS i.d.R. ausgenommen ist; BSI § 8 ist
        // der Pflichten-Anker)
        $staat = $byKey['kritis.kritis_staat_verwaltung_b3s'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $staat);
        self::assertStringContainsString('UP Bund', $staat->getNormRef() ?? '');
        self::assertStringContainsString('OZG', $staat->getNormRef() ?? '');
        // DPO-section verpflichtend (DSGVO + BfDI-Aufsicht)
        self::assertTrue($staat->isDpoSectionRequired());
        // ORP.1 Organisation als Anker (UP Bund)
        self::assertContains('ORP.1.A1', $staat->getLinkedBsiBausteine() ?? []);

        // DPO-section gating per Sektor:
        // ITK + Finanz + Gesundheit + Staat -> true (DSGVO-Schwere)
        // Energie + Wasser + Transport + Ernaehrung -> false (kein DSGVO-Fokus)
        self::assertTrue($byKey['kritis.kritis_itk_b3s']->isDpoSectionRequired());
        self::assertTrue($byKey['kritis.kritis_finanz_b3s']->isDpoSectionRequired());
        self::assertTrue($byKey['kritis.kritis_gesundheit_b3s']->isDpoSectionRequired());
        self::assertTrue($byKey['kritis.kritis_staat_verwaltung_b3s']->isDpoSectionRequired());
        self::assertFalse($byKey['kritis.kritis_energie_b3s']->isDpoSectionRequired());
        self::assertFalse($byKey['kritis.kritis_wasser_b3s']->isDpoSectionRequired());
        self::assertFalse($byKey['kritis.kritis_transport_logistik_b3s']->isDpoSectionRequired());
        self::assertFalse($byKey['kritis.kritis_ernaehrung_b3s']->isDpoSectionRequired());

        // Topic translation key follows policy.kritis.<topic>.v1.title scheme
        foreach ($this->persisted as $template) {
            $titleKey = $template->getTitleTranslationKey() ?? '';
            self::assertStringStartsWith('policy.kritis.', $titleKey);
            self::assertStringEndsWith('.v1.title', $titleKey);
            $bodyKey = $template->getBodyTranslationKey() ?? '';
            self::assertStringStartsWith('policy.kritis.', $bodyKey);
            self::assertStringEndsWith('.v1.body', $bodyKey);
        }
    }

    #[Test]
    public function testDryRunDoesNotPersist(): void
    {
        $command = $this->makeCommand();
        $tester = new CommandTester($command);

        $tester->execute(['--dry-run' => true]);
        self::assertCount(
            0,
            $this->persisted,
            '--dry-run reports created counts but never calls persist()',
        );
        self::assertStringContainsString('created=8', $tester->getDisplay());
        self::assertStringContainsString('dry_run=yes', $tester->getDisplay());
    }
}
