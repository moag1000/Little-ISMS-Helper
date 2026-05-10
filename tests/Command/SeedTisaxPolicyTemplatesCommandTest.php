<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\SeedTisaxPolicyTemplatesCommand;
use App\Entity\PolicyTemplate;
use App\Repository\PolicyTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Policy-Wizard W7-A — SeedTisaxPolicyTemplatesCommand unit tests.
 *
 * Exercises the 10 TISAX (VDA ISA + PSx + DSx) Pflicht-Richtlinien
 * seed (`app:policy-wizard:seed-tisax`).
 *
 * Three contracts are asserted:
 *   1. Idempotency — re-runs without `--force` are no-ops.
 *   2. Exhaustive coverage — all 10 templates land with the exact key set.
 *   3. Per-row correctness — standard='tisax', no BSI tier, ISO 27001
 *      Annex A cross-mapping, DPO-section gating for HR + DSx.
 */
#[AllowMockObjectsWithoutExpectations]
final class SeedTisaxPolicyTemplatesCommandTest extends TestCase
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

    private function makeCommand(): SeedTisaxPolicyTemplatesCommand
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

        return new SeedTisaxPolicyTemplatesCommand($em, $repo);
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
    public function testAllTenTisaxTemplatesCreated(): void
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
            // ISA 1.x - 8.x (8 core topics)
            'tisax.information_security_policies',
            'tisax.organisation_information_security',
            'tisax.human_resources_security',
            'tisax.physical_environmental_security',
            'tisax.identity_access_management',
            'tisax.it_security_operations',
            'tisax.supplier_relationships_security',
            'tisax.compliance_management',
            // PSx — Prototypenschutz (mandatory at protection-need high)
            'tisax.prototype_protection',
            // DSx — Datenschutz-Anhang (mandatory when PII in scope)
            'tisax.data_protection_addendum',
        ];

        sort($keys);
        sort($expected);
        self::assertSame($expected, $keys);

        // Every template carries standard='tisax', no BSI tier, version 1, active.
        foreach ($this->persisted as $template) {
            self::assertSame('tisax', $template->getStandard(), $template->getKey() . ' standard=tisax');
            self::assertNull(
                $template->getBsiTier(),
                $template->getKey() . ' must NOT carry a bsi_tier (TISAX is its own standard)',
            );
            self::assertSame(1, $template->getVersion());
            self::assertTrue($template->isActive());
            self::assertFalse(
                $template->isClimateChangeWording(),
                'climate-change wording is an ISO 27001 Cl. 5.2 concern only',
            );
            self::assertSame(12, $template->getReviewIntervalMonths(), 'TISAX standard review cycle is annual');
            self::assertNull($template->getLinkedBausteine(), 'TISAX has no BSI Bausteine');
            self::assertNull($template->getLinkedBsiBausteine(), 'TISAX has no BSI Bausteine');
            self::assertNull($template->getLinkedDoraArticles(), 'TISAX has no DORA articles');
        }
    }

    #[Test]
    public function testPerRowFidelity(): void
    {
        $command = $this->makeCommand();
        $tester = new CommandTester($command);

        $tester->execute([]);

        $byKey = [];
        foreach ($this->persisted as $template) {
            $byKey[$template->getKey()] = $template;
        }

        // ISA 1.x — IS-Leitlinie: top-level policy → A.5.1, A.5.2.
        $top = $byKey['tisax.information_security_policies'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $top);
        self::assertSame('VDA ISA 1.x', $top->getNormRef());
        self::assertSame('policy', $top->getDocumentType());
        self::assertSame(['A.5.1', 'A.5.2'], $top->getLinkedAnnexAControls());
        self::assertSame(
            ['ROLE_CISO', 'ROLE_TOP_MGMT'],
            $top->getApprovalChain(),
        );

        // ISA 3.x — Personalsicherheit: HR + CISO + TOP_MGMT approval, DPO-section
        // because of BDSG § 26 Beschaeftigtendaten.
        $hr = $byKey['tisax.human_resources_security'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $hr);
        self::assertSame('VDA ISA 3.x', $hr->getNormRef());
        self::assertContains('A.6.1', $hr->getLinkedAnnexAControls() ?? []);
        self::assertContains('A.6.6', $hr->getLinkedAnnexAControls() ?? []);
        self::assertTrue(
            $hr->isDpoSectionRequired(),
            'HR policy touches employee data → DSGVO/BDSG § 26 → DPO section required',
        );
        self::assertSame(
            ['ROLE_HR', 'ROLE_CISO', 'ROLE_TOP_MGMT'],
            $hr->getApprovalChain(),
        );

        // ISA 5.x — IAM: covers A.5.15 - A.5.18 + A.8.2 - A.8.5 (8 controls).
        $iam = $byKey['tisax.identity_access_management'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $iam);
        self::assertSame('VDA ISA 5.x', $iam->getNormRef());
        self::assertSame(
            ['A.5.15', 'A.5.16', 'A.5.17', 'A.5.18', 'A.8.2', 'A.8.3', 'A.8.4', 'A.8.5'],
            $iam->getLinkedAnnexAControls(),
        );
        self::assertFalse($iam->isDpoSectionRequired());

        // ISA 7.x — Lieferantensicherheit: A.5.19 - A.5.23.
        $supplier = $byKey['tisax.supplier_relationships_security'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $supplier);
        self::assertSame('VDA ISA 7.x', $supplier->getNormRef());
        self::assertSame(
            ['A.5.19', 'A.5.20', 'A.5.21', 'A.5.22', 'A.5.23'],
            $supplier->getLinkedAnnexAControls(),
        );

        // PSx — Prototypenschutz: programme document, NO DPO section
        // (engineering data, not personal data), ENGINEERING + PRODUCTION
        // affected functions — drives function-owner-review workflow.
        $proto = $byKey['tisax.prototype_protection'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $proto);
        self::assertSame('VDA ISA PSx', $proto->getNormRef());
        self::assertSame(
            'programme',
            $proto->getDocumentType(),
            'Prototype protection is a programme-level document, not a single policy',
        );
        self::assertFalse($proto->isDpoSectionRequired());
        $protoFn = $proto->getAffectedFunctions() ?? [];
        self::assertContains('ENGINEERING', $protoFn);
        self::assertContains('PRODUCTION', $protoFn);
        self::assertContains('FACILITIES', $protoFn);
        // PSx links to A.7.x (physical) + A.8.12 (DLP) + A.6.6 (NDA)
        $protoControls = $proto->getLinkedAnnexAControls() ?? [];
        self::assertContains('A.7.1', $protoControls);
        self::assertContains('A.6.6', $protoControls, 'NDA mapping for prototype access');
        self::assertContains('A.8.12', $protoControls, 'DLP mapping for prototype data leakage');

        // DSx — Datenschutz-Anhang: DPO-section required, DPO leads approval chain.
        $dsx = $byKey['tisax.data_protection_addendum'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $dsx);
        self::assertSame('VDA ISA DSx', $dsx->getNormRef());
        self::assertTrue($dsx->isDpoSectionRequired());
        self::assertSame(
            ['ROLE_DPO', 'ROLE_CISO', 'ROLE_LEGAL'],
            $dsx->getApprovalChain(),
            'DSx is DPO-led — approval chain begins with DPO',
        );
        self::assertSame(
            ['A.5.34', 'A.8.10', 'A.8.11', 'A.8.12'],
            $dsx->getLinkedAnnexAControls(),
        );
        $dsxFn = $dsx->getAffectedFunctions() ?? [];
        self::assertContains('DPO', $dsxFn);
        self::assertContains('LEGAL', $dsxFn);

        // ISA 4.x — Physische + umgebungsbezogene Sicherheit covers
        // the full ISO 27001 A.7 cluster (14 controls A.7.1 - A.7.14).
        $physical = $byKey['tisax.physical_environmental_security'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $physical);
        self::assertCount(14, $physical->getLinkedAnnexAControls() ?? []);
    }

    #[Test]
    public function testTranslationKeysFollowVersioningScheme(): void
    {
        $command = $this->makeCommand();
        $tester = new CommandTester($command);

        $tester->execute([]);

        foreach ($this->persisted as $template) {
            $titleKey = $template->getTitleTranslationKey() ?? '';
            $bodyKey = $template->getBodyTranslationKey() ?? '';
            $topic = $template->getTopic() ?? '';

            self::assertSame(
                'policy.tisax.' . $topic . '.v1.title',
                $titleKey,
                $template->getKey() . ' title key follows §8.7 versioning scheme',
            );
            self::assertSame(
                'policy.tisax.' . $topic . '.v1.body',
                $bodyKey,
                $template->getKey() . ' body key follows §8.7 versioning scheme',
            );
        }
    }
}
