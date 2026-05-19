<?php

declare(strict_types=1);

namespace App\Controller;

use App\Job\QuickFixApplyMigrationsJob;
use App\Job\QuickFixForceSchemaUpdateJob;
use App\Job\QuickFixReconcileSchemaJob;
use App\Job\QuickFixRepairAllJob;
use App\Job\QuickFixRepairDuplicatesJob;
use App\Job\QuickFixRepairOrphansJob;
use App\Job\QuickFixRepairTenantMismatchesJob;
use App\Message\Job\ExecuteJobMessage;
use App\Service\AuditLogger;
use App\Service\DataIntegrityService;
use App\Service\Job\JobStatusService;
use App\Service\QuickFixGuard;
use App\Service\SchemaMaintenanceService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Public-fallback Quick-Fix UI: lets self-hosted operators apply pending
 * Doctrine migrations after a Composer-style code update without needing
 * shell access. Reachable when {@see QuickFixGuard} permits — defaults to
 * open, can be locked via installer-token / dev-only / IP-allowlist.
 *
 * Routes are intentionally NOT locale-prefixed and NOT under `/admin/` so
 * the SchemaExceptionSubscriber can redirect here even when the locale
 * resolver itself fails on schema errors.
 */
class QuickFixController extends AbstractController
{
    public function index(
        Request $request,
        QuickFixGuard $guard,
        SchemaMaintenanceService $maintenance,
        DataIntegrityService $dataIntegrity,
    ): Response {
        if (!$guard->mayAccess($request)) {
            return $this->render('quick_fix/locked.html.twig', [
                'token_required' => $guard->isTokenRequired(),
            ], new Response('', Response::HTTP_FORBIDDEN));
        }

        // If a token was supplied via ?token= and matches, persist it as a
        // cookie so the user does not need to keep the URL parameter on the
        // POST submission. Lifetime: session.
        $cookieToSet = null;
        if ($request->query->has('token') && $guard->mayAccess($request)) {
            $cookieToSet = (string) $request->query->get('token');
        }

        $pendingCount = 0;
        $pendingNames = [];
        $pendingFsCount = 0;
        $pendingFsNames = [];
        $driftCount = 0;
        $driftStatements = [];
        $driftDestructive = [];
        $entityDriftCount = 0;
        $entityDriftStatements = [];
        $errorMessage = null;
        try {
            $status = $maintenance->getMaintenanceStatus();
            $pendingCount = (int) ($status['migration_status']['pending'] ?? 0);
            $pendingNames = $status['migration_status']['names'] ?? [];
            $driftCount = (int) ($status['schema_drift']['count'] ?? 0);
            $driftStatements = $status['schema_drift']['statements'] ?? [];
            $driftDestructive = $status['schema_drift']['destructive'] ?? [];
            $entityDriftCount = (int) ($status['entity_drift']['count'] ?? 0);
            $entityDriftStatements = $status['entity_drift']['statements'] ?? [];

            // Second source: file-system-discovered pending migrations
            // (the same list SchemaHealthService uses to gate reconcile).
            // Surface the count when it diverges from Doctrine's plan.
            $pendingFsNames = $maintenance->listPendingMigrationVersionsFromFileSystem();
            $pendingFsCount = count($pendingFsNames);
        } catch (\Throwable $e) {
            $errorMessage = $e->getMessage();
        }

        // Data integrity counts for Operator UI.
        // Failures here are non-fatal — schema may be broken, so integrity
        // checks could throw. We show zeros in that case and surface partial
        // errors as informational flash messages on the index page.
        // Each operation is independently wrapped so a column-not-found on
        // one entity type does NOT prevent the remaining checks from running.
        $orphanCount = 0;
        $orphansByType = [];
        $mismatchCount = 0;
        $duplicateCounts = [];
        $dataIntegrityErrors = [];
        try {
            $orphaned = $dataIntegrity->findAllOrphanedEntities();
            foreach ($orphaned as $type => $entities) {
                $cnt = count($entities);
                $orphansByType[$type] = $cnt;
                $orphanCount += $cnt;
            }
        } catch (\Throwable $e) {
            $dataIntegrityErrors[] = 'orphans: ' . $e->getMessage();
        }
        try {
            $broken = $dataIntegrity->findBrokenReferences();
            $mismatchCount = count($broken);
        } catch (\Throwable $e) {
            $dataIntegrityErrors[] = 'mismatches: ' . $e->getMessage();
        }
        try {
            $allDuplicates = $dataIntegrity->findDuplicateEntities();
            foreach ($allDuplicates as $type => $groups) {
                $duplicateCounts[$type] = count($groups);
            }
        } catch (\Throwable $e) {
            $dataIntegrityErrors[] = 'duplicates: ' . $e->getMessage();
        }

        // Read recovery payload(s) stored by apply() on failure.
        // Sticky across refreshes — cleared on successful force-mark or
        // when pending migrations reach zero in apply() so the operator
        // can refresh the page without losing the recovery affordance.
        //
        // Priority: universal key (any error category) > legacy phantom-diff key.
        // Both are cleared on successful apply or force-mark.
        $applyFailure = null;
        $errorDiagnosis = null;
        $session = $request->getSession();

        // Universal apply-failure payload (any error category, incl. data
        // constraint violations like 1048 "Column cannot be null").
        if ($session->has('quick_fix.last_apply_failure')) {
            /** @var array{version: string, category: string, message: string, suggested_action: string}|null $failureData */
            $failureData = $session->get('quick_fix.last_apply_failure');
            if (is_array($failureData) && isset($failureData['version'])) {
                $applyFailure = $failureData;
            }
        }

        // Backward-compat: if only the legacy phantom-diff key exists (e.g.
        // stored by an older code version), synthesize applyFailure from it.
        if ($applyFailure === null && $session->has('quick_fix.last_phantom_diff')) {
            /** @var array{version: string, message: string}|null $phantomData */
            $phantomData = $session->get('quick_fix.last_phantom_diff');
            if (is_array($phantomData) && isset($phantomData['version'])) {
                $applyFailure = [
                    'version' => $phantomData['version'],
                    'category' => 'phantom_diff_migration',
                    'message' => $phantomData['message'] ?? '',
                    'suggested_action' => '',
                ];
                // Also populate the legacy error_diagnosis for backward-compat
                // with any template code that might still read it.
                $errorDiagnosis = [
                    'category' => 'phantom_diff_migration',
                    'offending_version' => $phantomData['version'],
                    'message' => $phantomData['message'] ?? '',
                ];
            }
        }

        $response = $this->render('quick_fix/index.html.twig', [
            'pending_count' => $pendingCount,
            'pending_names' => $pendingNames,
            'pending_fs_count' => $pendingFsCount,
            'pending_fs_names' => $pendingFsNames,
            'drift_count' => $driftCount,
            'drift_statements' => $driftStatements,
            'drift_destructive' => $driftDestructive,
            'entity_drift_count' => $entityDriftCount,
            'entity_drift_statements' => $entityDriftStatements,
            'error_message' => $errorMessage,
            'error_diagnosis' => $errorDiagnosis,
            'apply_failure' => $applyFailure,
            // Data integrity
            'orphan_count' => $orphanCount,
            'orphans_by_type' => $orphansByType,
            'mismatch_count' => $mismatchCount,
            'duplicate_counts' => $duplicateCounts,
            'data_integrity_errors' => $dataIntegrityErrors,
        ]);

        if ($cookieToSet !== null) {
            $response->headers->setCookie(
                \Symfony\Component\HttpFoundation\Cookie::create('quick_fix_token')
                    ->withValue($cookieToSet)
                    ->withHttpOnly(true)
                    ->withSecure($request->isSecure())
                    ->withSameSite('Lax'),
            );
        }

        return $response;
    }

