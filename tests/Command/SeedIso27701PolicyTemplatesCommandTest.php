<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\SeedIso27701PolicyTemplatesCommand;
use App\Entity\PolicyTemplate;
use App\Repository\PolicyTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * SeedIso27701PolicyTemplatesCommand unit tests.
 *
 * Exercises the 10 ISO 27701:2025 PIMS-Pflicht-Policy-Templates
 * (`app:policy-wizard:seed-iso27701`).
 *
 * Three contracts asserted:
 *   1. Idempotency — re-runs without `--force` are no-ops.
 *   2. All 10 PIMS templates land with the exact key set + standard='iso27701'.
 *   3. ISO 27701 clause mapping correct (incl. 2019 vs 2025 6.13 delta).
 */
#[AllowMockObjectsWithoutExpectations]
final class SeedIso27701PolicyTemplatesCommandTest extends TestCase
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

    private function makeCommand(): SeedIso27701PolicyTemplatesCommand
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

        return new SeedIso27701PolicyTemplatesCommand($em, $repo);
    }

    #[Test]
    public function testSeedingIdempotent(): void
    {
        $command = $this->makeCommand();
        $tester = new CommandTester($command);

        // First run: 10 created, 0 skipped.
        $exitCode = $tester->execute([]);
        self::assertSame(0, $exitCode);
        self::assertCount(10, $this->persisted, 'first invocation creates all 10 templates');
        $createdRows = $this->persisted;

        // Pre-populate the repository so the second run finds them.
        foreach ($createdRows as $template) {
            $key = $template->getKey();
            self::assertIsString($key);
            $this->existing[$key] = $template;
        }
        $this->persisted = [];

        // Second run without --force: all 10 skipped.
        $exitCode = $tester->execute([]);
        self::assertSame(0, $exitCode);
        self::assertCount(
            0,
            $this->persisted,
            'second invocation without --force is a no-op (idempotency)',
        );
        $output = $tester->getDisplay();
        self::assertStringContainsString('skipped=10', $output);

        // Third run with --force: all 10 updated in place.
        $tester->execute(['--force' => true]);
        self::assertCount(10, $this->persisted, '--force triggers update on every existing row');
    }

    #[Test]
    public function testAllTenIso27701TemplatesCreated(): void
    {
        $command = $this->makeCommand();
        $tester = new CommandTester($command);

        $tester->execute([]);
        self::assertCount(10, $this->persisted);

        $keys = array_map(
            static fn (PolicyTemplate $t): ?string => $t->getKey(),
            $this->persisted,
        );

        $expected = [
            'iso27701.pims_top_level',
            'iso27701.role_data_controller_responsibilities',
            'iso27701.role_data_processor_responsibilities',
            'iso27701.pii_inventory_records_processing',
            'iso27701.consent_management_legal_basis',
            'iso27701.data_subject_rights_procedure',
            'iso27701.pia_dpia_methodology',
            'iso27701.data_breach_notification',
            'iso27701.third_party_transfer_policy',
            'iso27701.retention_disposal_policy',
        ];

        sort($keys);
        sort($expected);
        self::assertSame($expected, $keys);

        // Every PIMS template carries standard='iso27701', cross-refs
        // A.5.34, surfaces in the DPO inbox, ships v1 active, and has
        // a gated DPO sign-off section per Art. 38 Abs. 3 DSGVO.
        foreach ($this->persisted as $template) {
            self::assertSame('iso27701', $template->getStandard(), $template->getKey() . ' standard=iso27701');
            self::assertSame(['A.5.34'], $template->getLinkedAnnexAControls(), $template->getKey() . ' must link A.5.34');
            self::assertSame(['dpo'], $template->getAffectedFunctions(), $template->getKey() . ' must surface to DPO inbox');
            self::assertSame(1, $template->getVersion());
            self::assertTrue($template->isActive());
            self::assertTrue(
                $template->isDpoSectionRequired(),
                $template->getKey() . ' must require a DPO sign-off section',
            );
            self::assertFalse(
                $template->isClimateChangeWording(),
                'climate-change wording is an ISO 27001 Cl. 5.2 concern — irrelevant for PIMS docs',
            );
        }
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

        // PIMS Top-Level: Cl. 5.2 + 6.1.1.
        $top = $byKey['iso27701.pims_top_level'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $top);
        self::assertSame(['5.2', '6.1.1'], $top->getIso27701Clauses2025());
        self::assertSame(['5.2', '6.1.1'], $top->getIso27701Clauses2019());

        // Controller responsibilities: Cl. 7.2 + sub-clauses.
        $ctrl = $byKey['iso27701.role_data_controller_responsibilities'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $ctrl);
        $ctrlClauses = $ctrl->getIso27701Clauses2025();
        self::assertNotNull($ctrlClauses);
        self::assertContains('7.2', $ctrlClauses);
        self::assertContains('7.2.8', $ctrlClauses);

        // Processor responsibilities: Cl. 8.2 + sub-clauses.
        $proc = $byKey['iso27701.role_data_processor_responsibilities'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $proc);
        $procClauses = $proc->getIso27701Clauses2025();
        self::assertNotNull($procClauses);
        self::assertContains('8.2', $procClauses);
        self::assertContains('8.2.6', $procClauses);

        // DSR Procedure: full Cl. 7.3.1-7.3.10 range.
        $dsr = $byKey['iso27701.data_subject_rights_procedure'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $dsr);
        $dsrClauses = $dsr->getIso27701Clauses2025();
        self::assertNotNull($dsrClauses);
        self::assertCount(10, $dsrClauses, 'DSR covers Cl. 7.3.1 through 7.3.10');
        self::assertContains('7.3.1', $dsrClauses);
        self::assertContains('7.3.10', $dsrClauses);

        // PIA/DPIA: Cl. 6.2 + 7.2.5.
        $dpia = $byKey['iso27701.pia_dpia_methodology'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $dpia);
        self::assertSame(['6.2', '7.2.5'], $dpia->getIso27701Clauses2025());

        // Data Breach Notification: 6.13 (2025) was 6.13.1.5 (2019).
        // Only template where 2019 vs 2025 mapping diverges.
        $breach = $byKey['iso27701.data_breach_notification'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $breach);
        self::assertSame(['6.13'], $breach->getIso27701Clauses2025(), '2025 promoted breach response to top-level Cl. 6.13');
        self::assertSame(['6.13.1.5'], $breach->getIso27701Clauses2019(), '2019 had it as a sub-clause');

        // Third-Country Transfer: dual coverage Cl. 7.5 + 8.5.
        $tx = $byKey['iso27701.third_party_transfer_policy'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $tx);
        $txClauses = $tx->getIso27701Clauses2025();
        self::assertNotNull($txClauses);
        self::assertContains('7.5', $txClauses);
        self::assertContains('8.5', $txClauses);

        // Retention & Disposal: dual coverage Cl. 7.4 + 8.4.
        $ret = $byKey['iso27701.retention_disposal_policy'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $ret);
        $retClauses = $ret->getIso27701Clauses2025();
        self::assertNotNull($retClauses);
        self::assertContains('7.4', $retClauses);
        self::assertContains('8.4', $retClauses);

        // Document types per spec.
        self::assertSame('policy', $byKey['iso27701.pims_top_level']->getDocumentType());
        self::assertSame('policy', $byKey['iso27701.role_data_controller_responsibilities']->getDocumentType());
        self::assertSame('policy', $byKey['iso27701.role_data_processor_responsibilities']->getDocumentType());
        self::assertSame('methodology', $byKey['iso27701.pii_inventory_records_processing']->getDocumentType());
        self::assertSame('policy', $byKey['iso27701.consent_management_legal_basis']->getDocumentType());
        self::assertSame('procedure', $byKey['iso27701.data_subject_rights_procedure']->getDocumentType());
        self::assertSame('methodology', $byKey['iso27701.pia_dpia_methodology']->getDocumentType());
        self::assertSame('procedure', $byKey['iso27701.data_breach_notification']->getDocumentType());
        self::assertSame('policy', $byKey['iso27701.third_party_transfer_policy']->getDocumentType());
        self::assertSame('policy', $byKey['iso27701.retention_disposal_policy']->getDocumentType());

        // Top-Mgmt approval required for the high-stakes docs.
        self::assertContains('ROLE_TOP_MGMT', $byKey['iso27701.pims_top_level']->getApprovalChain() ?? []);
        self::assertContains('ROLE_TOP_MGMT', $byKey['iso27701.role_data_controller_responsibilities']->getApprovalChain() ?? []);
        self::assertContains('ROLE_TOP_MGMT', $byKey['iso27701.role_data_processor_responsibilities']->getApprovalChain() ?? []);
        self::assertContains('ROLE_TOP_MGMT', $byKey['iso27701.data_breach_notification']->getApprovalChain() ?? []);
        self::assertContains('ROLE_TOP_MGMT', $byKey['iso27701.third_party_transfer_policy']->getApprovalChain() ?? []);
        // RoPA / DSR / DPIA / Consent / Retention need DPO + CISO only.
        self::assertNotContains('ROLE_TOP_MGMT', $byKey['iso27701.pii_inventory_records_processing']->getApprovalChain() ?? []);
        self::assertNotContains('ROLE_TOP_MGMT', $byKey['iso27701.data_subject_rights_procedure']->getApprovalChain() ?? []);
        self::assertNotContains('ROLE_TOP_MGMT', $byKey['iso27701.pia_dpia_methodology']->getApprovalChain() ?? []);

        // Norm refs cite ISO 27701:2025 + relevant DSGVO articles.
        self::assertStringContainsString('ISO 27701:2025', $top->getNormRef() ?? '');
        self::assertStringContainsString('GDPR Art. 30', $byKey['iso27701.pii_inventory_records_processing']->getNormRef() ?? '');
        self::assertStringContainsString('GDPR Art. 33+34', $byKey['iso27701.data_breach_notification']->getNormRef() ?? '');
    }
}
