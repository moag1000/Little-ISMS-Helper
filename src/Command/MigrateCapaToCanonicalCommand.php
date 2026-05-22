<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ChangeRequest;
use App\Entity\CorrectiveAction;
use App\Entity\Incident;
use App\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Junior-ISB-Audit-2026-05-22 M-07: CAPA-Welt consolidation stub command —
 * does NOT execute the migration yet, only audits scope.
 *
 * Reports per-tenant statistics for the planned CAPA consolidation
 * (CorrectiveAction = canonical, ChangeRequest sibling-FK, Incident-listener).
 *
 * This is a REPORT-ONLY scaffold. The actual migration logic is deferred to
 * sprint S14 — see ADR docs/decisions/2026-05-23-capa-canonical-process.md.
 *
 * Reported scope per tenant:
 *  - Incidents with severity >= high AND non-empty rootCause (candidates for
 *    auto-CorrectiveAction creation via the new listener)
 *  - Existing CorrectiveAction rows (already structured, no migration needed)
 *  - ChangeRequest rows (candidates for relatedCorrectiveAction FK backfill —
 *    flagged for manual review since the link is semantic, not mechanical)
 *
 * @see docs/decisions/2026-05-23-capa-canonical-process.md
 * @see src/EventListener/AutoReactionCorrectiveActionListener.php — reference pattern (AuditFinding path)
 * @see src/Listener/AutoReactionCorrectiveActionListenerForIncident.php — S14 stub for the Incident path
 */
#[AsCommand(
    name: 'app:migrate-capa',
    description: '[STUB / S14] Report-only scope audit for the CAPA-canonical-process consolidation (M-07). Does NOT migrate yet.'
)]
final class MigrateCapaToCanonicalCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'tenant',
                't',
                InputOption::VALUE_REQUIRED,
                'Limit report to a specific tenant ID. Default: report across all tenants.'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Explicit dry-run flag. Currently redundant — this stub NEVER writes. Kept for forward-compat with the S14 implementation.'
            )
            ->setHelp(<<<'HELP'
<info>Usage:</info>
  php bin/console app:migrate-capa                        # Report across all tenants
  php bin/console app:migrate-capa --tenant=5             # Report for tenant ID 5
  php bin/console app:migrate-capa --dry-run              # Explicit dry-run (default behaviour)

<info>What this command does TODAY (stub):</info>
  Prints per-tenant statistics for the planned CAPA consolidation:
   - Incidents with severity >= high AND non-empty rootCause (auto-CA candidates)
   - Existing CorrectiveAction rows (already canonical)
   - ChangeRequest rows (candidates for relatedCorrectiveAction FK backfill)

<info>What this command will do in S14 (NOT YET):</info>
  Per-tenant data migration: materialise Incident.rootCause / Incident.correctiveActions
  freetext into structured CorrectiveAction rows. Requires --commit flag (TBD).

<info>References:</info>
  ADR: docs/decisions/2026-05-23-capa-canonical-process.md
  Junior-ISB-Audit-2026-05-22 finding: M-07
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $tenantId = $input->getOption('tenant') !== null ? (int) $input->getOption('tenant') : null;

        $io->title('M-07 — CAPA-Canonical-Process Consolidation (Scope Audit)');
        $io->warning(
            'This is a STUB command (PR for ADR M-07). It reports scope statistics ONLY. '
            . 'No data is written. The actual migration is deferred to sprint S14.'
        );

        $tenantRepo = $this->entityManager->getRepository(Tenant::class);
        $tenants = $tenantId !== null
            ? array_filter([$tenantRepo->find($tenantId)])
            : $tenantRepo->findAll();

        if (empty($tenants)) {
            $io->error($tenantId !== null
                ? sprintf('Tenant with ID %d not found.', $tenantId)
                : 'No tenants found in the database.'
            );
            return Command::FAILURE;
        }

        $io->section(sprintf('Scanning %d tenant(s)…', count($tenants)));

        $rows = [];
        $totals = ['ca_existing' => 0, 'incident_candidates' => 0, 'cr_total' => 0];

        foreach ($tenants as $tenant) {
            $stats = $this->collectTenantStats($tenant);
            $rows[] = [
                $tenant->getId(),
                $this->shorten((string) $tenant->getName(), 40),
                $stats['ca_existing'],
                $stats['incident_candidates'],
                $stats['cr_total'],
            ];
            $totals['ca_existing'] += $stats['ca_existing'];
            $totals['incident_candidates'] += $stats['incident_candidates'];
            $totals['cr_total'] += $stats['cr_total'];
        }

        $io->table(
            ['Tenant ID', 'Name', 'Existing CAs', 'Incident CA-candidates', 'Total CRs'],
            $rows
        );

        $io->section('Totals across reported tenants');
        $io->listing([
            sprintf('Existing CorrectiveAction rows (already canonical): %d', $totals['ca_existing']),
            sprintf('Incident rows that would auto-create a CA in S14 (severity>=high + rootCause set): %d', $totals['incident_candidates']),
            sprintf('ChangeRequest rows (manual semantic review for relatedCorrectiveAction backfill): %d', $totals['cr_total']),
        ]);

        $io->success('Scope audit complete. No data was written. See ADR docs/decisions/2026-05-23-capa-canonical-process.md for the S14 migration plan.');

        return Command::SUCCESS;
    }

    /**
     * @return array{ca_existing:int, incident_candidates:int, cr_total:int}
     */
    private function collectTenantStats(Tenant $tenant): array
    {
        $em = $this->entityManager;

        $caExisting = (int) $em->createQueryBuilder()
            ->select('COUNT(ca.id)')
            ->from(CorrectiveAction::class, 'ca')
            ->where('ca.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getSingleScalarResult();

        // Incident candidates: severity in {high, critical} AND non-empty rootCause.
        // We do not assume a specific severity-enum class here — the field is queried
        // as a scalar string match for forward-compat with both enum and string columns.
        $incidentCandidates = (int) $em->createQueryBuilder()
            ->select('COUNT(i.id)')
            ->from(Incident::class, 'i')
            ->where('i.tenant = :tenant')
            ->andWhere('i.severity IN (:severities)')
            ->andWhere('i.rootCause IS NOT NULL')
            ->andWhere("i.rootCause != ''")
            ->setParameter('tenant', $tenant)
            ->setParameter('severities', ['high', 'critical'])
            ->getQuery()
            ->getSingleScalarResult();

        $crTotal = (int) $em->createQueryBuilder()
            ->select('COUNT(cr.id)')
            ->from(ChangeRequest::class, 'cr')
            ->where('cr.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'ca_existing' => $caExisting,
            'incident_candidates' => $incidentCandidates,
            'cr_total' => $crTotal,
        ];
    }

    private function shorten(string $s, int $max): string
    {
        if (mb_strlen($s) <= $max) {
            return $s;
        }
        return mb_substr($s, 0, $max - 1) . '…';
    }
}
