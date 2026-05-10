<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\SeedBsiExtraBausteineCommand;
use App\Entity\PolicyTemplate;
use App\Repository\PolicyTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * SeedBsiExtraBausteineCommand unit tests.
 *
 * Asserts:
 *   1. Idempotency — re-runs without --force are no-ops.
 *   2. Exhaustive coverage — all 15 templates land with exact key set.
 *   3. Per-row correctness — bsi_tier and norm anchors per spot-check rows.
 */
#[AllowMockObjectsWithoutExpectations]
final class SeedBsiExtraBausteineCommandTest extends TestCase
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

    private function makeCommand(): SeedBsiExtraBausteineCommand
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

        return new SeedBsiExtraBausteineCommand($em, $repo);
    }

    #[Test]
    public function testSeedingIdempotent(): void
    {
        $command = $this->makeCommand();
        $tester = new CommandTester($command);

        // First run: 15 created.
        $exitCode = $tester->execute([]);
        self::assertSame(0, $exitCode);
        self::assertCount(15, $this->persisted, 'first invocation creates all 15 templates');
        $createdRows = $this->persisted;

        foreach ($createdRows as $template) {
            $key = $template->getKey();
            self::assertIsString($key);
            $this->existing[$key] = $template;
        }
        $this->persisted = [];

        // Second run without --force: 15 skipped.
        $exitCode = $tester->execute([]);
        self::assertSame(0, $exitCode);
        self::assertCount(0, $this->persisted, 'second run without --force is a no-op');
        self::assertStringContainsString('skipped=15', $tester->getDisplay());

        // Third run with --force: 15 updated in place.
        $tester->execute(['--force' => true]);
        self::assertCount(15, $this->persisted, '--force triggers update on every row');
    }

    #[Test]
    public function testAllFifteenTemplatesCreated(): void
    {
        $command = $this->makeCommand();
        $tester = new CommandTester($command);

        $tester->execute([]);
        self::assertCount(15, $this->persisted);

        $keys = array_map(
            static fn (PolicyTemplate $t): ?string => $t->getKey(),
            $this->persisted,
        );

        $expected = [
            'bsi.bsi_app_3_2_web_server',
            'bsi.bsi_app_3_4_samba',
            'bsi.bsi_app_4_3_relational_database',
            'bsi.bsi_app_5_3_email',
            'bsi.bsi_sys_1_1_general_server',
            'bsi.bsi_sys_1_2_2_windows_server',
            'bsi.bsi_sys_1_3_linux_unix_server',
            'bsi.bsi_sys_2_1_general_client',
            'bsi.bsi_sys_4_5_storage_systems',
            'bsi.bsi_net_1_1_network_architecture',
            'bsi.bsi_net_3_1_router_switches',
            'bsi.bsi_net_3_2_firewall',
            'bsi.bsi_inf_1_general_building',
            'bsi.bsi_inf_2_data_center',
            'bsi.bsi_ind_1_2_ot_network_architecture',
        ];

        sort($keys);
        sort($expected);
        self::assertSame($expected, $keys);

        // Every template carries standard='bsi' and a valid bsi_tier.
        foreach ($this->persisted as $template) {
            self::assertSame('bsi', $template->getStandard(), $template->getKey() . ' standard=bsi');
            self::assertContains(
                $template->getBsiTier(),
                PolicyTemplate::BSI_TIERS,
                $template->getKey() . ' bsi_tier in {basis,standard,kern}',
            );
            self::assertSame(1, $template->getVersion());
            self::assertTrue($template->isActive());
            self::assertFalse($template->isClimateChangeWording());
        }
    }

    #[Test]
    public function testTierAndAnchorsPerSpotCheck(): void
    {
        $command = $this->makeCommand();
        $tester = new CommandTester($command);

        $tester->execute([]);

        $byKey = [];
        foreach ($this->persisted as $template) {
            $byKey[$template->getKey()] = $template;
        }

        // Spot-check: APP.3.2 Webserver → tier basis, A.8.x cluster.
        $web = $byKey['bsi.bsi_app_3_2_web_server'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $web);
        self::assertSame(PolicyTemplate::BSI_TIER_BASIS, $web->getBsiTier());
        self::assertSame('APP.3.2', $web->getNormRef());
        self::assertContains('APP.3.2.A1', $web->getLinkedBsiBausteine() ?? []);
        self::assertContains('APP.3.2.A11', $web->getLinkedBsiBausteine() ?? []);
        self::assertContains('A.8.26', $web->getLinkedAnnexAControls() ?? []);

        // Spot-check: NET.3.2 Firewall → tier standard (heightened), A.8.20-23.
        $fw = $byKey['bsi.bsi_net_3_2_firewall'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $fw);
        self::assertSame(
            PolicyTemplate::BSI_TIER_STANDARD,
            $fw->getBsiTier(),
            'firewall is critical zone-crossing → standard tier',
        );
        self::assertContains('NET.3.2.A11', $fw->getLinkedBsiBausteine() ?? []);
        self::assertContains('A.8.22', $fw->getLinkedAnnexAControls() ?? []);

        // Spot-check: INF.2 Datacenter → tier standard, A.7.x cluster, BCM in chain.
        $dc = $byKey['bsi.bsi_inf_2_data_center'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $dc);
        self::assertSame(PolicyTemplate::BSI_TIER_STANDARD, $dc->getBsiTier());
        self::assertSame('programme', $dc->getDocumentType());
        self::assertContains('A.7.11', $dc->getLinkedAnnexAControls() ?? []);
        self::assertContains('ROLE_BCM', $dc->getApprovalChain() ?? []);

        // Spot-check: IND.1 OT/ICS → tier standard, IEC 62443 referenced via Cross-Ref.
        $ot = $byKey['bsi.bsi_ind_1_2_ot_network_architecture'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $ot);
        self::assertSame(PolicyTemplate::BSI_TIER_STANDARD, $ot->getBsiTier());
        self::assertSame('IND.1', $ot->getNormRef());
        self::assertContains('IND.1.A11', $ot->getLinkedBsiBausteine() ?? []);
        self::assertContains('IND.1.A15', $ot->getLinkedBsiBausteine() ?? []);
        self::assertContains('A.5.7', $ot->getLinkedAnnexAControls() ?? []);
        self::assertContains('A.8.32', $ot->getLinkedAnnexAControls() ?? []);
        self::assertContains('ROLE_OT_LEAD', $ot->getApprovalChain() ?? []);
        self::assertContains('ROLE_BCM', $ot->getApprovalChain() ?? []);

        // Spot-check: SYS.4.5 Removable Media → DPO required (data exfil risk).
        $rm = $byKey['bsi.bsi_sys_4_5_storage_systems'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $rm);
        self::assertTrue(
            $rm->isDpoSectionRequired(),
            'removable media → personal data exfil risk → DPO section required',
        );

        // Spot-check: APP.4.3 RDBMS → DPO required (personal data store).
        $db = $byKey['bsi.bsi_app_4_3_relational_database'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $db);
        self::assertTrue($db->isDpoSectionRequired());

        // Spot-check: APP.5.3 Email → DPO required (content/metadata personal).
        $mail = $byKey['bsi.bsi_app_5_3_email'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $mail);
        self::assertTrue($mail->isDpoSectionRequired());

        // Negative: SYS.1.1 (server) does NOT auto-mandate DPO section.
        $srv = $byKey['bsi.bsi_sys_1_1_general_server'] ?? null;
        self::assertInstanceOf(PolicyTemplate::class, $srv);
        self::assertFalse($srv->isDpoSectionRequired());
    }
}
