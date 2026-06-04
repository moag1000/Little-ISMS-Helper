<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ChangeRequest;
use App\Entity\CorrectiveAction;
use App\Entity\Incident;
use App\Entity\Tenant;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Junior-ISB-Audit-2026-05-22 M-07 Phase-1: CAPA-Welt consolidation backfill.
 *
 * Per ADR `docs/decisions/2026-05-23-capa-canonical-process.md`:
 *
 *   Backfills `CorrectiveAction.source_type` for every existing row based on
 *   the populated FK columns:
 *     - `finding` set       → source_type = 'audit_finding'
 *     - `sourceIncident` set → source_type = 'incident'
 *     - `sourceChangeRequest` set → source_type = 'change_request'
 *     - else                 → source_type = 'manual'
 *
 *   This is idempotent: rows already carrying the inferred value are skipped.
 *   Default behaviour is dry-run; only `--apply` actually writes.
 *
 *   Also reports per-tenant statistics about:
 *     - Existing CorrectiveAction rows (counted by inferred source_type)
 *     - Incident candidates (severity ∈ {high, critical} ∧ rootCause non-empty)
 *       that the AutoReactionCorrectiveActionListenerForIncident would
 *       materialise into structured CAs on the next persist/update.
 *     - ChangeRequest rows (informational — manual semantic linking only).
 *
 * @see docs/decisions/2026-05-23-capa-canonical-process.md
 * @see \App\EventListener\AutoReactionCorrectiveActionListener        — AuditFinding-path listener (reference pattern)
 * @see \App\Listener\AutoReactionCorrectiveActionListenerForIncident — Incident-path listener (M-07 Phase-1 wiring)
 */
#[AsCommand(
    name: 'app:migrate-capa',
    description: 'M-07 Phase-1: Backfill CorrectiveAction.source_type from existing FKs (dry-run by default; --apply to write).'
)]
final class MigrateCapaToCanonicalCommand extends Command
{
    private const BATCH_SIZE = 100;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption(
                'tenant',
                't',
                InputOption::VALUE_REQUIRED,
                'Limit scope to a specific tenant ID. Default: all tenants.'
            )
            ->addOption(
                'apply',
                null,
                InputOption::VALUE_NONE,
                'Actually write the inferred source_type values. Without this flag, the command only previews.'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Explicit dry-run flag. Default behaviour even without this flag is preview-only.'
            )
            ->setHelp(<<<'HELP'
<info>Usage:</info>
  php bin/console app:migrate-capa                        # Dry-run preview across all tenants (default).
  php bin/console app:migrate-capa --tenant=5             # Dry-run for tenant ID 5 only.
  php bin/console app:migrate-capa --apply                # Actually write inferred source_type values.
  php bin/console app:migrate-capa --apply --tenant=5     # Apply for one tenant.

<info>What this command does:</info>
  Scans every CorrectiveAction row (optionally scoped to one tenant) and infers
  source_type from the populated FK columns:
    - finding              → source_type = 'audit_finding'
    - sourceIncident       → source_type = 'incident'
    - sourceChangeRequest  → source_type = 'change_request'
    - none of the above    → source_type = 'manual'

  Rows whose stored source_type already matches the inferred value are skipped.
  Audit-log entry is written per tenant batch via AuditLogger::logBulk (one
  envelope + N per-row entries) — required by ISO 27001 Cl. 7.5.3.

<info>References:</info>
  ADR: docs/decisions/2026-05-23-capa-canonical-process.md
  Audit finding: Junior-ISB-Audit-2026-05-22 M-07
HELP
            );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $tenantId = $input->getOption('tenant') !== null ? (int) $input->getOption('tenant') : null;
        $apply = (bool) $input->getOption('apply');

        $io->title('M-07 Phase-1 — CAPA-Canonical-Process Backfill');
        if (!$apply) {
            $io->warning('DRY-RUN mode (no writes). Use --apply to actually update rows.');
        } else {
            $io->note('APPLY mode — rows will be updated and audit-logged.');
        }

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
        $totals = [
            'scanned' => 0,
            'updated' => 0,
            'audit_finding' => 0,
            'incident' => 0,
            'change_request' => 0,
            'manual' => 0,
            'incident_candidates' => 0,
            'cr_total' => 0,
        ];

        foreach ($tenants as $tenant) {
            $stats = $this->processTenant($tenant, $apply, $io);

            $rows[] = [
                $tenant->getId(),
                $this->shorten((string) $tenant->getName(), 30),
                $stats['scanned'],
                $stats['updated'],
                $stats['by_source_type']['audit_finding'] ?? 0,
                $stats['by_source_type']['incident'] ?? 0,
                $stats['by_source_type']['change_request'] ?? 0,
                $stats['by_source_type']['manual'] ?? 0,
                $stats['incident_candidates'],
                $stats['cr_total'],
            ];

            $totals['scanned'] += $stats['scanned'];
            $totals['updated'] += $stats['updated'];
            foreach ($stats['by_source_type'] as $type => $count) {
                $totals[$type] = ($totals[$type] ?? 0) + $count;
            }
            $totals['incident_candidates'] += $stats['incident_candidates'];
            $totals['cr_total'] += $stats['cr_total'];
        }

        $io->table(
            ['Tenant ID', 'Name', 'Scanned', 'Updated', 'finding', 'incident', 'CR', 'manual', 'Incident cands', 'Total CRs'],
            $rows
        );