    #[IsCsrfTokenValid('quick_fix_apply')]
    public function apply(
        Request $request,
        QuickFixGuard $guard,
        JobStatusService $jobStatusService,
        MessageBusInterface $messageBus,
        TranslatorInterface $translator,
    ): Response {
        if (!$guard->mayAccess($request)) {
            return $this->render('quick_fix/locked.html.twig', [
                'token_required' => $guard->isTokenRequired(),
            ], new Response('', Response::HTTP_FORBIDDEN));
        }

        // Clearing the session keys early: if the previous failure was rooted
        // in a Doctrine plan that now applies cleanly, the recovery card on
        // the index page should not linger after the worker drains the job.
        $request->getSession()->remove('quick_fix.last_phantom_diff');
        $request->getSession()->remove('quick_fix.last_apply_failure');

        return $this->dispatchJobProgress(
            $jobStatusService,
            $messageBus,
            $translator,
            QuickFixApplyMigrationsJob::class,
            'quick_fix.apply',
            'quick_fix.job.apply_label',
            'quick_fix.job.apply_subtitle',
            [],
        );
    }

    #[IsCsrfTokenValid('quick_fix_reconcile')]
    public function reconcile(
        Request $request,
        QuickFixGuard $guard,
        SchemaMaintenanceService $maintenance,
        JobStatusService $jobStatusService,
        MessageBusInterface $messageBus,
        TranslatorInterface $translator,
    ): Response {
        if (!$guard->mayAccess($request)) {
            return $this->render('quick_fix/locked.html.twig', [
                'token_required' => $guard->isTokenRequired(),
            ], new Response('', Response::HTTP_FORBIDDEN));
        }

        // Destructive drift (DROP/TRUNCATE) requires explicit confirm-checkbox.
        // The status check still runs synchronously here — it's cheap and we
        // want to reject the form submit BEFORE dispatching a long-running job
        // that would just fail mid-flight.
        try {
            $status = $maintenance->getMaintenanceStatus();
            $destructive = $status['schema_drift']['destructive'] ?? [];
        } catch (\Throwable $e) {
            $this->addFlash('error', sprintf('Status-Abfrage fehlgeschlagen: %s', $e->getMessage()));
            return new RedirectResponse($this->generateUrl('app_quick_fix_index'));
        }

        $confirmDestructive = (bool) $request->request->get('confirm_destructive', false);
        if ($destructive !== [] && !$confirmDestructive) {
            $this->addFlash('error', 'Destruktive Statements erkannt — Risiko-Akzeptanz-Checkbox aktivieren um anzuwenden.');
            return new RedirectResponse($this->generateUrl('app_quick_fix_index'));
        }

        return $this->dispatchJobProgress(
            $jobStatusService,
            $messageBus,
            $translator,
            QuickFixReconcileSchemaJob::class,
            'quick_fix.reconcile',
            'quick_fix.job.reconcile_label',
            'quick_fix.job.reconcile_subtitle',
            [],
        );
    }

