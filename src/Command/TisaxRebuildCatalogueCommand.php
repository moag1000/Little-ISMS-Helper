<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\ComplianceFrameworkRepository;
use App\Service\Tisax\TisaxCatalogueProvider;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reconciles the TISAX (framework 114) data to the single canonical VDA-ISA 6.0
 * catalogue. Idempotent. DRY-RUN by default — pass --force to write.
 *
 * Two phases, because the legacy shared pollution is entangled:
 *
 *  PHASE A (safe, runs with --force):
 *    1. Snapshot framework 114 + 132 requirements (+ fulfillment ids) to
 *       var/backups/.
 *    2. Re-seed / refresh the canonical 80-control shared baseline via
 *       TisaxCatalogueProvider (numbers only) and fix framework metadata from
 *       the YAML (kills the 6.0.4 / name drift).
 *    3. Flatten framework-114 requirements (parent_requirement_id = NULL) so no
 *       row depends on a section parent.
 *    4. Delete the retired legacy framework 132 when it carries no fulfilments
 *       and no mapping references (verified first).
 *
 *  PHASE B (legacy purge — only with --purge-legacy, REPORT-ONLY otherwise):
 *    The old shared catalogue rows (upload_tenant_id IS NULL, control ids NOT in
 *    the canonical 80 — e.g. ACC-/INF- legacy + section stubs + extra ids) are
 *    referenced by ~1500 ComplianceMapping rows (the OLD reuse graph, keyed to
 *    legacy ids) and carry the tenant's superseded pre-BYO fulfilments. Deleting
 *    them cascade-drops that graph. So this phase NEVER runs implicitly: it
 *    reports the exact blast radius and requires the canonical mapping graph to
 *    be re-derived (app:tisax:reconcile) before an explicit --purge-legacy run.
 */
#[AsCommand(
    name: 'app:tisax:rebuild-catalogue',
    description: 'Reconcile TISAX (114) to the single canonical 80-control catalogue (dry-run unless --force)'
)]
final class TisaxRebuildCatalogueCommand extends Command
{
    private const FW = 114;
    private const FW_LEGACY = 132;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $conn,
        private readonly TisaxCatalogueProvider $catalogue,
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Execute Phase A writes (default: dry-run report only)')
            ->addOption('purge-legacy', null, InputOption::VALUE_NONE, 'Also execute Phase B (delete legacy shared pollution + cascade old mapping graph). Requires --force.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');
        $purge = (bool) $input->getOption('purge-legacy');
        $io->title('TISAX catalogue reconciliation' . ($force ? '' : ' (DRY-RUN)'));

        $official = array_flip($this->officialControlIds());

        // --- Diagnostics (read-only) ---
        $sharedPollution = (int) $this->conn->fetchOne(
            "SELECT COUNT(*) FROM compliance_requirement WHERE framework_id = ? AND upload_tenant_id IS NULL",
            [self::FW]
        );
        $nestedTenant = (int) $this->conn->fetchOne(
            "SELECT COUNT(*) FROM compliance_requirement c JOIN compliance_requirement p ON c.parent_requirement_id = p.id
             WHERE c.framework_id = ? AND c.upload_tenant_id IS NOT NULL",
            [self::FW]
        );
        $legacyReqs = (int) $this->conn->fetchOne("SELECT COUNT(*) FROM compliance_requirement WHERE framework_id = ?", [self::FW_LEGACY]);
        $legacyFul = (int) $this->conn->fetchOne(
            "SELECT COUNT(*) FROM compliance_requirement_fulfillment f JOIN compliance_requirement r ON f.requirement_id = r.id WHERE r.framework_id = ?",
            [self::FW_LEGACY]
        );
        $legacyMaps = (int) $this->conn->fetchOne(
            "SELECT COUNT(*) FROM compliance_mapping m WHERE m.source_requirement_id IN (SELECT id FROM compliance_requirement WHERE framework_id = ?)
             OR m.target_requirement_id IN (SELECT id FROM compliance_requirement WHERE framework_id = ?)",
            [self::FW_LEGACY, self::FW_LEGACY]
        );

        $io->section('Current state');
        $io->listing([
            sprintf('Shared rows on FW %d: %d (pollution — none are canonical)', self::FW, $sharedPollution),
            sprintf('Tenant rows nested under section parents: %d (to flatten)', $nestedTenant),
            sprintf('Legacy FW %d: %d requirements, %d fulfilments, %d mapping refs', self::FW_LEGACY, $legacyReqs, $legacyFul, $legacyMaps),
        ]);

        if (!$force) {
            $io->note('DRY-RUN. Re-run with --force to execute Phase A (snapshot + reseed 80 + flatten + drop empty FW132).');
            $this->reportPhaseB($io, $official);
            return Command::SUCCESS;
        }

