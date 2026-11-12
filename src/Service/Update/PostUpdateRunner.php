<?php

declare(strict_types=1);

namespace App\Service\Update;

use App\Repository\SystemSettingsRepository;
use App\Service\AuditLogger;
use App\Service\Backup\BackupVersionInfo;
use App\Service\SchemaMaintenanceService;
use App\Service\SchemaSnapshotService;
use App\Service\TenantContext;
use App\Service\Upload\ProtectedUploads;
use App\Workflow\Mirror\RegulatoryWorkflowMirror;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Runs the update-based Quick-Fix steps by itself after a deploy brought new code.
 *
 * "New code" = the fingerprint (application version, newest migration, regulatory workflow YAML)
 * differs from the one stored by the last successful run. Steps, all idempotent:
 *
 *  1. pending Doctrine migrations (with snapshot) + non-destructive schema reconcile —
 *     destructive drift stays a manual Quick-Fix decision,
 *  2. regulatory workflow definitions aligned with their YAML (what "Workflow-Definitionen abgleichen" does),
 *  3. uploads that still lie in public/uploads moved to var/uploads.
 *
 * Triggers (no request parameter chooses anything): the scheduler command `app:update:run` and
 * {@see \App\EventSubscriber\PostUpdateSubscriber} (after the response, for hosts without a worker).
 * This is the explicit system path beside S33 (`QuickFixGuard::mayRunPrivileged()` gates the
 * operator's buttons; nobody can trigger more than "bring the instance in line with its own code").
 * A failure is stored, audit-logged and shown on the Quick-Fix page; it is not retried by itself (same fingerprint = not due),
 * the operator fixes the cause and presses "Erneut ausführen" (or ships the next version).
 * Switch off: Admin > Quick-Fix settings, `auto_update`.
 */
class PostUpdateRunner
{
    public const SETTING_CATEGORY = 'quick_fix';
    public const SETTING_KEY = 'auto_update';

    public function __construct(
        private readonly PostUpdateState $state,
        private readonly SchemaMaintenanceService $maintenance,
        private readonly SchemaSnapshotService $snapshots,
        private readonly RegulatoryWorkflowMirror $workflows,
        private readonly ProtectedUploads $uploads,
        private readonly AuditLogger $auditLogger,
        private readonly TenantContext $tenantContext,
        private readonly SystemSettingsRepository $settings,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function isEnabled(): bool
    {
        try {
            return (bool) $this->settings->getSetting(self::SETTING_CATEGORY, self::SETTING_KEY, true);
        } catch (\Throwable) {
            // Settings table may be the thing that is behind: default is on.
            return true;
        }
    }

    /** Which code is deployed: version + newest migration + regulatory workflow YAML. */
    public function fingerprint(): string
    {
        $migrations = glob($this->projectDir . '/migrations/Version*.php') ?: [];
        sort($migrations);
        $yaml = glob($this->projectDir . '/config/workflows/regulatory/*.yaml') ?: [];
        sort($yaml);
        $yamlHash = sha1(implode('|', array_map(static fn (string $f): string => basename($f) . sha1_file($f), $yaml)));

        return sha1(implode('|', [
            BackupVersionInfo::application($this->projectDir),
            $migrations === [] ? '-' : basename((string) end($migrations)),
            count($migrations),
            $yamlHash,
        ]));
    }

    /** Three file stats: cheap enough for every request; a change means "compute the fingerprint". */
    public function statSignature(): string
    {
        $parts = [];
        foreach (['/composer.json', '/composer.lock', '/migrations', '/config/workflows/regulatory'] as $rel) {
            $parts[] = (string) @filemtime($this->projectDir . $rel);
        }

        return implode('.', $parts);
    }

    /** True when the deployed code is newer than what the last run took care of. */
    public function isDue(): bool
    {
        if (!$this->isEnabled() || !$this->setupComplete()) {
            return false;
        }
        $last = $this->state->read();
        if ($last !== null && $last['stat'] === $this->statSignature()) {
            return false;
        }
        if ($last === null) {
            return true;
        }
        if ($last['fingerprint'] === $this->fingerprint()) {
            // Only file times moved (re-upload, touch): remember them, nothing to do.
            if ($last['status'] !== 'running') {
                $last['stat'] = $this->statSignature();
                $this->state->write($last);
            }

            return false;
        }

        return true;
    }

    /**
     * @return array{ran: bool, status: string}
     */
    public function run(string $trigger, bool $force = false): array
    {
        if (!$force && !$this->isDue()) {
            return ['ran' => false, 'status' => 'not_due'];
        }
        $last = $this->state->read();

        $lockDir = $this->projectDir . '/var';
        if (!is_dir($lockDir)) {
            @mkdir($lockDir, 0775, true);
        }
        $lock = @fopen($lockDir . '/post-update.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            return ['ran' => false, 'status' => 'locked'];
        }

        try {
            return ['ran' => true, 'status' => $this->execute($trigger, $last)];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param array<string, mixed>|null $last */
    private function execute(string $trigger, ?array $last): string
    {
        @set_time_limit(0);
        $fingerprint = $this->fingerprint();
        $version = BackupVersionInfo::application($this->projectDir);
        $state = [
            'fingerprint' => $fingerprint,
            'stat' => $this->statSignature(),
            'status' => 'running',
            'version' => $version,
            'previous_version' => is_array($last) ? ($last['version'] ?? null) : null,
            'started_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            'finished_at' => null,
            'trigger' => $trigger,
            'steps' => [],
            'error' => null,
        ];
        $this->state->write($state);

        $this->tenantContext->runWithoutOrganisation(function () use (&$state): void {
            $this->step($state, 'migrations', $this->migrations(...));
            if ($state['steps'][0]['status'] !== 'failed') {
                $this->step($state, 'workflows', $this->workflowDefinitions(...));
                $this->step($state, 'uploads', $this->protectUploads(...));
            }
        });

        $failed = array_values(array_filter($state['steps'], static fn (array $s): bool => $s['status'] === 'failed'));
        $state['status'] = $failed === [] ? 'ok' : 'failed';
        $state['error'] = $failed === [] ? null : $failed[0]['message'];
        $state['finished_at'] = (new \DateTimeImmutable())->format(\DATE_ATOM);
        $this->state->write($state);

        try {
            $this->auditLogger->logCustom(
                $state['status'] === 'ok' ? 'system.auto_update.completed' : 'system.auto_update.failed',
                'System',
                null,
                ['version' => $state['previous_version']],
                ['version' => $version, 'trigger' => $trigger, 'steps' => $state['steps']],
                sprintf('Automatic post-update run %s (%s)', $state['status'], $version),
                'system',
            );
        } catch (\Throwable $e) {
            $this->logger->error('Post-update audit entry failed', ['exception' => $e]);
        }

        return $state['status'];
    }

    /**
     * @param array<string, mixed> $state
     * @param callable(): array{0: string, 1: string} $work [status ok|skipped, message]; throws on failure
     */
    private function step(array &$state, string $name, callable $work): void
    {
        try {
            [$status, $message] = $work();
        } catch (\Throwable $e) {
            $this->logger->error('Post-update step failed', ['step' => $name, 'exception' => $e]);
            [$status, $message] = ['failed', $e->getMessage()];
        }
        $state['steps'][] = ['name' => $name, 'status' => $status, 'message' => $message];
        $this->state->write($state);
    }

    /** @return array{0: string, 1: string} */
    protected function migrations(): array
    {
        $pending = (int) $this->maintenance->getMaintenanceStatus()['migration_status']['pending'];
        if ($pending === 0) {
            return ['skipped', 'no pending migrations'];
        }
        $this->snapshots->snapshot('auto-update');
        $result = $this->maintenance->executePendingMigrations('auto-update');
        if (!($result['success'] ?? false)) {
            $diagnosis = $result['diagnosis'] ?? null;

            // @intentional-assertion: the failed step is recorded in the state file and the audit log
            throw new \RuntimeException(is_array($diagnosis) && ($diagnosis['category'] ?? 'unknown') !== 'unknown'
                ? sprintf('[%s] %s', $diagnosis['category'], $diagnosis['message'] ?? '')
                : (string) ($result['error'] ?? 'migration failed'));
        }
        $message = sprintf('%d migration(s) applied', (int) ($result['executed'] ?? 0));

        // Non-destructive drift only; destructive statements wait for the operator.
        $status = $this->maintenance->getMaintenanceStatus();
        if ($status['schema_drift']['count'] > 0 && $status['schema_drift']['destructive'] === []) {
            $reconcile = $this->maintenance->reconcileSchema('auto-update');
            if ($reconcile['success']) {
                $message .= sprintf(', %d schema statement(s) reconciled', $reconcile['executed']);
            }
        }

        return ['ok', $message];
    }

    /** @return array{0: string, 1: string} */
    protected function workflowDefinitions(): array
    {
        $result = $this->workflows->sync();

        return ['ok', sprintf('%d created, %d updated', $result['created'], $result['updated'])];
    }

    /** @return array{0: string, 1: string} */
    protected function protectUploads(): array
    {
        $result = $this->uploads->moveLegacyFiles();
        if ($result['failed'] > 0) {
            // @intentional-assertion: the failed step is recorded in the state file and the audit log
            throw new \RuntimeException(sprintf('%d upload file(s) could not be moved', $result['failed']));
        }

        return ['ok', sprintf('%d moved, %d already present', $result['moved'], $result['kept'])];
    }

    private function setupComplete(): bool
    {
        return is_file($this->projectDir . '/var/setup_complete.lock')
            || is_file($this->projectDir . '/config/setup_complete.lock');
    }
}