    // =========================================================================
    // Phantom-diff recovery — force-mark a migration as executed without DDL.
    // Safety gate: CSRF + explicit "already exists" checkbox.
    // =========================================================================

    /**
     * Marks a migration version as executed in the metadata storage WITHOUT
     * running its DDL. Only safe when the operator has verified that the
     * schema change (column/table) already exists in the live database.
     *
     * Protected by:
     * - QuickFixGuard (IP/token/dev-only constraints)
     * - CSRF token 'quick_fix_force_mark_executed'
     * - Mandatory confirm_already_exists checkbox
     */
    #[IsCsrfTokenValid('quick_fix_force_mark_executed')]
    public function forceMarkExecuted(
        Request $request,
        QuickFixGuard $guard,
        SchemaMaintenanceService $maintenance,
        AuditLogger $auditLogger,
    ): Response {
        if (!$guard->mayAccess($request)) {
            return $this->render('quick_fix/locked.html.twig', [
                'token_required' => $guard->isTokenRequired(),
            ], new Response('', Response::HTTP_FORBIDDEN));
        }

        $confirmAlreadyExists = (bool) $request->request->get('confirm_already_exists', false);
        if (!$confirmAlreadyExists) {
            $this->addFlash('error', 'Sicherheitscheck: Bitte bestätigen dass das Schema die Tabelle/Spalte WIRKLICH bereits enthält.');
            return new RedirectResponse($this->generateUrl('app_quick_fix_index'));
        }

        $version = trim((string) $request->request->get('version', ''));
        if ($version === '') {
            $this->addFlash('error', 'Keine Migrations-Version angegeben.');
            return new RedirectResponse($this->generateUrl('app_quick_fix_index'));
        }

        $result = $maintenance->markMigrationAsExecuted($version);

        if ($result['success']) {
            $request->getSession()->remove('quick_fix.last_phantom_diff');
            $request->getSession()->remove('quick_fix.last_apply_failure');
            $auditLogger->logCustom(
                'quick_fix.force_mark_migration_executed',
                'Migration',
                null,
                null,
                ['version' => $version],
                sprintf('QuickFix: operator force-marked migration as executed (no DDL): %s', $version),
            );
            $this->addFlash('success', sprintf('Migration "%s" als ausgeführt markiert (ohne DDL).', $version));
        } else {
            $this->addFlash('error', sprintf('Fehler beim Markieren: %s', $result['error'] ?? 'unbekannter Fehler'));
        }

        return new RedirectResponse($this->generateUrl('app_quick_fix_index'));
    }