        $this->conn->beginTransaction();
        try {
            // 1. Snapshot.
            $snapshotPath = $this->writeSnapshot();
            $io->text('Snapshot: ' . $snapshotPath);

            // 2. Canonical 80 shared baseline + metadata from YAML.
            $r = $this->catalogue->loadCatalogue(true);
            $io->text(sprintf('Canonical baseline: %d created, %d updated (framework metadata refreshed from YAML).', $r['created'], $r['updated']));

            // 3. Flatten FW114.
            $flattened = $this->conn->executeStatement(
                "UPDATE compliance_requirement SET parent_requirement_id = NULL WHERE framework_id = ? AND parent_requirement_id IS NOT NULL",
                [self::FW]
            );
            $io->text(sprintf('Flattened %d rows (parent_requirement_id → NULL).', $flattened));

            // 4. Drop legacy FW132 only when provably safe.
            if ($legacyReqs > 0 && $legacyFul === 0 && $legacyMaps === 0) {
                $this->conn->executeStatement("DELETE FROM compliance_requirement WHERE framework_id = ?", [self::FW_LEGACY]);
                $this->conn->executeStatement("DELETE FROM compliance_framework WHERE id = ?", [self::FW_LEGACY]);
                $io->text(sprintf('Deleted legacy framework %d (%d rows, 0 fulfilments, 0 mappings).', self::FW_LEGACY, $legacyReqs));
            } elseif ($legacyReqs > 0) {
                $io->warning(sprintf('Legacy FW %d NOT deleted — it still has %d fulfilments / %d mapping refs.', self::FW_LEGACY, $legacyFul, $legacyMaps));
            }

            if ($purge) {
                $io->warning('Phase B (--purge-legacy) is intentionally NOT auto-executed: it cascade-drops the old mapping graph. Re-derive canonical mappings (app:tisax:reconcile) first, then run a dedicated purge. Reporting only.');
            }

            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollBack();
            $io->error('Rolled back: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $this->reportPhaseB($io, $official);
        $io->success('Phase A complete.');
        return Command::SUCCESS;
    }

    /** @param array<string,int> $official */
    private function reportPhaseB(SymfonyStyle $io, array $official): void
    {
        $rows = $this->conn->fetchAllAssociative(
            "SELECT id FROM compliance_requirement WHERE framework_id = ? AND upload_tenant_id IS NULL",
            [self::FW]
        );
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        if ($ids === []) {
            $io->text('Phase B: no legacy shared rows remain.');
            return;
        }
        $in = implode(',', $ids);
        $maps = (int) $this->conn->fetchOne(
            "SELECT COUNT(*) FROM compliance_mapping WHERE source_requirement_id IN ($in) OR target_requirement_id IN ($in)"
        );
        $ful = (int) $this->conn->fetchOne(
            "SELECT COUNT(*) FROM compliance_requirement_fulfillment WHERE requirement_id IN ($in)"
        );
        $io->section('Phase B blast radius (NOT executed)');
        $io->listing([
            sprintf('%d legacy shared rows (none canonical)', count($ids)),
            sprintf('%d ComplianceMapping rows reference them (the old legacy-id reuse graph)', $maps),
            sprintf('%d superseded pre-BYO fulfilments on them', $ful),
            'Prerequisite: re-derive the canonical (number-keyed) mapping graph, then purge in a dedicated, snapshotted run.',
        ]);
    }

    private function writeSnapshot(): string
    {
        $dir = $this->projectDir . '/var/backups';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $reqs = $this->conn->fetchAllAssociative(
            "SELECT id, framework_id, requirement_id, category, upload_tenant_id, parent_requirement_id, maturity_current
             FROM compliance_requirement WHERE framework_id IN (?, ?)",
            [self::FW, self::FW_LEGACY]
        );
        $ful = $this->conn->fetchAllAssociative(
            "SELECT f.id, f.requirement_id, f.tenant_id, f.status, f.fulfillment_percentage
             FROM compliance_requirement_fulfillment f JOIN compliance_requirement r ON f.requirement_id = r.id
             WHERE r.framework_id IN (?, ?)",
            [self::FW, self::FW_LEGACY]
        );
        $ts = count($reqs) . '-' . substr(sha1(implode(',', array_column($reqs, 'id'))), 0, 10);
        $path = $dir . '/tisax_rebuild_snapshot_' . $ts . '.json';
        file_put_contents($path, json_encode(['requirements' => $reqs, 'fulfillments' => $ful], JSON_PRETTY_PRINT));
        return $path;
    }

    /** @return list<string> the 80 official VDA-ISA 6.0 control numbers (from the YAML catalogue). */
    private function officialControlIds(): array
    {
        $ids = [];
        foreach (($this->catalogue->getMetadata() !== [] ? $this->yamlRequirements() : []) as $r) {
            if (isset($r['controlId'])) {
                $ids[] = (string) $r['controlId'];
            }
        }
        return $ids;
    }

    /** @return list<array<string,mixed>> */
    private function yamlRequirements(): array
    {
        $path = $this->projectDir . '/fixtures/library/frameworks/vda-isa-tisax-v6.yaml';
        if (!is_file($path)) {
            return [];
        }
        $data = \Symfony\Component\Yaml\Yaml::parseFile($path);
        return $data['requirements'] ?? [];
    }
}
