<?php

namespace App\Command;

use App\Repository\AuditLogRepository;
use App\Repository\SystemSettingsRepository;
use App\Service\AuditLogger;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Audit Log Cleanup Command
 *
 * Implements DSGVO Art. 5.1(e) and NIS2 Art. 21.2 compliant log retention.
 *
 * DSGVO Art. 5.1(e) - Speicherbegrenzung (Storage Limitation):
 * "Personal data shall be kept in a form which permits identification of data subjects
 * for no longer than is necessary for the purposes for which the personal data are processed."
 *
 * NIS2 Art. 21.2 - Incident Reporting:
 * "Member States shall ensure that entities keep audit logs of activities
 * for at least 12 months."
 *
 * Default Retention Period: 365 days (12 months)
 * Configurable via: app.audit_log_retention_days parameter
 *
 * Usage:
 *   php bin/console app:audit-log:cleanup
 *   php bin/console app:audit-log:cleanup --dry-run
 *   php bin/console app:audit-log:cleanup --retention-days=730 (2 years)
 *
 * Cron Setup (recommended: daily at 2 AM):
 *   0 2 * * * cd /path/to/project && php bin/console app:audit-log:cleanup >> /var/log/audit-cleanup.log 2>&1
 */
#[AsCommand(name: 'app:audit-log:cleanup', description: 'Clean up old audit logs based on retention policy (DSGVO Art. 5.1(e) + NIS2 Art. 21.2)', help: <<<'TXT'
The <info>%command.name%</info> command cleans up old audit logs according to retention policy.

<info>GDPR & NIS2 Compliance:</info>
  • DSGVO Art. 5.1(e): Storage Limitation - personal data must not be kept longer than necessary
  • NIS2 Art. 21.2: Audit logs must be retained for at least 12 months

<info>Default Retention Period:</info> 365 days (12 months)

<info>Examples:</info>

  # Preview what would be deleted (dry run)
  <info>php bin/console %command.name% --dry-run</info>

  # Execute cleanup with default retention (365 days)
  <info>php bin/console %command.name%</info>

  # Execute cleanup with custom retention (2 years)
  <info>php bin/console %command.name% --retention-days=730</info>

<info>Recommended Cron Setup (daily at 2 AM):</info>
  <comment>0 2 * * * cd /path/to/project && php bin/console %command.name% >> /var/log/audit-cleanup.log 2>&1</comment>
TXT)]
class AuditLogCleanupCommand
{
    /** Hardcoded fallback wenn SystemSettings-Lookup fehlschlägt (Robustheit). */
    private const int DEFAULT_RETENTION_DAYS = 730;
    /** NIS2 Art. 21.2 — mind. 12 Monate. */
    private const int MIN_RETENTION_DAYS = 365;