    // =========================================================================
    // Bulk phantom-diff recovery — mark ALL phantom-diff migrations at once.
    // Safety gate: CSRF + explicit "all already exist" checkbox.
    // =========================================================================

    /**
     * Iterates over pending migrations and marks each phantom-diff version as
     * executed without running its DDL. Stops on the first non-phantom error
     * or when all phantom-diff migrations are cleared.
     *
     * Protected by:
     * - QuickFixGuard (IP/token/dev-only constraints)
     * - CSRF token 'quick_fix_mark_all_phantom_diff'
     * - Mandatory confirm_all_already_exist checkbox
     */
    #[IsCsrfTokenValid('quick_fix_mark_all_phantom_diff')]
    public function markAllPhantomDiff(
        Request $request,
        QuickFixGuard $guard,
        SchemaMaintenanceService $maintenance,
        AuditLogger $auditLogger,
    ): Response {
        if (!$guard->mayAccess($request)) {
            return $this->render('quick_fix/locked.html.twig', [
                'token_required' => $guard->isTokenRequired(),
            ], new Response('', Response::HTTP_FORBIDDEN));
        }

        $confirmAllAlreadyExist = (bool) $request->request->get('confirm_all_already_exist', false);
        if (!$confirmAllAlreadyExist) {
            $this->addFlash('error', 'Sicherheitscheck: Bitte bestätigen dass ALLE Tabellen/Spalten der ausstehenden Migrationen im Schema bereits vorhanden sind.');
            return new RedirectResponse($this->generateUrl('app_quick_fix_index'));
        }

        $result = $maintenance->markAllPhantomDiffMigrationsAsExecuted('quick-fix');

        // Clear both session keys — the bulk action supersedes them.
        $request->getSession()->remove('quick_fix.last_phantom_diff');
        $request->getSession()->remove('quick_fix.last_apply_failure');

        $markedCount = count($result['marked']);

        $skippedCount = count($result['skipped'] ?? []);

        $auditLogger->logCustom(
            'quick_fix.force_mark_migration_executed',
            'Migration',
            null,
            null,
            [
                'marked_count' => $markedCount,
                'skipped_count' => $skippedCount,
                'remaining_pending' => $result['remaining_pending'],
                'batch' => 'mark_all_phantom_diff',
            ],
            sprintf(
                'QuickFix: mark-all-phantom-diff completed — %d marked, %d skipped, %d still pending',
                $markedCount,
                $skippedCount,
                $result['remaining_pending'],
            ),
        );

        if ($markedCount > 0) {
            $this->addFlash('success', sprintf(
                '%d Migration(en) als ausgeführt markiert.',
                $markedCount,
            ));
        }

        if ($skippedCount > 0) {
            $this->addFlash('warning', sprintf(
                '%d Migration(en) konnten nicht automatisch behandelt werden (real errors). Manuelle Prüfung erforderlich.',
                $skippedCount,
            ));
            foreach (($result['skipped'] ?? []) as $version => $reason) {
                $this->addFlash('warning', sprintf(
                    '%s: %s',
                    $version,
                    substr($reason, 0, 200),
                ));
            }
        }

        if ($markedCount === 0 && $skippedCount === 0) {
            $this->addFlash('info', sprintf(
                'Keine phantom-diff Migrationen gefunden. Noch ausstehend: %d.',
                $result['remaining_pending'],
            ));
        }

        return new RedirectResponse($this->generateUrl('app_quick_fix_index'));
    }

