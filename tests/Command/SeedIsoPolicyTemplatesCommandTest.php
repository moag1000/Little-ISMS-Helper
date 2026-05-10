<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\SeedIsoPolicyTemplatesCommand;
use App\Entity\PolicyTemplate;
use App\Repository\PolicyTemplateRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardTopicCatalogue;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * SeedIsoPolicyTemplatesCommand unit tests.
 *
 * Asserts three contracts of the ISO 27001 PolicyTemplate seed
 * (`app:policy-wizard:seed-iso27001`):
 *   1. Idempotency — re-runs without `--force` are no-ops; `--force`
 *      updates every existing row.
 *   2. Exhaustive coverage — all 25 templates land (1 top_level + 24
 *      catalogue topics from PolicyWizardTopicCatalogue::ISO27001_TOPICS).
 *   3. Per-row correctness — translation key paths follow the
 *      `policy.iso27001.<translation_topic>.v1.{title,body}` shape, with
 *      the documented 4 catalogue→translation overrides honoured.
 */
#[AllowMockObjectsWithoutExpectations]
final class SeedIsoPolicyTemplatesCommandTest extends TestCase
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

    private function makeCommand(): SeedIsoPolicyTemplatesCommand
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

        return new SeedIsoPolicyTemplatesCommand($em, $repo);
    }

    #[Test]
    public function testIdempotency(): void
    {
        $command = $this->makeCommand();
        $tester = new CommandTester($command);

        // First run: 25 created.
        $exitCode = $tester->execute([]);
        self::assertSame(0, $exitCode);
        self::assertCount(25, $this->persisted, 'first invocation creates all 25 ISO 27001 templates');
        $createdRows = $this->persisted;

        // Pre-populate the repository so the second run finds them.
        foreach ($createdRows as $template) {
            $key = $template->getKey();
            self::assertIsString($key);
            $this->existing[$key] = $template;
        }
        $this->persisted = [];

        // Second run without --force: 25 skipped, 0 persisted.
        $tester->execute([]);
        self::assertCount(
            0,
            $this->persisted,
            'second invocation without --force is a no-op (idempotency)',
        );
        $output = $tester->getDisplay();
        self::assertStringContainsString('skipped=25', $output);

        // Third run with --force: every existing row updated in place.
        $tester->execute(['--force' => true]);
        self::assertCount(25, $this->persisted, '--force triggers update on every existing row');
    }

    #[Test]
    public function test25TemplatesCreated(): void
    {
        $command = $this->makeCommand();
        $tester = new CommandTester($command);

        $tester->execute([]);
        self::assertCount(25, $this->persisted);

        $keys = array_map(
            static fn (PolicyTemplate $t): ?string => $t->getKey(),
            $this->persisted,
        );

        // 1 cross-cutting top-level + 24 catalogue topics
        $expected = ['iso27001.top_level'];
        foreach (PolicyWizardTopicCatalogue::ISO27001_TOPICS as $topic) {
            $expected[] = 'iso27001.' . $topic;
        }

        sort($keys);
        sort($expected);
        self::assertSame($expected, $keys, 'seed must cover top_level + every catalogue topic');

        // Every template carries standard='iso27001', is active, version=1,
        // and a NULL bsi_tier (ISO templates carry no BSI tier semantics).
        foreach ($this->persisted as $template) {
            self::assertSame('iso27001', $template->getStandard(), $template->getKey() . ' standard=iso27001');
            self::assertSame(1, $template->getVersion(), $template->getKey() . ' version=1');
            self::assertTrue($template->isActive(), $template->getKey() . ' is_active=true');
            self::assertNull($template->getBsiTier(), $template->getKey() . ' bsi_tier=null (ISO carries no BSI tier)');
            self::assertNotNull($template->getTitleTranslationKey());
            self::assertNotNull($template->getBodyTranslationKey());
            self::assertStringStartsWith('policy.iso27001.', (string) $template->getTitleTranslationKey());
        }
    }

    #[Test]
    public function testFieldsCorrectForKnownTopic(): void
    {
        $command = $this->makeCommand();
        $tester = new CommandTester($command);

        $tester->execute([]);

        $byKey = [];
        foreach ($this->persisted as $template) {
            $byKey[$template->getKey()] = $template;
        }

        // Spot-check 1 — access_control: A.5.15 lead, multi-control link,
        // 12 month review, no DPO gate, no Works-Council, no climate.
        $access = $byKey['iso27001.access_control'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $access);
        self::assertSame('access_control', $access->getTopic());
        self::assertSame('policy', $access->getDocumentType());
        self::assertSame('A.5.15', $access->getNormRef());
        self::assertSame(
            ['A.5.15', 'A.5.16', 'A.5.17', 'A.5.18', 'A.8.2', 'A.8.3', 'A.8.4', 'A.8.5'],
            $access->getLinkedAnnexAControls(),
        );
        self::assertSame(12, $access->getReviewIntervalMonths());
        self::assertSame(['ROLE_CISO'], $access->getApprovalChain());
        self::assertFalse($access->isDpoSectionRequired());
        self::assertFalse($access->isRequiresWorksCouncilEvidence());
        self::assertFalse($access->isClimateChangeWording());
        self::assertSame(
            'policy.iso27001.access_control.v1.title',
            $access->getTitleTranslationKey(),
        );
        self::assertSame(
            'policy.iso27001.access_control.v1.body',
            $access->getBodyTranslationKey(),
        );

        // Spot-check 2 — top_level: ISO 27001 Cl. 5.2, 24-month review,
        // climate_change_wording=true (Amd. 1:2024 driver).
        $top = $byKey['iso27001.top_level'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $top);
        self::assertSame('top_level', $top->getTopic());
        self::assertSame('ISO/IEC 27001:2022 Cl. 5.2', $top->getNormRef());
        self::assertSame(24, $top->getReviewIntervalMonths());
        self::assertTrue($top->isClimateChangeWording(), 'top_level must carry Amd. 1:2024 climate-change wording');
        self::assertSame(['ROLE_CISO', 'ROLE_TOP_MGMT'], $top->getApprovalChain());

        // Spot-check 3 — privacy_pii: catalogue topic ≠ translation
        // topic. PolicyTemplate.topic stays at the catalogue key
        // (`privacy_pii`) while translation keys point at the historic
        // `privacy` translation path. DPO gate ON, climate=true.
        $privacy = $byKey['iso27001.privacy_pii'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $privacy);
        self::assertSame('privacy_pii', $privacy->getTopic());
        self::assertSame(
            'policy.iso27001.privacy.v1.title',
            $privacy->getTitleTranslationKey(),
            'translation key uses historic `privacy` topic, not catalogue `privacy_pii`',
        );
        self::assertSame(
            'policy.iso27001.privacy.v1.body',
            $privacy->getBodyTranslationKey(),
        );
        self::assertTrue($privacy->isDpoSectionRequired(), 'privacy_pii must require DPO sign-off');
        self::assertContains('ROLE_DPO', $privacy->getApprovalChain() ?? []);
        self::assertTrue($privacy->isClimateChangeWording());

        // Spot-check 4 — malware: catalogue topic ≠ translation topic
        // (`malware` vs `malware_protection`).
        $malware = $byKey['iso27001.malware'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $malware);
        self::assertSame('malware', $malware->getTopic());
        self::assertSame(
            'policy.iso27001.malware_protection.v1.title',
            $malware->getTitleTranslationKey(),
        );
        self::assertSame(['A.8.7', 'A.6.3', 'A.8.32'], $malware->getLinkedAnnexAControls());

        // Spot-check 5 — supplier_relationships: catalogue topic ≠
        // translation topic (`supplier_relationships` vs `supplier_security`).
        // climate_change_wording=true per architecture §6 Step 2.
        $supplier = $byKey['iso27001.supplier_relationships'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $supplier);
        self::assertSame('supplier_relationships', $supplier->getTopic());
        self::assertSame(
            'policy.iso27001.supplier_security.v1.title',
            $supplier->getTitleTranslationKey(),
        );
        self::assertTrue($supplier->isClimateChangeWording());

        // Spot-check 6 — mobile_device: catalogue topic ≠ translation
        // topic (`mobile_device` vs `mobile_teleworking`). Works-Council
        // evidence required (BYOD/monitoring overlap).
        $mobile = $byKey['iso27001.mobile_device'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $mobile);
        self::assertSame('mobile_device', $mobile->getTopic());
        self::assertSame(
            'policy.iso27001.mobile_teleworking.v1.title',
            $mobile->getTitleTranslationKey(),
        );
        self::assertTrue($mobile->isRequiresWorksCouncilEvidence());

        // Spot-check 7 — translationTopicFor() static helper round-trip.
        self::assertSame('malware_protection', SeedIsoPolicyTemplatesCommand::translationTopicFor('malware'));
        self::assertSame('supplier_security', SeedIsoPolicyTemplatesCommand::translationTopicFor('supplier_relationships'));
        self::assertSame('privacy', SeedIsoPolicyTemplatesCommand::translationTopicFor('privacy_pii'));
        self::assertSame('mobile_teleworking', SeedIsoPolicyTemplatesCommand::translationTopicFor('mobile_device'));
        // identity for non-overridden keys
        self::assertSame('access_control', SeedIsoPolicyTemplatesCommand::translationTopicFor('access_control'));
        self::assertSame('cryptography', SeedIsoPolicyTemplatesCommand::translationTopicFor('cryptography'));
    }
}