    public function __construct(
        private readonly AuditLogRepository $auditLogRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly SystemSettingsRepository $systemSettingsRepository,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function __invoke(
        #[Option(description: 'Show which logs would be deleted without actually deleting them', name: 'dry-run')]
        bool $dryRun = false,
        #[Option(description: 'Number of days to retain audit logs (overrides SystemSettings)', name: 'retention-days')]
        ?int $retentionDays = null,
        ?SymfonyStyle $symfonyStyle = null
    ): int
    {
        // Resolve retention from SystemSettings (global Setting, kein Tenant-Override)
        // Ceiling: Wenn Setting gelöscht oder korrupt → DEFAULT_RETENTION_DAYS (730, ISO 27001 Clause 9.1).
        $retentionDays ??= $this->resolveRetentionDays();

        // NIS2 Compliance Check: minimum 12 months (365 days)
        if ($retentionDays < self::MIN_RETENTION_DAYS) {
            $symfonyStyle->error([
                'Retention period must be at least 365 days (12 months) for NIS2 Art. 21.2 compliance!',
                sprintf('Requested: %d days', $retentionDays),
                'Minimum required: 365 days'
            ]);
            return Command::FAILURE;
        }
        $cutoffDate = new DateTimeImmutable(sprintf('-%d days', $retentionDays));
        $symfonyStyle->title('Audit Log Cleanup');
        $symfonyStyle->section('Configuration');
        $symfonyStyle->table(
            ['Parameter', 'Value'],
            [
                ['Retention Period', sprintf('%d days (%d months)', $retentionDays, round($retentionDays / 30))],
                ['Cutoff Date', $cutoffDate->format('Y-m-d H:i:s')],
                ['Mode', $dryRun ? '🔍 DRY RUN (No changes will be made)' : '✂️ PRODUCTION (Logs will be deleted)'],
                ['DSGVO Art. 5.1(e)', '✅ Storage Limitation compliant'],
                ['NIS2 Art. 21.2', $retentionDays >= 365 ? '✅ 12-month retention compliant' : '❌ Non-compliant'],
            ]
        );
        // Count logs to be deleted
        $count = $this->auditLogRepository->countOldLogs($cutoffDate);
        if ($count === 0) {
            $symfonyStyle->success('No audit logs found older than ' . $cutoffDate->format('Y-m-d H:i:s'));
            return Command::SUCCESS;
        }
        $symfonyStyle->section('Analysis');
        $symfonyStyle->writeln(sprintf('Found <fg=yellow>%d</> audit log entries older than <fg=cyan>%s</>',
            $count,
            $cutoffDate->format('Y-m-d H:i:s')
        ));
        // Get sample of logs to be deleted
        $sampleLogs = $this->auditLogRepository->findOldLogs($cutoffDate, 5);
        if ($sampleLogs !== []) {
            $symfonyStyle->section('Sample of Logs to be Deleted (first 5)');
            $sampleData = [];
            foreach ($sampleLogs as $sampleLog) {
                $sampleData[] = [
                    $sampleLog->getId(),
                    $sampleLog->getEntityType(),
                    $sampleLog->getAction(),
                    $sampleLog->getUserName(),
                    $sampleLog->getCreatedAt()->format('Y-m-d H:i:s'),
                ];
            }
            $symfonyStyle->table(['ID', 'Entity Type', 'Action', 'User', 'Created At'], $sampleData);
        }
        if ($dryRun) {
            $symfonyStyle->note([
                'DRY RUN MODE: No changes have been made.',
                sprintf('%d log entries would be deleted.', $count),
                'Remove --dry-run to execute deletion.'
            ]);
            return Command::SUCCESS;
        }
        // Confirm deletion
        if (!$symfonyStyle->confirm(sprintf('Delete %d audit log entries?', $count), false)) {
            $symfonyStyle->warning('Operation cancelled by user.');
            return Command::SUCCESS;
        }
        $symfonyStyle->section('Deletion in Progress');
        $startTime = microtime(true);
        $deletedCount = $this->auditLogRepository->deleteOldLogs($cutoffDate);
        $this->entityManager->flush();
        $duration = round(microtime(true) - $startTime, 2);

        // Meta-Evidence: den Lösch-Vorgang selbst in den Audit-Log schreiben.
        $this->auditLogger->logCustom(
            action: 'audit_log_purge',
            entityType: 'AuditLog',
            entityId: null,
            newValues: [
                'deleted_count' => $deletedCount,
                'cutoff_date' => $cutoffDate->format('Y-m-d H:i:s'),
                'retention_days' => $retentionDays,
                'duration_seconds' => $duration,
            ],
            description: sprintf('Purged %d audit log entries older than %s (retention: %d days)', $deletedCount, $cutoffDate->format('Y-m-d'), $retentionDays),
            userName: 'system:audit-log:cleanup',
        );

        $symfonyStyle->section('Results');
        $symfonyStyle->success([
            sprintf('Successfully deleted %d audit log entries', $deletedCount),
            sprintf('Execution time: %s seconds', $duration),
            sprintf('Retention policy: %d days (%d months)', $retentionDays, round($retentionDays / 30)),
            'DSGVO Art. 5.1(e): ✅ Compliant',
            'NIS2 Art. 21.2: ✅ Compliant',
            'Purge-Vorgang selbst als AuditLog-Eintrag erzeugt (Meta-Evidence).',
        ]);
        return Command::SUCCESS;
    }

    /**
     * Read retention from SystemSettings (category=audit, key=retention_days).
     * Falls Setting fehlt oder korrupt → hardcoded Default (ISO 27001 Clause 9.1).
     */
    private function resolveRetentionDays(): int
    {
        $raw = $this->systemSettingsRepository->getSetting('audit', 'retention_days', self::DEFAULT_RETENTION_DAYS);
        if (!is_int($raw) && !(is_string($raw) && ctype_digit($raw))) {
            return self::DEFAULT_RETENTION_DAYS;
        }
        return (int) $raw;
    }
}