    // =========================================================================
    // Data-Repair routes — operator emergency UI
    // All guarded by QuickFixGuard + IsCsrfTokenValid. All operations are
    // idempotent (safe to click multiple times).
    // =========================================================================

    /**
     * Assigns all orphaned entities (tenant IS NULL) to the first available
     * tenant. Idempotent — re-running when 0 orphans is a no-op.
     */
    #[IsCsrfTokenValid('quick_fix_repair_orphans')]
    public function repairOrphans(
        Request $request,
        QuickFixGuard $guard,
        JobStatusService $jobStatusService,
        MessageBusInterface $messageBus,
        TranslatorInterface $translator,
    ): Response {
        if (!$guard->mayAccess($request)) {
            return $this->render('quick_fix/locked.html.twig', [
                'token_required' => $guard->isTokenRequired(),
            ], new Response('', Response::HTTP_FORBIDDEN));
        }

        return $this->dispatchJobProgress(
            $jobStatusService,
            $messageBus,
            $translator,
            QuickFixRepairOrphansJob::class,
            'quick_fix.repair_orphans',
            'quick_fix.job.repair_orphans_label',
            'quick_fix.job.repair_orphans_subtitle',
            [],
        );
    }

    /**
     * Fixes tenant mismatches (child entity tenant != parent entity tenant).
     * Each fix is audit-logged. Idempotent.
     */
    #[IsCsrfTokenValid('quick_fix_repair_mismatches')]
    public function repairTenantMismatches(
        Request $request,
        QuickFixGuard $guard,
        JobStatusService $jobStatusService,
        MessageBusInterface $messageBus,
        TranslatorInterface $translator,
    ): Response {
        if (!$guard->mayAccess($request)) {
            return $this->render('quick_fix/locked.html.twig', [
                'token_required' => $guard->isTokenRequired(),
            ], new Response('', Response::HTTP_FORBIDDEN));
        }

        return $this->dispatchJobProgress(
            $jobStatusService,
            $messageBus,
            $translator,
            QuickFixRepairTenantMismatchesJob::class,
            'quick_fix.repair_tenant_mismatches',
            'quick_fix.job.repair_mismatches_label',
            'quick_fix.job.repair_mismatches_subtitle',
            [],
        );
    }

    /**
     * Merges duplicate entities for a given entity type.
     * Keeps the entity with the lowest ID; deletes newer duplicates.
     * Idempotent — zero duplicates means no DB changes.
     */
    #[IsCsrfTokenValid('quick_fix_repair_duplicates')]
    public function repairDuplicates(
        Request $request,
        QuickFixGuard $guard,
        JobStatusService $jobStatusService,
        MessageBusInterface $messageBus,
        TranslatorInterface $translator,
        string $entityType,
    ): Response {
        if (!$guard->mayAccess($request)) {
            return $this->render('quick_fix/locked.html.twig', [
                'token_required' => $guard->isTokenRequired(),
            ], new Response('', Response::HTTP_FORBIDDEN));
        }

        $allowed = ['audits', 'assets', 'risks', 'incidents', 'documents'];
        if (!in_array($entityType, $allowed, true)) {
            $this->addFlash('error', sprintf('Unbekannter Entity-Typ: %s', $entityType));
            return new RedirectResponse($this->generateUrl('app_quick_fix_index'));
        }

        return $this->dispatchJobProgress(
            $jobStatusService,
            $messageBus,
            $translator,
            QuickFixRepairDuplicatesJob::class,
            'quick_fix.repair_duplicates',
            'quick_fix.job.repair_duplicates_label',
            'quick_fix.job.repair_duplicates_subtitle',
            ['entityType' => $entityType],
        );
    }

