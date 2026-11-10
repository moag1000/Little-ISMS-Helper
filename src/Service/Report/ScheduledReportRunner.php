<?php

declare(strict_types=1);

namespace App\Service\Report;

use App\Doctrine\EntityManagerRecovery;
use App\Entity\ScheduledReport;
use App\Entity\Tenant;
use App\Entity\User;
use App\Exception\BusinessRule\BusinessRuleException;
use App\Repository\ScheduledReportRepository;
use App\Service\Mail\MailerTlsChecker;
use App\Service\Mail\RecipientFilter;
use App\Service\ModuleConfigurationService;
use App\Service\ScheduledReportService;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs scheduled reports (S32). Every run first asks whether the report may still go out — its module active for the
 * organisation, the organisation active, the creator still an active manager of it — and skips it with a note
 * otherwise. Each report runs on its own: any \Throwable of one organisation's report is recorded on that report
 * (retried with back-off, no exception text shown to managers) and the batch carries on with the next one.
 */
final class ScheduledReportRunner
{
    public const OUTCOME_SENT = 'success';
    public const OUTCOME_SKIPPED = 'skipped';
    public const OUTCOME_FAILED = 'failed';

    /** lastRunMessage keys (domain scheduled_reports); the templates translate them. */
    public const MSG_SENT = 'scheduled_report.last_run.msg_sent';
    public const MSG_FAILED = 'scheduled_report.last_run.msg_failed';
    public const MSG_NO_RECIPIENTS = 'scheduled_report.last_run.msg_no_recipients';
    public const MSG_TLS = 'scheduled_report.last_run.msg_tls';
    public const SKIP_MODULE = 'scheduled_report.last_run.msg_module_inactive';
    public const SKIP_TENANT = 'scheduled_report.last_run.msg_tenant_inactive';
    public const SKIP_CREATOR = 'scheduled_report.last_run.msg_creator_not_entitled';

