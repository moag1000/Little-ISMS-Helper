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
        $driftCount = 0;
        $driftStatements = [];
        $driftDestructive = [];
        $errorMessage = null;
        try {
            $status = $maintenance->getMaintenanceStatus();
            $pendingCount = (int) ($status['migration_status']['pending'] ?? 0);
            $driftCount = (int) ($status['schema_drift']['count'] ?? 0);
            $driftStatements = $status['schema_drift']['statements'] ?? [];
            $driftDestructive = $status['schema_drift']['destructive'] ?? [];
        } catch (\Throwable $e) {
            $errorMessage = $e->getMessage();
        }

        // Data integrity counts for Operator UI.
        // Failures here are non-fatal — schema may be broken, so integrity
        // checks could throw. We show zeros in that case.
        $orphanCount = 0;
        $orphansByType = [];
        $mismatchCount = 0;
        $duplicateCounts = [];
        try {
            $orphaned = $dataIntegrity->findAllOrphanedEntities();
            foreach ($orphaned as $type => $entities) {
                $cnt = count($entities);
                $orphansByType[$type] = $cnt;
                $orphanCount += $cnt;
            }
            $broken = $dataIntegrity->findBrokenReferences();
            $mismatchCount = count($broken);
            $allDuplicates = $dataIntegrity->findDuplicateEntities();
            foreach ($allDuplicates as $type => $groups) {
                $duplicateCounts[$type] = count($groups);
            }
        } catch (\Throwable) {
            // non-fatal — display zeros, do not override $errorMessage from above
        }

        $response = $this->render('quick_fix/index.html.twig', [
            'pending_count' => $pendingCount,
            'drift_count' => $driftCount,
            'drift_statements' => $driftStatements,
            'drift_destructive' => $driftDestructive,
            'error_message' => $errorMessage,
            // Data integrity
            'orphan_count' => $orphanCount,
            'orphans_by_type' => $orphansByType,
            'mismatch_count' => $mismatchCount,
            'duplicate_counts' => $duplicateCounts,
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

        if (!$migrationResult['success']) {
            $diagnosis = $migrationResult['diagnosis'] ?? null;
            if (is_array($diagnosis) && ($diagnosis['category'] ?? 'unknown') !== 'unknown') {
                $this->addFlash('error', sprintf(
                    'Migration failed [%s]: %s — %s',
                    $diagnosis['category'],
                    $diagnosis['message'] ?? '',
                    $diagnosis['suggested_action'] ?? '',
                ));
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

        // Refuse when destructive drift exists — those need manual review.
        try {
            $status = $maintenance->getMaintenanceStatus();
            $destructive = $status['schema_drift']['destructive'] ?? [];
        } catch (\Throwable $e) {
            $this->addFlash('error', sprintf('Status-Abfrage fehlgeschlagen: %s', $e->getMessage()));
            return new RedirectResponse($this->generateUrl('app_quick_fix_index'));
        }

        if ($destructive !== []) {
            $this->addFlash('error', 'Destruktive Schema-Änderungen erkannt. Bitte manuell prüfen via CLI: php bin/console app:schema:reconcile');
            return new RedirectResponse($this->generateUrl('app_quick_fix_index'));
        }

        $result = $maintenance->reconcileSchema('quick-fix');

        if (!$result['success']) {
            $this->addFlash('error', sprintf('Schema-Reconcile fehlgeschlagen: %s', $result['error'] ?? ($result['blocked'] ?? 'unbekannter Fehler')));
            return new RedirectResponse($this->generateUrl('app_quick_fix_index'));
        }

        $this->addFlash('success', sprintf('%d Schema-Statement(s) erfolgreich angewendet.', $result['executed']));
        return new RedirectResponse($this->generateUrl('app_quick_fix_index', ['fixed' => 1]));
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
        try {
            // Pick the first tenant as target — in single-tenant deployments
            // this is always the correct one. In multi-tenant we still assign
            // to the first to make the data visible; admins can re-assign via
            // the full admin/data-repair UI once the app is accessible again.
            $tenantRepo = $em->getRepository(\App\Entity\Tenant::class);
            /** @var \App\Entity\Tenant|null $targetTenant */
            $targetTenant = $tenantRepo->findOneBy([]);

            if ($targetTenant !== null) {
                $orphaned = $dataIntegrity->findAllOrphanedEntities();
                foreach ($orphaned as $type => $entities) {
                    foreach ($entities as $entity) {
                        if (!method_exists($entity, 'setTenant') || !method_exists($entity, 'getId')) {
                            continue;
                        }
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
                    }
                }
                $em->flush();
            }
        } finally {
            if ($wasEnabled) {
                $filters->enable('tenant_filter');
            }
        }

        $this->addFlash('success', sprintf(
            '%d verwaiste Entität(en) dem Standard-Mandanten zugewiesen.',
            $fixed,
        ));
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
            $em->flush();
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

        try {
            // Step 1 — Orphans
            $tenantRepo = $em->getRepository(\App\Entity\Tenant::class);
            /** @var \App\Entity\Tenant|null $targetTenant */
            $targetTenant = $tenantRepo->findOneBy([]);
            if ($targetTenant !== null) {
                $orphaned = $dataIntegrity->findAllOrphanedEntities();
                foreach ($orphaned as $type => $entities) {
                    foreach ($entities as $entity) {
                        if (!method_exists($entity, 'setTenant') || !method_exists($entity, 'getId')) {
                            continue;
                        }
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
                    }
                }
                $em->flush();
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

        $this->addFlash('success', sprintf(
            'Alle Repairs abgeschlossen: %d Orphan(s) zugewiesen, %d Mismatch(es) behoben, %d Duplikat(e) bereinigt.',
            $totalOrphans,
            $totalMismatches,
            $totalDuplicates,
        ));
        return new RedirectResponse($this->generateUrl('app_quick_fix_index', ['fixed' => 1]));
    }
}