    /**
     * Convenience: runs ALL non-destructive repair operations in sequence.
     * Orphan-assign → mismatch-fix → duplicate-merge for all types.
     * Each step is audit-logged individually. Idempotent.
     */
    #[IsCsrfTokenValid('quick_fix_repair_all')]
    public function repairAll(
        Request $request,
        QuickFixGuard $guard,
        JobStatusService $jobStatusService,
        MessageBusInterface $messageBus,
        TranslatorInterface $translator,
    ): Response {
        if (!$guard->mayAccess($request)) {
            return $this->render('quick_fix/locked.html.twig', [
                'token_required' => $guard->isTokenRequired(),
            ], new Response('', Response::HTTP_FORBIDDEN));
        }

        return $this->dispatchJobProgress(
            $jobStatusService,
            $messageBus,
            $translator,
            QuickFixRepairAllJob::class,
            'quick_fix.repair_all',
            'quick_fix.job.repair_all_label',
            'quick_fix.job.repair_all_subtitle',
            [],
        );
    }

    // =========================================================================
    // Nuclear fallback: doctrine:schema:update --force (saveMode=true)
    // Only emits ADD/CREATE — never drops tables. Gated by confirm checkbox.
    // =========================================================================

    /**
     * Programmatically runs `doctrine:schema:update --force` (saveMode=true)
     * so existing data is never destroyed. Use as last resort when reconcile
     * still fails after the FK-check envelope is applied.
     *
     * Protected by:
     * - QuickFixGuard (IP/token/dev-only constraints)
     * - CSRF token 'quick_fix_force_schema_update'
     * - Mandatory confirm_force_schema checkbox
     */
    #[IsCsrfTokenValid('quick_fix_force_schema_update')]
    public function forceSchemaUpdate(
        Request $request,
        QuickFixGuard $guard,
        JobStatusService $jobStatusService,
        MessageBusInterface $messageBus,
        TranslatorInterface $translator,
    ): Response {
        if (!$guard->mayAccess($request)) {
            return $this->render('quick_fix/locked.html.twig', [
                'token_required' => $guard->isTokenRequired(),
            ], new Response('', Response::HTTP_FORBIDDEN));
        }

        $confirmForce = (bool) $request->request->get('confirm_force_schema', false);
        if (!$confirmForce) {
            $this->addFlash('error', 'Sicherheitscheck: Bitte Bestätigungs-Checkbox aktivieren um Schema-Update zu erzwingen.');
            return new RedirectResponse($this->generateUrl('app_quick_fix_index'));
        }

        return $this->dispatchJobProgress(
            $jobStatusService,
            $messageBus,
            $translator,
            QuickFixForceSchemaUpdateJob::class,
            'quick_fix.force_schema_update',
            'quick_fix.job.force_schema_label',
            'quick_fix.job.force_schema_subtitle',
            [],
        );
    }

    /**
     * Shared dispatch helper for every QuickFix async action.
     *
     * Creates a job-status record, dispatches the Messenger message, then
     * renders the standalone progress template that polls the public
     * /quick-fix/jobs/{id}/status endpoint.
     *
     * @param class-string $jobClass    FQCN of the {@see \App\Job\AsyncJobInterface}
     * @param string       $statusName  Internal slug stored on the status record
     * @param string       $labelKey    quick_fix.* translation key for the title
     * @param string       $subtitleKey quick_fix.* translation key for the subtitle
     * @param array<string, mixed> $args Args forwarded to the job context
     */
    private function dispatchJobProgress(
        JobStatusService $jobStatusService,
        MessageBusInterface $messageBus,
        TranslatorInterface $translator,
        string $jobClass,
        string $statusName,
        string $labelKey,
        string $subtitleKey,
        array $args,
    ): Response {
        $jobId = $jobStatusService->create($statusName, $args);

        $messageBus->dispatch(new ExecuteJobMessage(
            jobClass: $jobClass,
            args: $args,
            jobId: $jobId,
        ));

        return $this->render('quick_fix/job_progress.html.twig', [
            'jobId' => $jobId,
            'jobLabel' => $translator->trans($labelKey, [], 'quick_fix'),
            'jobSubtitle' => $translator->trans($subtitleKey, [], 'quick_fix'),
            'cancelUrl' => $this->generateUrl('app_quick_fix_index'),
            'statusUrl' => $this->generateUrl('app_quick_fix_job_status', ['id' => $jobId]),
        ]);
    }
}