    public function __construct(
        private readonly ScheduledReportRepository $repository,
        private readonly ScheduledReportService $reportService,
        private readonly EntityManagerInterface $entityManager,
        private readonly EntityManagerRecovery $recovery,
        private readonly TenantContext $tenantContext,
        private readonly ModuleConfigurationService $moduleConfiguration,
        private readonly RecipientFilter $recipientFilter,
        private readonly MailerTlsChecker $tlsChecker,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Why the report must not run now, as a lastRunMessage key; null when it may.
     */
    public function skipReason(ScheduledReport $report): ?string
    {
        $tenantId = $report->getTenantId();
        $tenant = $tenantId !== null ? $this->entityManager->find(Tenant::class, $tenantId) : null;
        if (!$tenant instanceof Tenant || !$tenant->isActive()) {
            return self::SKIP_TENANT;
        }
        $module = ScheduledReport::TYPE_MODULES[$report->getReportType() ?? ''] ?? null;
        if ($module !== null && !$this->moduleConfiguration->isModuleActiveForTenant($module, $tenant)) {
            return self::SKIP_MODULE;
        }
        $creator = $report->getCreatedBy();
        if ($creator !== null && !$this->creatorStillEntitled($creator, $tenant)) {
            return self::SKIP_CREATOR;
        }

        return null;
    }

    /**
     * The creator is still an active manager of the report's organisation, of an organisation above it (holding),
     * or a SUPER_ADMIN (who creates reports for any organisation they switch to).
     */
    private function creatorStillEntitled(User $creator, Tenant $tenant): bool
    {
        $home = $creator->getTenant();
        $reason = $this->recipientFilter->disqualification($creator, $home?->getId());
        if ($reason !== null) {
            return false;
        }
        if (in_array('ROLE_SUPER_ADMIN', $creator->getRoles(), true)) {
            return true;
        }
        for ($t = $tenant, $depth = 0; $t !== null && $depth < 20; $t = $t->getParent(), $depth++) {
            if ($t->getId() === $home?->getId()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Process every due report. One report's failure never stops another organisation's.
     *
     * @return array{processed: int, success: int, failed: int, skipped: int, details: list<array<string, mixed>>}
     */
    public function processDueReports(): array
    {
        $results = ['processed' => 0, 'success' => 0, 'failed' => 0, 'skipped' => 0, 'details' => []];
        $ids = array_map(static fn (ScheduledReport $r): ?int => $r->getId(), $this->repository->findDueReports());

        try {
            $this->tlsChecker->assertTlsConfigured();
        } catch (\RuntimeException $tlsException) {
            $this->logger->error('Scheduled report batch aborted: mailer DSN fails TLS policy', [
                'error' => $tlsException->getMessage(),
                'affected_reports' => count($ids),
            ]);
            foreach ($ids as $id) {
                $report = $this->entityManager->find(ScheduledReport::class, $id);
                if ($report instanceof ScheduledReport) {
                    $report->recordFailure(self::MSG_TLS);
                    $results = $this->tally($results, $report, self::OUTCOME_FAILED, self::MSG_TLS);
                }
            }
            $this->flushQuietly();

            return $results;
        }

        foreach ($ids as $id) {
            $report = $this->entityManager->find(ScheduledReport::class, $id);
            if (!$report instanceof ScheduledReport) {
                continue;
            }
            [$outcome, $message] = $this->run($report);
            $results = $this->tally($results, $report, $outcome, $message);
        }

        return $results;
    }

    /**
     * Run one report now (cron, "trigger" button, CLI). Never throws.
     *
     * @return array{0: string, 1: string} outcome and lastRunMessage key
     */
    public function run(ScheduledReport $report): array
    {
        $id = $report->getId();
        $reason = $this->skipReason($report);
        if ($reason !== null) {
            $report->recordRun(ScheduledReport::RUN_SKIPPED, $reason);
            $this->flushQuietly();
            $this->logger->notice('Scheduled report skipped', ['report_id' => $id, 'reason' => $reason]);

            return [self::OUTCOME_SKIPPED, $reason];
        }

        try {
            $this->tenantContext->runAs($report->getTenantId(), fn () => $this->reportService->processReport($report));

            return [self::OUTCOME_SENT, self::MSG_SENT];
        } catch (\Throwable $e) {
            $this->logger->error('Failed to process scheduled report', [
                'report_id' => $id,
                'tenant_id' => $report->getTenantId(),
                'exception' => $e,
            ]);
            $message = $e instanceof BusinessRuleException && $e->getRuleCode() === 'no_recipients'
                ? self::MSG_NO_RECIPIENTS
                : self::MSG_FAILED;
            // A DB error closed the manager: open a fresh one, else every later report of the batch fails too.
            if ($this->recovery->recoverIfClosed()) {
                $report = $this->entityManager->find(ScheduledReport::class, $id) ?? $report;
            }
            $report->recordFailure($message);
            $this->flushQuietly();

            return [self::OUTCOME_FAILED, $message];
        }
    }

    /**
     * Preview: same skip rules as a run, then the attachment without sending it.
     *
     * @throws ScheduledReportSkippedException when the report must not run
     */
    public function preview(ScheduledReport $report): string
    {
        $reason = $this->skipReason($report);
        if ($reason !== null) {
            throw new ScheduledReportSkippedException($reason);
        }

        return $this->reportService->previewReport($report);
    }

    /**
     * @param array{processed: int, success: int, failed: int, skipped: int, details: list<array<string, mixed>>} $results
     * @return array{processed: int, success: int, failed: int, skipped: int, details: list<array<string, mixed>>}
     */
    private function tally(array $results, ScheduledReport $report, string $outcome, string $message): array
    {
        $results['processed']++;
        $results[$outcome]++;
        $results['details'][] = [
            'id' => $report->getId(),
            'name' => $report->getName(),
            'status' => $outcome,
            'error' => $outcome === self::OUTCOME_SENT ? null : $message,
        ];

        return $results;
    }

    private function flushQuietly(): void
    {
        try {
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Could not store the scheduled report run', ['exception' => $e]);
            $this->recovery->recoverIfClosed();
        }
    }
}