        $io->section('Totals');
        $io->listing([
            sprintf('CorrectiveAction rows scanned: %d', $totals['scanned']),
            sprintf('Rows updated (%s): %d', $apply ? 'committed' : 'would-be', $totals['updated']),
            sprintf('  by source_type — audit_finding: %d, incident: %d, change_request: %d, manual: %d',
                $totals['audit_finding'], $totals['incident'], $totals['change_request'], $totals['manual']),
            sprintf('Incident candidates for auto-CA listener (severity>=high + rootCause): %d',
                $totals['incident_candidates']),
            sprintf('Total ChangeRequest rows (informational only): %d', $totals['cr_total']),
        ]);

        if ($apply) {
            $io->success(sprintf('Backfill complete. %d row(s) updated across %d tenant(s).',
                $totals['updated'], count($tenants)));
        } else {
            $io->success(sprintf('Dry-run complete. %d row(s) would be updated. Re-run with --apply to write.',
                $totals['updated']));
        }

        return Command::SUCCESS;
    }

    /**
     * @return array{
     *   scanned:int,
     *   updated:int,
     *   by_source_type: array<string, int>,
     *   incident_candidates:int,
     *   cr_total:int
     * }
     */
    private function processTenant(Tenant $tenant, bool $apply, SymfonyStyle $io): array
    {
        $em = $this->entityManager;
        $caRepo = $em->getRepository(CorrectiveAction::class);

        /** @var list<CorrectiveAction> $actions */
        $actions = $caRepo->findBy(['tenant' => $tenant]);

        $stats = [
            'scanned' => count($actions),
            'updated' => 0,
            'by_source_type' => [
                'audit_finding' => 0,
                'incident' => 0,
                'change_request' => 0,
                'manual' => 0,
            ],
            'incident_candidates' => $this->countIncidentCandidates($tenant),
            'cr_total' => $this->countChangeRequests($tenant),
        ];

        if (empty($actions)) {
            return $stats;
        }

        $progress = null;
        if ($io->isVeryVerbose() || count($actions) >= 50) {
            $progress = new ProgressBar($io, count($actions));
            $progress->setFormat(sprintf(' [tenant=%d] %%current%%/%%max%% [%%bar%%] %%percent:3s%%%%', $tenant->getId() ?? 0));
            $progress->start();
        }

        $batchAuditRows = [];
        $batchUpdatedCount = 0;

        foreach ($actions as $ca) {
            $inferred = $this->inferSourceType($ca);
            $stats['by_source_type'][$inferred] = ($stats['by_source_type'][$inferred] ?? 0) + 1;

            $current = $ca->getSourceType();
            if ($current !== $inferred) {
                $stats['updated']++;

                if ($apply) {
                    $ca->setSourceType($inferred);
                    $batchAuditRows[] = [
                        'entity_id' => $ca->getId(),
                        'action' => 'update',
                        'old_values' => ['source_type' => $current],
                        'new_values' => ['source_type' => $inferred],
                    ];
                    $batchUpdatedCount++;

                    if ($batchUpdatedCount % self::BATCH_SIZE === 0) {
                        $em->flush();
                        $em->clear();
                    }
                }
            }

            $progress?->advance();
        }

        if ($apply) {
            $em->flush();

            if (!empty($batchAuditRows)) {
                $this->auditLogger->logBulk(
                    eventType: 'capa.source_type_backfill',
                    entityType: 'CorrectiveAction',
                    batchData: [
                        'tenant_id' => $tenant->getId(),
                        'tenant_name' => $tenant->getName(),
                        'sprint' => 'M-07 Phase-1',
                        'adr_ref' => 'docs/decisions/2026-05-23-capa-canonical-process.md',
                    ],
                    perEntityData: $batchAuditRows,
                    description: sprintf(
                        'M-07 Phase-1 source_type backfill — tenant %s (%d rows updated)',
                        (string) $tenant->getName(),
                        $batchUpdatedCount
                    )
                );
            }
        }

        $progress?->finish();
        if ($progress !== null) {
            $io->newLine();
        }

        return $stats;
    }

    /**
     * Map a CorrectiveAction's populated FKs to its canonical source_type
     * (per ADR 2026-05-23). Priority order:
     *   1. finding → audit_finding (most historical rows)
     *   2. sourceIncident → incident
     *   3. sourceChangeRequest → change_request
     *   4. none → manual
     */
    private function inferSourceType(CorrectiveAction $ca): string
    {
        if ($ca->getFinding() !== null) {
            return CorrectiveAction::SOURCE_TYPE_AUDIT_FINDING;
        }
        if ($ca->getSourceIncident() !== null) {
            return CorrectiveAction::SOURCE_TYPE_INCIDENT;
        }
        if ($ca->getSourceChangeRequest() !== null) {
            return CorrectiveAction::SOURCE_TYPE_CHANGE_REQUEST;
        }
        return CorrectiveAction::SOURCE_TYPE_MANUAL;
    }

    private function countIncidentCandidates(Tenant $tenant): int
    {
        // We do not assume a specific severity-enum class here — the field is queried
        // as a scalar string match for forward-compat with both enum and string columns.
        return (int) $this->entityManager->createQueryBuilder()
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
    }

    private function countChangeRequests(Tenant $tenant): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(cr.id)')
            ->from(ChangeRequest::class, 'cr')
            ->where('cr.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function shorten(string $s, int $max): string
    {
        if (mb_strlen($s) <= $max) {
            return $s;
        }
        return mb_substr($s, 0, $max - 1) . '…';
    }
}
