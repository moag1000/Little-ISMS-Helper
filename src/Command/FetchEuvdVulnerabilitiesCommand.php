<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Vulnerability\EuvdSyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * F39 — ENISA EU Vulnerability Database (EUVD) feed sync.
 *
 * Enrichment-only by design: the EUVD is a GLOBAL feed and vulnerabilities are
 * tenant-scoped, so this command does NOT invent tenant-scoped rows from the
 * feed. Instead it matches feed records to EXISTING vulnerabilities by CVE id
 * (across all tenants) and flags them `in_euvd = true` + records the `euvd_id`.
 * This answers the auditor question "is this CVE in the EU database?" without
 * any cross-tenant data leakage.
 *
 * Intended to run on a cron (e.g. daily). Safe to re-run — idempotent upsert by
 * CVE id. A transient EUVD outage yields an empty fetch and exit 0 (logged).
 */
#[AsCommand(
    name: 'app:fetch-euvd-vulnerabilities',
    description: 'Sync the ENISA EUVD feed: flag existing vulnerabilities present in the EU Vulnerability Database (F39)',
)]
final class FetchEuvdVulnerabilitiesCommand
{
    public function __construct(
        private readonly EuvdSyncService $syncService,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Maximum number of EUVD records to fetch')]
        int $limit = 100,
        #[Option(description: 'Report what would change without writing')]
        bool $dryRun = false,
    ): int {
        $io->title('ENISA EUVD Feed Sync (F39)');

        $result = $this->syncService->sync($limit, $dryRun);

        if ($result['fetched'] === 0) {
            $io->warning('No EUVD records fetched (empty feed or upstream unavailable). Nothing to do.');
            return Command::SUCCESS;
        }

        $io->text(sprintf('Fetched %d EUVD record(s).', $result['fetched']));
        $io->success(sprintf(
            '%s %d vulnerability row(s) across %d matched CVE id(s).',
            $dryRun ? '[dry-run] would flag' : 'Flagged',
            $result['flagged'],
            $result['matched_cves'],
        ));

        return Command::SUCCESS;
    }
}
