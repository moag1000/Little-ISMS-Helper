<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AuditLogger;
use App\Service\DataIntegrityService;
use App\Service\QuickFixGuard;
use App\Service\SchemaMaintenanceService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

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
        SchemaMaintenanceService $maintenance,
    ): Response {
        if (!$guard->mayAccess($request)) {
            return $this->render('quick_fix/locked.html.twig', [
                'token_required' => $guard->isTokenRequired(),
            ], new Response('', Response::HTTP_FORBIDDEN));
        }

        $migrationResult = $maintenance->executePendingMigrations('quick-fix');

        if ($migrationResult['success']) {
            $request->getSession()->remove('quick_fix.last_phantom_diff');
            $request->getSession()->remove('quick_fix.last_apply_failure');
        }

        if (!$migrationResult['success']) {
            $diagnosis = $migrationResult['diagnosis'] ?? null;
            if (is_array($diagnosis) && ($diagnosis['category'] ?? 'unknown') !== 'unknown') {
                $this->addFlash('error', sprintf(
                    'Migration failed [%s]: %s — %s',
                    $diagnosis['category'],
                    $diagnosis['message'] ?? '',
                    $diagnosis['suggested_action'] ?? '',
                ));

                // Store the failure payload for ANY error category so the index
                // page can surface recovery options (skip-single, skip-all,
                // force-schema-update) regardless of error type.
                $offendingVersion = $diagnosis['offending_version'] ?? null;
                if ($offendingVersion !== null) {
                    $request->getSession()->set('quick_fix.last_apply_failure', [
                        'version' => $offendingVersion,
                        'category' => $diagnosis['category'] ?? 'unknown',
                        'message' => $diagnosis['message'] ?? '',
                        'suggested_action' => $diagnosis['suggested_action'] ?? '',
                    ]);
                }

                // Keep the existing phantom-diff session key as a backward-compat
                // alias so callers that already read it continue to work.
                if (
                    ($diagnosis['category'] ?? '') === 'phantom_diff_migration'
                    && $offendingVersion !== null
                ) {
                    $request->getSession()->set('quick_fix.last_phantom_diff', [
                        'version' => $offendingVersion,
                        'message' => $diagnosis['message'] ?? '',
                    ]);
                }
            } else {
                $this->addFlash('error', sprintf('Migration failed: %s', $migrationResult['error'] ?? 'unknown error'));
            }
            return new RedirectResponse($this->generateUrl('app_quick_fix_index'));
        }

        // Auto-chain: after migrations land, re-check schema-drift. If
        // non-destructive drift remains (entity-metadata vs DB), run
        // reconcile in the same click. Destructive drift still requires
        // manual review via CLI.
        $reconcileExecuted = 0;
        $reconcileError = null;
        try {
            $status = $maintenance->getMaintenanceStatus();
            $driftCount = (int) ($status['schema_drift']['count'] ?? 0);
            $destructive = $status['schema_drift']['destructive'] ?? [];

            if ($driftCount > 0 && $destructive === []) {
                $reconcileResult = $maintenance->reconcileSchema('quick-fix');
                if ($reconcileResult['success']) {
                    $reconcileExecuted = (int) $reconcileResult['executed'];
                } else {
                    $reconcileError = $reconcileResult['error'] ?? $reconcileResult['blocked'] ?? 'unknown error';
                }
            }
        } catch (\Throwable $e) {
            $reconcileError = $e->getMessage();
        }

        $this->addFlash('success', sprintf(
            '%d Migration(en) angewendet%s.',
            $migrationResult['executed'],
            $reconcileExecuted > 0 ? sprintf(' + %d Schema-Statement(s) reconciled', $reconcileExecuted) : '',
        ));

        if ($reconcileError !== null) {
            $this->addFlash('error', sprintf('Auto-Reconcile fehlgeschlagen: %s', $reconcileError));
        }

        return new RedirectResponse($this->generateUrl('app_quick_fix_index', ['fixed' => 1]));
    }

    #[IsCsrfTokenValid('quick_fix_reconcile')]
    public function reconcile(
        Request $request,
        QuickFixGuard $guard,
        SchemaMaintenanceService $maintenance,
    ): Response {
        if (!$guard->mayAccess($request)) {
            return $this->render('quick_fix/locked.html.twig', [
                'token_required' => $guard->isTokenRequired(),
            ], new Response('', Response::HTTP_FORBIDDEN));
        }

        // Destructive drift (DROP/TRUNCATE) requires explicit confirm-checkbox.
        // No more CLI-only escape hatch — operator can apply via UI when they
        // ticked the risk-acceptance checkbox in the form.
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

        // First attempt: standard reconcile (will block on pending_migrations
        // if SchemaHealthService sees file-system-discovered migrations that
        // haven't run yet).
        $result = $maintenance->reconcileSchema('quick-fix');

        // Auto-recovery: if blocked by pending_migrations, try to apply them
        // via the Doctrine MigrationPlanCalculator first (which may differ
        // from SchemaHealth's file-system-list — see CLAUDE.md pitfall #6
        // PREPARE/EXECUTE silently-failing migrations leave file/DB-list
        // inconsistent). After applying, retry reconcile with bypass=true.
        if (!$result['success'] && ($result['blocked'] ?? null) === 'pending_migrations') {
            $applyResult = $maintenance->executePendingMigrations('quick-fix');
            if ($applyResult['success']) {
                // Retry with bypass — the file-system-list may still hold
                // entries for migrations that recorded as executed but
                // never ran their DDL. The user already opted-in via
                // checkbox if there's destructive drift.
                $result = $maintenance->reconcileSchema('quick-fix', true);
            } else {
                $diagnosis = $applyResult['diagnosis'] ?? null;
                $diagnosisMsg = (is_array($diagnosis) && ($diagnosis['category'] ?? 'unknown') !== 'unknown')
                    ? sprintf('[%s] %s', $diagnosis['category'], $diagnosis['suggested_action'] ?? $diagnosis['message'] ?? '')
                    : ($applyResult['error'] ?? 'unknown error');
                $this->addFlash('error', sprintf(
                    'Reconcile blockiert: %d pending Migration(en) — Apply fehlgeschlagen: %s',
                    count($maintenance->listPendingMigrationVersionsFromFileSystem()),
                    $diagnosisMsg,
                ));
                return new RedirectResponse($this->generateUrl('app_quick_fix_index'));
            }
        }

        if (!$result['success']) {
            $this->addFlash('error', sprintf('Schema-Reconcile fehlgeschlagen: %s', $result['error'] ?? ($result['blocked'] ?? 'unbekannter Fehler')));
            return new RedirectResponse($this->generateUrl('app_quick_fix_index'));
        }

        $autoMarkedCount = count($result['auto_marked'] ?? []);
        $this->addFlash('success', sprintf(
            '%d Schema-Statement(s) erfolgreich angewendet%s.',
            $result['executed'],
            $autoMarkedCount > 0
                ? sprintf(' + %d ausstehende Migration(en) automatisch als ausgeführt markiert', $autoMarkedCount)
                : '',
        ));
        return new RedirectResponse($this->generateUrl('app_quick_fix_index', ['fixed' => 1]));
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
        DataIntegrityService $dataIntegrity,
        EntityManagerInterface $em,
        AuditLogger $auditLogger,
    ): Response {
        if (!$guard->mayAccess($request)) {
            return $this->render('quick_fix/locked.html.twig', [
                'token_required' => $guard->isTokenRequired(),
            ], new Response('', Response::HTTP_FORBIDDEN));
        }

        // Disable tenant filter so orphans are actually visible.
        $filters = $em->getFilters();
        $wasEnabled = $filters->isEnabled('tenant_filter');
        if ($wasEnabled) {
            $filters->disable('tenant_filter');
        }

        $fixed = 0;
        $skippedGlobal = 0;
        $perEntityErrors = [];
        try {
            // Pick the first tenant as target — in single-tenant deployments
            // this is always the correct one. In multi-tenant we still assign
            // to the first to make the data visible; admins can re-assign via
            // the full admin/data-repair UI once the app is accessible again.
            $tenantRepo = $em->getRepository(\App\Entity\Tenant::class);
            /** @var \App\Entity\Tenant|null $targetTenant */
            $targetTenant = $tenantRepo->findOneBy([]);

            if ($targetTenant !== null) {
                // GLOBAL_CATALOGUE_ENTITIES have tenant_id=NULL by design (e.g.
                // NotificationTemplate). findAllOrphanedEntities() already
                // excludes them, but we also count any that slip through so we
                // can surface a "skipped: N global catalogue rows" message.
                $globalClasses = $dataIntegrity->getGlobalCatalogueEntityClasses();

                $orphaned = $dataIntegrity->findAllOrphanedEntities();
                foreach ($orphaned as $type => $entities) {
                    foreach ($entities as $entity) {
                        if (!method_exists($entity, 'setTenant') || !method_exists($entity, 'getId')) {
                            continue;
                        }

                        // Safety guard: skip any globally-scoped entity.
                        if (in_array($entity::class, $globalClasses, true)) {
                            $skippedGlobal++;
                            continue;
                        }

                        try {
                            $entity->setTenant($targetTenant);
                            $auditLogger->logCustom(
                                'quick_fix.repair.orphan_assigned',
                                (new \ReflectionClass($entity))->getShortName(),
                                (int) $entity->getId(),
                                ['tenant_id' => null],
                                ['tenant_id' => $targetTenant->getId(), 'tenant_name' => $targetTenant->getName()],
                                sprintf(
                                    'QuickFix: orphan %s#%d assigned to tenant %s',
                                    (new \ReflectionClass($entity))->getShortName(),
                                    (int) $entity->getId(),
                                    $targetTenant->getName(),
                                ),
                            );
                            $fixed++;
                        } catch (\Throwable $e) {
                            // Roll back this entity's change so the EM stays clean.
                            // A prior failure may have closed the EM; refresh would re-throw.
                            if ($em->isOpen()) {
                                try { $em->refresh($entity); } catch (\Throwable) {}
                            }
                            $perEntityErrors[] = sprintf(
                                '%s#%d: %s',
                                (new \ReflectionClass($entity))->getShortName(),
                                (int) $entity->getId(),
                                $e->getMessage(),
                            );
                        }
                    }
                }
                if ($fixed > 0 && $em->isOpen()) {
                    try { $em->flush(); } catch (\Throwable $e) {
                        $this->addFlash('warning', 'Flush nach Orphan-Repair partial: ' . $e->getMessage());
                    }
                }
            }
        } finally {
            if ($wasEnabled) {
                $filters->enable('tenant_filter');
            }
        }

        $this->addFlash('success', sprintf(
            '%d verwaiste Entität(en) dem Standard-Mandanten zugewiesen%s.',
            $fixed,
            $skippedGlobal > 0 ? sprintf(' (%d globale Katalog-Einträge übersprungen)', $skippedGlobal) : '',
        ));

        foreach ($perEntityErrors as $errMsg) {
            $this->addFlash('warning', 'Orphan-Repair übersprungen: ' . $errMsg);
        }
        return new RedirectResponse($this->generateUrl('app_quick_fix_index', ['fixed' => 1]));
    }

    /**
     * Fixes tenant mismatches (child entity tenant != parent entity tenant).
     * Each fix is audit-logged. Idempotent.
     */
    #[IsCsrfTokenValid('quick_fix_repair_mismatches')]
    public function repairTenantMismatches(
        Request $request,
        QuickFixGuard $guard,
        DataIntegrityService $dataIntegrity,
        EntityManagerInterface $em,
        AuditLogger $auditLogger,
    ): Response {
        if (!$guard->mayAccess($request)) {
            return $this->render('quick_fix/locked.html.twig', [
                'token_required' => $guard->isTokenRequired(),
            ], new Response('', Response::HTTP_FORBIDDEN));
        }

        $filters = $em->getFilters();
        $wasEnabled = $filters->isEnabled('tenant_filter');
        if ($wasEnabled) {
            $filters->disable('tenant_filter');
        }

        $fixed = 0;
        try {
            $broken = $dataIntegrity->findBrokenReferences();
            foreach ($broken as $ref) {
                $entityClass = $ref['entity_class'] ?? null;
                $entityId = $ref['entity_id'] ?? null;
                $parentTenant = $ref['expected_tenant'] ?? null;

                if ($entityClass === null || $entityId === null || $parentTenant === null) {
                    continue;
                }

                $entity = $em->find($entityClass, $entityId);
                if ($entity === null || !method_exists($entity, 'setTenant') || !method_exists($entity, 'getTenant')) {
                    continue;
                }

                $previousTenant = $entity->getTenant();
                $entity->setTenant($parentTenant);
                $auditLogger->logCustom(
                    'quick_fix.repair.tenant_mismatch_fixed',
                    (new \ReflectionClass($entity))->getShortName(),
                    (int) $entityId,
                    ['tenant_id' => $previousTenant?->getId()],
                    ['tenant_id' => $parentTenant->getId(), 'tenant_name' => $parentTenant->getName()],
                    sprintf(
                        'QuickFix: %s#%d tenant aligned to parent (tenant %d → %d)',
                        (new \ReflectionClass($entity))->getShortName(),
                        (int) $entityId,
                        (int) ($previousTenant?->getId() ?? 0),
                        (int) $parentTenant->getId(),
                    ),
                );
                $fixed++;
            }
            if ($em->isOpen()) {
                try { $em->flush(); } catch (\Throwable $e) {
                    $this->addFlash('warning', 'Flush nach Mismatch-Repair partial: ' . $e->getMessage());
                }
            }
        } finally {
            if ($wasEnabled) {
                $filters->enable('tenant_filter');
            }
        }

        $this->addFlash('success', sprintf('%d Mandant-Fehlzuordnung(en) behoben.', $fixed));
        return new RedirectResponse($this->generateUrl('app_quick_fix_index', ['fixed' => 1]));
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
        DataIntegrityService $dataIntegrity,
        EntityManagerInterface $em,
        AuditLogger $auditLogger,
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

        $filters = $em->getFilters();
        $wasEnabled = $filters->isEnabled('tenant_filter');
        if ($wasEnabled) {
            $filters->disable('tenant_filter');
        }

        $deleted = 0;
        try {
            $deleted = $dataIntegrity->mergeDuplicates($entityType);
            $auditLogger->logCustom(
                'quick_fix.repair.duplicates_merged',
                $entityType,
                0,
                [],
                ['entity_type' => $entityType, 'deleted_count' => $deleted],
                sprintf('QuickFix: merged duplicates for %s — %d record(s) deleted.', $entityType, $deleted),
            );
        } finally {
            if ($wasEnabled) {
                $filters->enable('tenant_filter');
            }
        }

        $this->addFlash('success', sprintf('%d Duplikat(e) aus "%s" bereinigt.', $deleted, $entityType));
        return new RedirectResponse($this->generateUrl('app_quick_fix_index', ['fixed' => 1]));
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
        DataIntegrityService $dataIntegrity,
        EntityManagerInterface $em,
        AuditLogger $auditLogger,
    ): Response {
        if (!$guard->mayAccess($request)) {
            return $this->render('quick_fix/locked.html.twig', [
                'token_required' => $guard->isTokenRequired(),
            ], new Response('', Response::HTTP_FORBIDDEN));
        }

        $filters = $em->getFilters();
        $wasEnabled = $filters->isEnabled('tenant_filter');
        if ($wasEnabled) {
            $filters->disable('tenant_filter');
        }

        $totalOrphans = 0;
        $totalMismatches = 0;
        $totalDuplicates = 0;
        $totalSkippedGlobal = 0;
        $allEntityErrors = [];

        try {
            // Step 1 — Orphans
            $tenantRepo = $em->getRepository(\App\Entity\Tenant::class);
            /** @var \App\Entity\Tenant|null $targetTenant */
            $targetTenant = $tenantRepo->findOneBy([]);
            if ($targetTenant !== null) {
                // Entities with tenant_id=NULL by design (global catalogues) must
                // never be reassigned — doing so causes UniqueConstraintViolationException
                // when multiple seeded rows share the same unique key.
                $globalClasses = $dataIntegrity->getGlobalCatalogueEntityClasses();

                $orphaned = $dataIntegrity->findAllOrphanedEntities();
                foreach ($orphaned as $type => $entities) {
                    foreach ($entities as $entity) {
                        if (!method_exists($entity, 'setTenant') || !method_exists($entity, 'getId')) {
                            continue;
                        }

                        // Safety guard: skip globally-scoped catalogue entities.
                        if (in_array($entity::class, $globalClasses, true)) {
                            $totalSkippedGlobal++;
                            continue;
                        }

                        try {
                            $entity->setTenant($targetTenant);
                            $auditLogger->logCustom(
                                'quick_fix.repair.orphan_assigned',
                                (new \ReflectionClass($entity))->getShortName(),
                                (int) $entity->getId(),
                                ['tenant_id' => null],
                                ['tenant_id' => $targetTenant->getId()],
                                sprintf('QuickFix/all: orphan %s#%d → tenant %d', (new \ReflectionClass($entity))->getShortName(), (int) $entity->getId(), (int) $targetTenant->getId()),
                            );
                            $totalOrphans++;
                        } catch (\Throwable $e) {
                            $em->refresh($entity);
                            $allEntityErrors[] = sprintf(
                                '%s#%d: %s',
                                (new \ReflectionClass($entity))->getShortName(),
                                (int) $entity->getId(),
                                $e->getMessage(),
                            );
                        }
                    }
                }
                if ($totalOrphans > 0 && $em->isOpen()) {
                    try { $em->flush(); } catch (\Throwable $e) {
                        $allEntityErrors[] = 'Flush partial: ' . $e->getMessage();
                    }
                }
            }

            // Step 2 — Tenant mismatches
            $broken = $dataIntegrity->findBrokenReferences();
            foreach ($broken as $ref) {
                $entityClass = $ref['entity_class'] ?? null;
                $entityId = $ref['entity_id'] ?? null;
                $parentTenant = $ref['expected_tenant'] ?? null;
                if ($entityClass === null || $entityId === null || $parentTenant === null) {
                    continue;
                }
                $entity = $em->find($entityClass, $entityId);
                if ($entity === null || !method_exists($entity, 'setTenant') || !method_exists($entity, 'getTenant')) {
                    continue;
                }
                $previousTenant = $entity->getTenant();
                $entity->setTenant($parentTenant);
                $auditLogger->logCustom(
                    'quick_fix.repair.tenant_mismatch_fixed',
                    (new \ReflectionClass($entity))->getShortName(),
                    (int) $entityId,
                    ['tenant_id' => $previousTenant?->getId()],
                    ['tenant_id' => $parentTenant->getId()],
                    sprintf('QuickFix/all: mismatch fix %s#%d', (new \ReflectionClass($entity))->getShortName(), (int) $entityId),
                );
                $totalMismatches++;
            }
            $em->flush();

            // Step 3 — Duplicates for all supported types
            foreach (['audits', 'assets', 'risks', 'incidents', 'documents'] as $type) {
                $deleted = $dataIntegrity->mergeDuplicates($type);
                $totalDuplicates += $deleted;
                if ($deleted > 0) {
                    $auditLogger->logCustom(
                        'quick_fix.repair.duplicates_merged',
                        $type,
                        0,
                        [],
                        ['deleted_count' => $deleted],
                        sprintf('QuickFix/all: merged %d duplicate(s) for %s', $deleted, $type),
                    );
                }
            }
        } finally {
            if ($wasEnabled) {
                $filters->enable('tenant_filter');
            }
        }

        $globalNote = $totalSkippedGlobal > 0
            ? sprintf(', %d globale Katalog-Einträge übersprungen', $totalSkippedGlobal)
            : '';

        $this->addFlash('success', sprintf(
            'Alle Repairs abgeschlossen: %d Orphan(s) zugewiesen, %d Mismatch(es) behoben, %d Duplikat(e) bereinigt%s.',
            $totalOrphans,
            $totalMismatches,
            $totalDuplicates,
            $globalNote,
        ));

        foreach ($allEntityErrors as $errMsg) {
            $this->addFlash('warning', 'Orphan-Repair übersprungen: ' . $errMsg);
        }

        return new RedirectResponse($this->generateUrl('app_quick_fix_index', ['fixed' => 1]));
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
        SchemaMaintenanceService $maintenance,
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

        $result = $maintenance->forceSchemaUpdate('quick-fix');

        if ($result['success']) {
            $this->addFlash('success', sprintf(
                'Schema-Update erfolgreich: %d Statement(s) angewendet.',
                $result['statements_executed'],
            ));
        } else {
            $this->addFlash('error', sprintf('Schema-Update erzwingen fehlgeschlagen: %s', $result['error'] ?? 'unbekannter Fehler'));
        }

        return new RedirectResponse($this->generateUrl('app_quick_fix_index', ['fixed' => 1]));
    }
}
