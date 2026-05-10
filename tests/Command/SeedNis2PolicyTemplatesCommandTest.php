<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\SeedNis2PolicyTemplatesCommand;
use App\Entity\PolicyTemplate;
use App\Repository\PolicyTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * SeedNis2PolicyTemplatesCommand unit tests.
 *
 * Exercises the 12 NIS2 Pflicht-Richtlinien (`app:policy-wizard:seed-nis2`)
 * covering Art. 21 (security measures) and Art. 23 (reporting) of NIS2-RL
 * (Directive (EU) 2022/2555) plus § 32 NIS2UmsuCG.
 *
 * Three contracts are asserted:
 *   1. Idempotency — re-runs without `--force` are no-ops.
 *   2. Exhaustive coverage — all 12 templates land with the exact key set.
 *   3. Per-row correctness — linked_annex_a_controls, dpo_section flags
 *      and norm references match the Art. 21 / Art. 23 spec.
 */
#[AllowMockObjectsWithoutExpectations]
final class SeedNis2PolicyTemplatesCommandTest extends TestCase
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

    private function makeCommand(): SeedNis2PolicyTemplatesCommand
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

        return new SeedNis2PolicyTemplatesCommand($em, $repo);
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
    public function testAllTwelveNis2TemplatesCreated(): void
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
            'nis2.governance_framework',
            'nis2.risk_management_policy',
            'nis2.incident_handling_policy',
            'nis2.business_continuity_crisis_policy',
            'nis2.supply_chain_security_policy',
            'nis2.acquisition_development_security',
            'nis2.assessment_effectiveness_policy',
            'nis2.basic_cyber_hygiene_training',
            'nis2.cryptography_policy',
            'nis2.access_control_asset_mgmt',
            'nis2.mfa_communication_policy',
            'nis2.reporting_obligations_procedure',
        ];

        sort($keys);
        sort($expected);
        self::assertSame($expected, $keys);

        // Every template carries standard='nis2', non-null annex-A controls
        // (we deliberately link every NIS2 measure to ISO 27001 Annex A as
        // the data-reuse evidence anchor) and version 1.
        foreach ($this->persisted as $template) {
            self::assertSame('nis2', $template->getStandard(), $template->getKey() . ' standard=nis2');
            self::assertSame(1, $template->getVersion());
            self::assertTrue($template->isActive());
            self::assertFalse(
                $template->isClimateChangeWording(),
                'climate-change wording is an ISO 27001 Cl. 5.2 concern only',
            );
            self::assertNull(
                $template->getBsiTier(),
                $template->getKey() . ' must NOT carry a bsi_tier (NIS2 has no Basis/Standard/Kern)',
            );
            self::assertNotEmpty(
                $template->getLinkedAnnexAControls() ?? [],
                $template->getKey() . ' must link to at least one Annex A control (data-reuse anchor)',
            );
            self::assertNotEmpty(
                $template->getApprovalChain() ?? [],
                $template->getKey() . ' must declare an approval chain',
            );
        }
    }

    #[Test]
    public function testLinkedAnnexAControlsAndNormRefs(): void
    {
        $command = $this->makeCommand();
        $tester = new CommandTester($command);

        $tester->execute([]);

        $byKey = [];
        foreach ($this->persisted as $template) {
            $byKey[$template->getKey()] = $template;
        }

        // Art. 21(2)(a) — Risk-management policy maps to A.5.1, A.5.7, A.5.8.
        $risk = $byKey['nis2.risk_management_policy'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $risk);
        self::assertSame('NIS2 Art. 21 Abs. 2 lit. a', $risk->getNormRef());
        self::assertSame(['A.5.1', 'A.5.7', 'A.5.8'], $risk->getLinkedAnnexAControls());
        self::assertSame(12, $risk->getReviewIntervalMonths());

        // Art. 21(2)(b) + Art. 23 — Incident-handling links to A.5.24-A.5.28
        // plus A.6.8 (reporting). DPO section required because of GDPR overlap.
        $incident = $byKey['nis2.incident_handling_policy'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $incident);
        self::assertStringContainsString('Art. 21 Abs. 2 lit. b', $incident->getNormRef() ?? '');
        self::assertStringContainsString('Art. 23', $incident->getNormRef() ?? '');
        $incBs = $incident->getLinkedAnnexAControls() ?? [];
        self::assertContains('A.5.24', $incBs);
        self::assertContains('A.5.25', $incBs);
        self::assertContains('A.5.26', $incBs);
        self::assertContains('A.5.27', $incBs);
        self::assertContains('A.5.28', $incBs);
        self::assertContains('A.6.8', $incBs, 'A.6.8 = ISMS event reporting (NIS2 Art. 23 anchor)');
        self::assertTrue($incident->isDpoSectionRequired(), 'incident handling crosses GDPR Art. 33');

        // Art. 21(2)(d) — Supply-chain links to the 5 supplier controls A.5.19-A.5.23.
        $supply = $byKey['nis2.supply_chain_security_policy'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $supply);
        self::assertSame('NIS2 Art. 21 Abs. 2 lit. d', $supply->getNormRef());
        self::assertSame(
            ['A.5.19', 'A.5.20', 'A.5.21', 'A.5.22', 'A.5.23'],
            $supply->getLinkedAnnexAControls(),
        );

        // Art. 21(2)(h) — Cryptography links to A.8.24 only, 24mo review per BSI.
        $crypto = $byKey['nis2.cryptography_policy'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $crypto);
        self::assertSame(['A.8.24'], $crypto->getLinkedAnnexAControls());
        self::assertSame(24, $crypto->getReviewIntervalMonths(), 'crypto review tracks BSI TR-02102 cycle');

        // Art. 21(2)(j) — MFA links to authentication + secure-comms controls.
        $mfa = $byKey['nis2.mfa_communication_policy'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $mfa);
        $mfaControls = $mfa->getLinkedAnnexAControls() ?? [];
        self::assertContains('A.5.14', $mfaControls, 'A.5.14 = secure transfer');
        self::assertContains('A.8.5', $mfaControls, 'A.8.5 = secure authentication / MFA anchor');
        self::assertContains('A.8.20', $mfaControls);

        // Art. 23 — Reporting procedure links to authority-contact controls
        // and is a procedure document type, not a policy. DPO required.
        $reporting = $byKey['nis2.reporting_obligations_procedure'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $reporting);
        self::assertSame('procedure', $reporting->getDocumentType());
        $reportingControls = $reporting->getLinkedAnnexAControls() ?? [];
        self::assertContains('A.5.5', $reportingControls, 'A.5.5 = contact with authorities');
        self::assertContains('A.5.6', $reportingControls, 'A.5.6 = contact with special interest groups');
        self::assertContains('A.6.8', $reportingControls);
        self::assertTrue($reporting->isDpoSectionRequired(), 'reporting procedure crosses GDPR Art. 33');

        // Governance framework spans the four governance controls.
        $gov = $byKey['nis2.governance_framework'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $gov);
        self::assertSame(
            ['A.5.1', 'A.5.2', 'A.5.3', 'A.5.4'],
            $gov->getLinkedAnnexAControls(),
        );
        self::assertContains('ROLE_TOP_MGMT', $gov->getApprovalChain() ?? []);

        // BCM template has BCM role in approval chain (not just CISO).
        $bcm = $byKey['nis2.business_continuity_crisis_policy'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $bcm);
        self::assertContains('ROLE_BCM', $bcm->getApprovalChain() ?? []);

        // Translation keys follow the policy.nis2.<topic>.v1.{title,body} schema.
        foreach ($this->persisted as $template) {
            $topic = $template->getTopic();
            self::assertSame(
                'policy.nis2.' . $topic . '.v1.title',
                $template->getTitleTranslationKey(),
                $template->getKey() . ' title-key schema',
            );
            self::assertSame(
                'policy.nis2.' . $topic . '.v1.body',
                $template->getBodyTranslationKey(),
                $template->getKey() . ' body-key schema',
            );
        }
    }

    #[Test]
    public function testDryRunDoesNotPersist(): void
    {
        $command = $this->makeCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--dry-run' => true]);
        self::assertSame(0, $exitCode);
        self::assertCount(
            0,
            $this->persisted,
            '--dry-run must not call EntityManager::persist',
        );

        $output = $tester->getDisplay();
        self::assertStringContainsString('dry_run=yes', $output);
        self::assertStringContainsString('created=12', $output);
    }
}
