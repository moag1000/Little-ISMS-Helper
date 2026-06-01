<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Roll a tenant's TISAX state back to a pre-consolidation snapshot
 * (var/backups/tisax_consolidate_snapshot_*.json written by app:tisax:consolidate).
 *
 * This is the documented rollback path the spec (§9.2 G7) requires: the
 * consolidation is forward-snapshot-based, and this command replays that snapshot.
 * It UPSERTS every snapshotted requirement by id (restoring framework_id,
 * requirement_source, the full maturity/DP assessment + dataSourceMapping) and
 * re-activates the legacy framework. Rows deleted as seed-junk are re-inserted
 * with sensible defaults for the non-snapshotted NOT-NULL columns.
 *
 * Dry-run by default; --force applies.
 */
#[AsCommand(
    name: 'app:tisax:restore-snapshot',
    description: 'Restore TISAX requirements + framework state from a consolidation snapshot (rollback)',
)]
final class TisaxRestoreSnapshotCommand extends Command
{
    public function __construct(private readonly Connection $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('snapshot', InputArgument::REQUIRED, 'Path to a tisax_consolidate_snapshot_*.json');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Apply (default is dry-run).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = (string) $input->getArgument('snapshot');
        if (!is_file($path)) {
            $io->error('Snapshot not found: ' . $path);
            return Command::FAILURE;
        }
        $snap = json_decode((string) file_get_contents($path), true);
        $rows = $snap['rows'] ?? [];
        if ($rows === []) {
            $io->error('Snapshot has no rows.');
            return Command::FAILURE;
        }
        $force = (bool) $input->getOption('force');

        $existing = $this->db->fetchFirstColumn('SELECT id FROM compliance_requirement');
        $existing = array_flip(array_map('intval', $existing));
        $updates = 0;
        $inserts = 0;

        if (!$force) {
            foreach ($rows as $r) {
                isset($existing[(int) $r['id']]) ? $updates++ : $inserts++;
            }
            $io->note(sprintf('DRY-RUN: would update %d, re-insert %d rows; reactivate framework %s.',
                $updates, $inserts, (string) ($snap['legacy_framework_id'] ?? '?')));
            return Command::SUCCESS;
        }

        $this->db->beginTransaction();
        try {
            foreach ($rows as $r) {
                $id = (int) $r['id'];
                $params = [
                    'framework_id' => $r['framework_id'],
                    'requirement_id' => $r['requirement_id'],
                    'title' => $r['title'],
                    'category' => $r['category'] ?? null,
                    'requirement_source' => $r['requirement_source'] ?? 'system',
                    'upload_tenant_id' => $r['upload_tenant_id'] ?? null,
                    'maturity_current' => $r['maturity_current'] ?? null,
                    'maturity_target' => $r['maturity_target'] ?? null,
                    'maturity_reviewed_at' => $this->toMysqlDate($r['maturity_reviewed_at'] ?? null),
                    'assessment_state_dp' => $r['assessment_state_dp'] ?? null,
                    'data_source_mapping' => $r['data_source_mapping'] ?? null,
                ];
                if (is_array($params['data_source_mapping'])) {
                    $params['data_source_mapping'] = json_encode($params['data_source_mapping']);
                }

                if (isset($existing[$id])) {
                    $set = implode(', ', array_map(static fn ($c) => "$c = :$c", array_keys($params)));
                    $this->db->executeStatement(
                        "UPDATE compliance_requirement SET $set WHERE id = :id",
                        $params + ['id' => $id],
                    );
                    $updates++;
                } else {
                    $params['id'] = $id;
                    $params['description'] = $r['title']; // NOT-NULL fallback
                    $params['priority'] = 'low';
                    $params['requirement_type'] = 'core';
                    $params['created_at'] = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
                    $cols = implode(', ', array_keys($params));
                    $vals = implode(', ', array_map(static fn ($c) => ":$c", array_keys($params)));
                    $this->db->executeStatement(
                        "INSERT INTO compliance_requirement ($cols) VALUES ($vals)",
                        $params,
                    );
                    $inserts++;
                }
            }

            // Reactivate the legacy framework (undo retire).
            if (($lid = $snap['legacy_framework_id'] ?? null) !== null) {
                $this->db->executeStatement(
                    'UPDATE compliance_framework SET active = 1, successor_id = NULL WHERE id = :id',
                    ['id' => (int) $lid],
                );
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $io->error('Restore failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf('Restored: %d updated, %d re-inserted; framework reactivated.', $updates, $inserts));
        return Command::SUCCESS;
    }

    /** Normalise any date-ish snapshot value to MySQL 'Y-m-d H:i:s', or null. */
    private function toMysqlDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $ts = strtotime((string) $value);
        return $ts === false ? null : date('Y-m-d H:i:s', $ts);
    }
}
