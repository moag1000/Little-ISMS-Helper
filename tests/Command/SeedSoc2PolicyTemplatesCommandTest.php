<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\SeedSoc2PolicyTemplatesCommand;
use App\Entity\PolicyTemplate;
use App\Repository\PolicyTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * SOC 2 Policy-Wizard — SeedSoc2PolicyTemplatesCommand unit tests.
 *
 * Exercises the 10 SOC 2 Trust Services Criteria policy templates seeded
 * via `app:policy-wizard:seed-soc2`. Mirrors the contract of
 * {@see SeedBsiPolicyTemplatesCommandTest} and
 * {@see SeedDoraPolicyTemplatesCommandTest}.
 *
 * Three contracts are asserted:
 *   1. Idempotency — re-runs without `--force` are no-ops.
 *   2. Exhaustive coverage — all 10 templates land with the exact key set.
 *   3. Per-row correctness — norm_ref, linked_annex_a_controls and the
 *      DPO-section gate match the SOC 2 TSC structure.
 */
#[AllowMockObjectsWithoutExpectations]
final class SeedSoc2PolicyTemplatesCommandTest extends TestCase
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

    private function makeCommand(): SeedSoc2PolicyTemplatesCommand
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

        return new SeedSoc2PolicyTemplatesCommand($em, $repo);
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
    public function testAllTenSoc2TemplatesCreated(): void
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
            'soc2.security_governance',
            'soc2.logical_physical_access_controls',
            'soc2.system_operations',
            'soc2.change_management',
            'soc2.risk_mitigation',
            'soc2.availability_principle',
            'soc2.confidentiality_principle',
            'soc2.processing_integrity_principle',
            'soc2.privacy_principle',
            'soc2.incident_response_communication',
        ];

        sort($keys);
        sort($expected);
        self::assertSame($expected, $keys);

        // Every template carries standard='soc2', no BSI tier, no DORA
        // articles, version=1, active and no climate-change wording (that
        // is an ISO 27001 Cl. 5.2 concern).
        foreach ($this->persisted as $template) {
            self::assertSame('soc2', $template->getStandard(), $template->getKey() . ' standard=soc2');
            self::assertNull($template->getBsiTier(), $template->getKey() . ' has no BSI tier');
            self::assertNull($template->getLinkedDoraArticles(), $template->getKey() . ' has no DORA articles');
            self::assertSame(1, $template->getVersion());
            self::assertTrue($template->isActive());
            self::assertSame(12, $template->getReviewIntervalMonths(), $template->getKey() . ' reviews annually');
            self::assertFalse(
                $template->isClimateChangeWording(),
                'climate-change wording is an ISO 27001 Cl. 5.2 concern only',
            );
            // Title + body translation keys follow the §8.7 versioning scheme.
            self::assertSame(
                sprintf('policy.soc2.%s.v1.title', $template->getTopic()),
                $template->getTitleTranslationKey(),
            );
            self::assertSame(
                sprintf('policy.soc2.%s.v1.body', $template->getTopic()),
                $template->getBodyTranslationKey(),
            );
        }
    }

    #[Test]
    public function testNormAnchorsAndAnnexAMappings(): void
    {
        $command = $this->makeCommand();
        $tester = new CommandTester($command);

        $tester->execute([]);

        $byKey = [];
        foreach ($this->persisted as $template) {
            $byKey[$template->getKey()] = $template;
        }

        // Governance — CC1+CC2+CC3 → A.5.1, A.5.2, A.5.4, A.5.7, A.5.8, A.6.3.
        $gov = $byKey['soc2.security_governance'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $gov);
        self::assertSame('TSC CC1-CC3', $gov->getNormRef());
        self::assertSame('policy', $gov->getDocumentType());
        self::assertContains('A.5.1', $gov->getLinkedAnnexAControls() ?? []);
        self::assertContains('A.5.7', $gov->getLinkedAnnexAControls() ?? []);

        // Access Controls — CC6 → A.5.15-A.5.18, A.7.1, A.7.2, A.8.2-A.8.5.
        $access = $byKey['soc2.logical_physical_access_controls'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $access);
        self::assertSame('TSC CC6', $access->getNormRef());
        self::assertContains('A.5.15', $access->getLinkedAnnexAControls() ?? []);
        self::assertContains('A.5.18', $access->getLinkedAnnexAControls() ?? []);
        self::assertContains('A.7.2', $access->getLinkedAnnexAControls() ?? []);
        self::assertContains('A.8.5', $access->getLinkedAnnexAControls() ?? []);

        // System Operations — CC7 → A.8.16 (monitoring) is the canonical anchor.
        $sysOps = $byKey['soc2.system_operations'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $sysOps);
        self::assertSame('TSC CC7', $sysOps->getNormRef());
        self::assertContains('A.8.16', $sysOps->getLinkedAnnexAControls() ?? []);
        self::assertContains('A.8.8', $sysOps->getLinkedAnnexAControls() ?? []);

        // Change Management — CC8.1 → A.8.32 (the headline).
        $chg = $byKey['soc2.change_management'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $chg);
        self::assertSame('TSC CC8.1', $chg->getNormRef());
        self::assertContains('A.8.32', $chg->getLinkedAnnexAControls() ?? []);

        // Risk Mitigation / Vendor — CC9 → A.5.19-A.5.23 (supplier suite).
        $risk = $byKey['soc2.risk_mitigation'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $risk);
        self::assertSame('TSC CC9', $risk->getNormRef());
        self::assertSame(['A.5.19', 'A.5.20', 'A.5.21', 'A.5.22', 'A.5.23'], $risk->getLinkedAnnexAControls());
        self::assertContains('ROLE_PROCUREMENT', $risk->getApprovalChain() ?? []);

        // Availability — A1.1-A1.3 → A.5.29 + A.5.30 (BC) + A.8.13 (backup).
        $avail = $byKey['soc2.availability_principle'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $avail);
        self::assertSame('TSC A1.1-A1.3', $avail->getNormRef());
        self::assertContains('A.5.29', $avail->getLinkedAnnexAControls() ?? []);
        self::assertContains('A.5.30', $avail->getLinkedAnnexAControls() ?? []);
        self::assertContains('A.8.13', $avail->getLinkedAnnexAControls() ?? []);
        self::assertContains('ROLE_BCM', $avail->getApprovalChain() ?? []);

        // Confidentiality — C1.1-C1.2 → A.5.12 (classification) + A.8.24 (crypto).
        $conf = $byKey['soc2.confidentiality_principle'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $conf);
        self::assertSame('TSC C1.1-C1.2', $conf->getNormRef());
        self::assertContains('A.5.12', $conf->getLinkedAnnexAControls() ?? []);
        self::assertContains('A.8.24', $conf->getLinkedAnnexAControls() ?? []);

        // Processing Integrity — PI1.1-PI1.5 → A.8.25-A.8.29 (secure SDLC).
        $pi = $byKey['soc2.processing_integrity_principle'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $pi);
        self::assertSame('TSC PI1.1-PI1.5', $pi->getNormRef());
        self::assertContains('A.8.25', $pi->getLinkedAnnexAControls() ?? []);
        self::assertContains('A.8.29', $pi->getLinkedAnnexAControls() ?? []);

        // Privacy — P1-P8 → A.5.34 (privacy + PII) + A.8.10/.11/.12 (deletion / masking / DLP).
        // Privacy is the only SOC 2 template that requires DPO sign-off.
        $priv = $byKey['soc2.privacy_principle'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $priv);
        self::assertSame('TSC P1-P8', $priv->getNormRef());
        self::assertContains('A.5.34', $priv->getLinkedAnnexAControls() ?? []);
        self::assertContains('A.8.11', $priv->getLinkedAnnexAControls() ?? []);
        self::assertTrue($priv->isDpoSectionRequired(), 'Privacy template requires DPO cross-check');
        self::assertContains('ROLE_DPO', $priv->getApprovalChain() ?? []);

        // Incident Response — CC2.3 + CC7.4 → A.5.24-A.5.28 + A.6.8 (event reporting).
        $ir = $byKey['soc2.incident_response_communication'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $ir);
        self::assertSame('TSC CC2.3 + CC7.4', $ir->getNormRef());
        self::assertContains('A.5.24', $ir->getLinkedAnnexAControls() ?? []);
        self::assertContains('A.5.26', $ir->getLinkedAnnexAControls() ?? []);
        self::assertContains('A.6.8', $ir->getLinkedAnnexAControls() ?? []);
        self::assertContains('ROLE_TOP_MGMT', $ir->getApprovalChain() ?? []);

        // Default DPO-section state for the 9 non-Privacy templates is false.
        foreach ($byKey as $key => $template) {
            if ($key === 'soc2.privacy_principle') {
                continue;
            }
            self::assertFalse(
                $template->isDpoSectionRequired(),
                sprintf('%s should not require DPO sign-off', $key),
            );
        }
    }
}
