<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Job\AssignOrphansJob;
use App\Job\ExecutePendingMigrationsJob;
use App\Job\FixAllOrphansJob;
use App\Job\FixTenantMismatchesJob;
use App\Job\MergeDuplicatesJob;
use App\Job\ReassignEntityJob;
use App\Job\ReconcileSchemaJob;
use App\Job\RunFullIntegrityCheckJob;
use App\Job\ScanBrokenReferencesJob;
use App\Job\ScanDuplicatesJob;
use App\Job\ScanHealthIssuesJob;
use App\Job\ScanOrphansJob;
use App\Repository\AssetRepository;
use App\Repository\RiskRepository;
use App\Repository\IncidentRepository;
use App\Repository\TenantRepository;
use App\Repository\ControlRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Security\Voter\TenantScopedAdminVoter;
use App\Service\AuditLogger;
use App\Service\DataIntegrityResultCache;
use App\Service\DataIntegrityService;
use App\Service\Job\JobDispatcher;
use App\Service\Job\JobStatusService;
use App\Service\SchemaMaintenanceService;
use App\Service\SectionScanResultCache;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Admin UI for data repair / integrity / schema operations.
 *
 * Role-Scope (Phase 4e — system-settings cluster):
 *  - Class-level {@see TenantScopedAdminVoter::ADMIN_OWN_TENANT} — tenant
 *    admins repair orphans / duplicates / tenant-mismatches inside their
 *    own tenant tree (`W own orphans/dupes` per spec §3.1).
 *  - Schema-level routes (`/schema/migrations`, `/schema/reconcile`) AND
 *    cross-tenant duplicate merging are upgraded to
 *    {@see TenantScopedAdminVoter::ADMIN_GLOBAL_OP} / ROLE_SUPER_ADMIN
 *    — these touch global DDL or merge records across tenants and must
 *    not be reachable by a tenant-scoped admin.
 */
#[IsGranted(TenantScopedAdminVoter::ADMIN_OWN_TENANT)]
class DataRepairController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private readonly AssetRepository $assetRepository,
        private readonly RiskRepository $riskRepository,
        private readonly IncidentRepository $incidentRepository,
        private readonly TenantRepository $tenantRepository,
        private readonly ControlRepository $controlRepository,
        private readonly ComplianceRequirementRepository $complianceRequirementRepository,
        private readonly DataIntegrityService $dataIntegrityService,
        private readonly TranslatorInterface $translator,
        private readonly AuditLogger $auditLogger,
        private readonly SchemaMaintenanceService $schemaMaintenanceService,
        private readonly \Doctrine\Persistence\ManagerRegistry $managerRegistry,
        private readonly JobDispatcher $jobDispatcher,
        private readonly JobStatusService $jobStatusService,
        private readonly DataIntegrityResultCache $integrityResultCache,
        private readonly SectionScanResultCache $sectionScanCache,
    ) {
    }

    /**
     * Reset the EM if a prior flush closed it. Used between bulk-repair
     * iterations so a single constraint violation doesn't kill the whole loop.
     */
    private function resetEntityManagerIfClosed(): void
    {
        if (!$this->entityManager->isOpen()) {
            $this->managerRegistry->resetManager();
            $em = $this->managerRegistry->getManager();
            if ($em instanceof EntityManagerInterface) {
                $this->entityManager = $em;
            }
        }
    }

    /**
     * Mappt URL-Type-Slug (z.B. 'asset', 'risk', 'control') auf den
     * passenden FQCN anhand der Doctrine-Metadatas. Verzicht auf manuelle
     * Liste — neue Entity-Klassen sind automatisch repair-fähig.
     */
    private function resolveEntityClassForType(string $type): ?string
    {
        $slug = strtolower(str_replace('_', '', $type));
        foreach ($this->entityManager->getMetadataFactory()->getAllMetadata() as $metadata) {
            if ($metadata->isMappedSuperclass || $metadata->isEmbeddedClass) {
                continue;
            }
            $short = strtolower((new \ReflectionClass($metadata->getName()))->getShortName());
            // Singular- und Plural-Vergleich (asset/assets → Asset)
            if ($short === $slug || $short . 's' === $slug || $short === rtrim($slug, 's')) {
                return $metadata->getName();
            }
        }
        return null;
    }

    /**
     * Schaltet den TenantFilter für die Dauer des Callbacks aus und restauriert ihn
     * danach in jedem Fall. Ohne das kombiniert Doctrine "WHERE tenant IS NULL" mit
     * dem impliziten "AND tenant_id = :current" → 0 Resultate → Repair-Flow
     * findet seine Orphans nicht.
     */
    private function withoutTenantFilter(callable $fn): mixed
    {
        $filters = $this->entityManager->getFilters();
        $wasEnabled = $filters->isEnabled('tenant_filter');
        if ($wasEnabled) {
            $filters->disable('tenant_filter');
        }
        try {
            return $fn();
        } finally {
            if ($wasEnabled) {
                $filters->enable('tenant_filter');
            }
        }
    }

    /**
     * Cache-only index page.
     *
     * Previously the controller ran a full integrity scan + several findAll()
     * sweeps + 4 health-check methods synchronously on every GET, which on
     * tenants with > ~50k entities pushed past PHP-FPM's 30 s timeout. The
     * page now renders <500 ms regardless of data size by reading scalar
     * counts from per-section JSON caches written by background jobs:
     *
     *   - var/data_integrity/last.json         (full integrity summary)
     *   - var/data_integrity/orphans.json
     *   - var/data_integrity/duplicates.json
     *   - var/data_integrity/broken_references.json
     *   - var/data_integrity/health.json
     *
     * Live data only stays on this page for the schema-maintenance card
     * (Doctrine migration backlog + drift), which is cheap, and for a
     * tenant-status card that uses scalar COUNT queries. Every section
     * that previously rendered entity lists has moved to its own
     * `/admin/data-repair/<section>` sub-page that loads scoped data and
     * exposes a "Refresh now" CTA dispatching a section-specific scan
     * job.
     */
    #[Route('/admin/data-repair', name: 'admin_data_repair_index', methods: ['GET'])]
    public function index(): Response
    {
        // Schema maintenance: Doctrine migration backlog + entity-vs-DB drift.
        // Both are read-only here; cheap on every render (a single SQL probe).
        $maintenance = $this->schemaMaintenanceService->getMaintenanceStatus();

        // Cheap COUNT(t.id) — sub-millisecond on any realistic deployment.
        $tenantCount = (int) $this->tenantRepository->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->getQuery()
            ->getSingleScalarResult();

        return $this->render('admin/data_repair/index.html.twig', [
            // Schema maintenance status (3-card grid in template)
            'migration_status' => $maintenance['migration_status'],
            'schema_drift' => $maintenance['schema_drift'],

            // Async-integrity-check (Phase 2.5): scalar summary written by
            // RunFullIntegrityCheckJob to var/data_integrity/last.json.
            'integrityResultCache' => $this->integrityResultCache->read(),

            // Per-section caches written by ScanOrphansJob /
            // ScanDuplicatesJob / ScanBrokenReferencesJob /
            // ScanHealthIssuesJob. Keys: 'orphans', 'duplicates',
            // 'broken_references', 'health'. Each value is either null
            // (never scanned) or an envelope with `completed_at`,
            // `duration_ms` and a section-specific `payload`.
            'sectionCaches' => $this->sectionScanCache->readAll(),

            // Tenant-status card — cheap COUNT(*) result so the index
            // page can show "N tenants in this deployment" without
            // loading every tenant entity.
            'tenantCount' => $tenantCount,
        ]);
    }

    // ====================================================================
    // Section sub-pages — each renders from its own per-section cache and
    // exposes a "Refresh now" CTA that dispatches a scoped scan job.
    // ====================================================================

    /**
     * Orphan-entity sub-page. Reads only the orphan cache + the dropdown
     * sources needed for the per-entity reassign forms. The live
     * findAll() sweeps are scoped to this sub-page so they no longer
     * block the index render.
     */
    #[Route('/admin/data-repair/orphans', name: 'admin_data_repair_orphans', methods: ['GET'])]
    public function orphans(): Response
    {
        $tenants = $this->tenantRepository->findAll();

        // Live data for the repair-action dropdowns — without this the
        // reassign forms can't render. Scoped to this sub-page only;
        // never visited from the main index after the refactor.
        $orphaned = $this->withoutTenantFilter(
            fn() => $this->dataIntegrityService->findAllOrphanedEntities()
        );

        return $this->render('admin/data_repair/orphans.html.twig', [
            'cache' => $this->sectionScanCache->read(SectionScanResultCache::SECTION_ORPHANS),
            'tenants' => $tenants,
            'orphanedEntities' => $orphaned,
            'orphanedAssets' => $orphaned['assets'] ?? [],
            'orphanedRisks' => $orphaned['risks'] ?? [],
            'orphanedIncidents' => $orphaned['incidents'] ?? [],
        ]);
    }

    /**
     * Duplicate-detection sub-page.
     */
    #[Route('/admin/data-repair/duplicates', name: 'admin_data_repair_duplicates_index', methods: ['GET'])]
    public function duplicates(): Response
    {
        $duplicates = $this->withoutTenantFilter(
            fn() => $this->dataIntegrityService->findDuplicateEntities()
        );

        return $this->render('admin/data_repair/duplicates.html.twig', [
            'cache' => $this->sectionScanCache->read(SectionScanResultCache::SECTION_DUPLICATES),
            'duplicates' => $duplicates,
        ]);
    }

    /**
     * Broken-references sub-page (broken FKs + cascade orphans + orphaned uploads).
     */
    #[Route('/admin/data-repair/broken-references', name: 'admin_data_repair_broken_refs_index', methods: ['GET'])]
    public function brokenReferences(): Response
    {
        $broken = $this->withoutTenantFilter(
            fn() => $this->dataIntegrityService->findBrokenReferences()
        );
        $cascade = $this->withoutTenantFilter(
            fn() => $this->dataIntegrityService->findCascadeOrphans()
        );
        $uploads = $this->withoutTenantFilter(
            fn() => $this->dataIntegrityService->findOrphanedUploads()
        );

        return $this->render('admin/data_repair/broken_references.html.twig', [
            'cache' => $this->sectionScanCache->read(SectionScanResultCache::SECTION_BROKEN_REFERENCES),
            'brokenReferences' => $broken,
            'cascadeOrphans' => $cascade,
            'orphanedUploads' => $uploads,
        ]);
    }

    /**
     * Health-issues sub-page (4 buckets: risk / compliance / operational / data-quality).
     */
    #[Route('/admin/data-repair/health', name: 'admin_data_repair_health_index', methods: ['GET'])]
    public function health(): Response
    {
        return $this->render('admin/data_repair/health.html.twig', [
            'cache' => $this->sectionScanCache->read(SectionScanResultCache::SECTION_HEALTH),
        ]);
    }

    /**
     * Refresh-now CTA for the orphan sub-page. Dispatches {@see ScanOrphansJob}
     * via the same in-request runner used by the other admin jobs.
     */
    #[Route('/admin/data-repair/orphans/refresh', name: 'admin_data_repair_orphans_refresh', methods: ['POST'])]
    public function refreshOrphans(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('refresh_section_orphans', $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('common.csrf_error', [], 'messages'));
            return $this->redirectToRoute('admin_data_repair_orphans');
        }

        return $this->dispatchSectionScan(
            $request,
            ScanOrphansJob::class,
            'admin.data_repair.scan_orphans',
            'admin.data_repair.job.scan_orphans_label',
            'admin.data_repair.job.scan_orphans_subtitle',
            $this->generateUrl('admin_data_repair_orphans'),
        );
    }

    #[Route('/admin/data-repair/duplicates/refresh', name: 'admin_data_repair_duplicates_refresh', methods: ['POST'])]
    public function refreshDuplicates(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('refresh_section_duplicates', $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('common.csrf_error', [], 'messages'));
            return $this->redirectToRoute('admin_data_repair_duplicates_index');
        }

        return $this->dispatchSectionScan(
            $request,
            ScanDuplicatesJob::class,
            'admin.data_repair.scan_duplicates',
            'admin.data_repair.job.scan_duplicates_label',
            'admin.data_repair.job.scan_duplicates_subtitle',
            $this->generateUrl('admin_data_repair_duplicates_index'),
        );
    }

    #[Route('/admin/data-repair/broken-references/refresh', name: 'admin_data_repair_broken_refs_refresh', methods: ['POST'])]
    public function refreshBrokenReferences(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('refresh_section_broken_refs', $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('common.csrf_error', [], 'messages'));
            return $this->redirectToRoute('admin_data_repair_broken_refs_index');
        }

        return $this->dispatchSectionScan(
            $request,
            ScanBrokenReferencesJob::class,
            'admin.data_repair.scan_broken_refs',
            'admin.data_repair.job.scan_broken_refs_label',
            'admin.data_repair.job.scan_broken_refs_subtitle',
            $this->generateUrl('admin_data_repair_broken_refs_index'),
        );
    }

    #[Route('/admin/data-repair/health/refresh', name: 'admin_data_repair_health_refresh', methods: ['POST'])]
    public function refreshHealth(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('refresh_section_health', $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('common.csrf_error', [], 'messages'));
            return $this->redirectToRoute('admin_data_repair_health_index');
        }

        return $this->dispatchSectionScan(
            $request,
            ScanHealthIssuesJob::class,
            'admin.data_repair.scan_health',
            'admin.data_repair.job.scan_health_label',
            'admin.data_repair.job.scan_health_subtitle',
            $this->generateUrl('admin_data_repair_health_index'),
        );
    }

    /**
     * Shared dispatch shim for the four section-scan refresh routes.
     * Creates a JobStatusService row with payload-embedded UI metadata
     * (label/subtitle) and redirects (303) to the shared progress page —
     * the PRG pattern required by Hotwire Turbo. JobDispatcher flushes
     * the redirect before running the job in-request.
     *
     * @param class-string<\App\Job\AsyncJobInterface> $jobClass
     */
    private function dispatchSectionScan(
        Request $request,
        string $jobClass,
        string $jobName,
        string $jobLabelKey,
        string $jobSubtitleKey,
        string $cancelUrl,
    ): Response {
        $jobId = $this->jobStatusService->create($jobName, [
            '_label' => $this->translator->trans($jobLabelKey, [], 'admin'),
            '_subtitle' => $this->translator->trans($jobSubtitleKey, [], 'admin'),
        ]);

        $response = $this->redirectToRoute('admin_job_progress_page', [
            'id'     => $jobId,
            'return' => $cancelUrl,
        ], Response::HTTP_SEE_OTHER);

        return $this->jobDispatcher->dispatch(
            $jobClass,
            [],
            $jobId,
            $response,
            $request->getSession(),
        );
    }

    /**
     * Dispatches the full integrity check as an async job.
     *
     * Originally part of {@see self::index()} — running synchronously on every
     * GET could push past PHP-FPM's 30 s limit on large tenant trees because
     * `runFullIntegrityCheck()` loads every Doctrine-mapped entity that owns
     * a tenant_id column plus all duplicate / broken-ref / file-orphan /
     * JSON-schema / audit-integrity / status-enum-drift checks.
     *
     * The worker persists a scalar summary via {@see DataIntegrityResultCache};
     * the index page reads it on subsequent visits.
     */
    #[Route('/admin/data-repair/run-integrity-check', name: 'admin_data_repair_run_integrity_check', methods: ['POST'])]
    public function runIntegrityCheck(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('run_integrity_check', $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('common.csrf_error', [], 'messages'));
            return $this->redirectToRoute('admin_data_repair_index');
        }

        $jobId = $this->jobStatusService->create(
            'admin.data_repair.run_integrity_check',
            [
                '_label' => $this->translator->trans('admin.data_repair.job.run_integrity_check_label', [], 'admin'),
                '_subtitle' => $this->translator->trans('admin.data_repair.job.run_integrity_check_subtitle', [], 'admin'),
            ],
        );

        // PRG: 303 redirect to the shared progress page — required for Turbo.
        // InRequestJobRunner flushes the redirect BEFORE running the job, and
        // markRunning() is called inside dispatch() before detach() — so by
        // the time the browser GETs /admin/jobs/{id}/progress, status is
        // already `running` and the polling card never flashes `pending`.
        $response = $this->redirectToRoute('admin_job_progress_page', [
            'id'     => $jobId,
            'return' => $this->generateUrl('admin_data_repair_index'),
        ], Response::HTTP_SEE_OTHER);

        return $this->jobDispatcher->dispatch(
            RunFullIntegrityCheckJob::class,
            [],
            $jobId,
            $response,
            $request->getSession(),
        );
    }

    /**
     * Bulk-assigns orphaned entities (tenant_id IS NULL) to a target tenant.
     *
     * Was synchronous and looped {@see AuditLogger::logCustom()} per entity
     * (each call flushes the EM). On tenants with thousands of orphans this
     * exceeded the PHP-FPM 30 s timeout and the operator saw a blank page.
     *
     * Now dispatches {@see AssignOrphansJob} via {@see JobDispatcher} — the
     * worker batches flushes (50 entities per commit) and the polling page
     * surfaces live progress without blocking PHP-FPM.
     */
    #[Route('/admin/data-repair/assign-orphans', name: 'admin_data_repair_assign_orphans', methods: ['POST'])]
    public function assignOrphans(
        Request $request,
        #[CurrentUser] User $user,
    ): Response {
        if (!$this->isCsrfTokenValid('assign_orphans', $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('common.csrf_error', [], 'messages'));
            return $this->redirectToRoute('admin_data_repair_orphans');
        }

        $tenantId = (int) ($request->request->get('tenant_id') ?? 0);
        $entityType = (string) ($request->request->get('entity_type') ?? 'all');

        $tenant = $this->tenantRepository->find($tenantId);
        if ($tenant === null) {
            $this->addFlash('error', $this->translator->trans('admin.data_repair.tenant_not_found', [], 'admin'));
            return $this->redirectToRoute('admin_data_repair_orphans');
        }

        $jobId = $this->jobStatusService->create(
            'admin.data_repair.assign_orphans',
            [
                'tenantId' => $tenantId,
                'tenantName' => $tenant->getName(),
                'entityType' => $entityType,
                '_label' => $this->translator->trans('admin.data_repair.job.assign_orphans_label', [
                    '%tenant%' => $tenant->getName(),
                ], 'admin'),
                '_subtitle' => $this->translator->trans('admin.data_repair.job.assign_orphans_subtitle', [], 'admin'),
            ],
        );

        // PRG: 303 redirect to the shared progress page — see runIntegrityCheck() rationale.
        $response = $this->redirectToRoute('admin_job_progress_page', [
            'id'     => $jobId,
            'return' => $this->generateUrl('admin_data_repair_orphans'),
        ], Response::HTTP_SEE_OTHER);

        return $this->jobDispatcher->dispatch(
            AssignOrphansJob::class,
            [
                'tenantId' => $tenantId,
                'entityType' => $entityType,
                'userId' => $user->getId(),
            ],
            $jobId,
            $response,
            $request->getSession(),
        );
    }

    /**
     * Reassign a single orphan entity to a tenant.
     *
     * Was synchronous: one flush + one audit-log flush per request. On slow
     * shared-hosting MySQL (fsync-heavy disk) the operator could still wait
     * 10–30 s, which felt indistinguishable from the bulk-assign timeout.
     *
     * Now dispatches {@see ReassignEntityJob} — the polling page renders
     * immediately and the job refreshes the orphan-cache so the index page
     * reflects the new state when the operator returns.
     */
    #[Route('/admin/data-repair/reassign-entity/{type}/{id}', name: 'admin_data_repair_reassign_entity', methods: ['POST'])]
    public function reassignEntity(
        Request $request,
        string $type,
        int $id,
        #[CurrentUser] User $user,
    ): Response {
        if (!$this->isCsrfTokenValid('reassign_entity_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('common.csrf_error', [], 'messages'));
            return $this->redirectToRoute('admin_data_repair_orphans');
        }

        $tenantId = (int) ($request->request->get('tenant_id') ?? 0);
        $tenant = $this->tenantRepository->find($tenantId);
        if ($tenant === null) {
            $this->addFlash('error', $this->translator->trans('admin.data_repair.tenant_not_found', [], 'admin'));
            return $this->redirectToRoute('admin_data_repair_orphans');
        }

        // Verify the entity type slug maps to a real Doctrine entity before
        // dispatching — saves the operator a round-trip to the job-progress
        // page if they submitted a garbage URL.
        if ($this->resolveEntityClassForType($type) === null) {
            $this->addFlash('error', $this->translator->trans('admin.data_repair.invalid_entity_type', [], 'admin'));
            return $this->redirectToRoute('admin_data_repair_orphans');
        }

        $jobId = $this->jobStatusService->create(
            'admin.data_repair.reassign_entity',
            [
                'type' => $type,
                'id' => $id,
                'tenantId' => $tenantId,
                'tenantName' => $tenant->getName(),
                '_label' => $this->translator->trans('admin.data_repair.job.reassign_entity_label', [
                    '%type%' => $type,
                    '%id%' => $id,
                ], 'admin'),
                '_subtitle' => $this->translator->trans('admin.data_repair.job.reassign_entity_subtitle', [
                    '%tenant%' => $tenant->getName(),
                ], 'admin'),
            ],
        );

        // PRG: 303 redirect to the shared progress page — see runIntegrityCheck() rationale.
        $response = $this->redirectToRoute('admin_job_progress_page', [
            'id'     => $jobId,
            'return' => $this->generateUrl('admin_data_repair_orphans'),
        ], Response::HTTP_SEE_OTHER);

        return $this->jobDispatcher->dispatch(
            ReassignEntityJob::class,
            [
                'entityType' => $type,
                'entityId' => $id,
                'tenantId' => $tenantId,
                'userId' => $user->getId(),
            ],
            $jobId,
            $response,
            $request->getSession(),
        );
    }

    #[Route('/admin/data-repair/assign-asset/{type}/{id}', name: 'admin_data_repair_assign_asset', methods: ['POST'])]
    public function assignAsset(Request $request, string $type, int $id): Response
    {
        $assetId = $request->request->get('asset_id');

        if (!$this->isCsrfTokenValid('assign_asset_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('common.csrf_error', [], 'messages'));
            return $this->redirectToRoute('admin_data_repair_index');
        }

        if (!$assetId) {
            $this->addFlash('error', $this->translator->trans('admin.data_repair.select_asset', [], 'messages') ?: 'Bitte wählen Sie ein Asset aus.');
            return $this->redirectToRoute('admin_data_repair_index');
        }

        $asset = $this->assetRepository->find($assetId);
        if (!$asset) {
            $this->addFlash('error', $this->translator->trans('admin.data_repair.asset_not_found', [], 'messages') ?: 'Asset nicht gefunden.');
            return $this->redirectToRoute('admin_data_repair_index');
        }

        $entityName = '';

        switch ($type) {
            case 'risk':
                $entity = $this->riskRepository->find($id);
                if (!$entity) {
                    $this->addFlash('error', $this->translator->trans('admin.data_repair.entity_not_found', [], 'admin'));
                    return $this->redirectToRoute('admin_data_repair_index');
                }
                $previousAsset = $entity->getAsset();
                $previousAssetId = $previousAsset?->getId();
                $entity->setAsset($asset);
                $entityName = $entity->getTitle();
                $this->auditLogger->logCustom(
                    'admin.data_repair.asset_assigned',
                    'Risk',
                    $id,
                    ['asset_id' => $previousAssetId],
                    ['asset_id' => $asset->getId(), 'asset_name' => $asset->getName()],
                    sprintf('Risk#%d "%s" linked to Asset#%d "%s"', $id, $entityName, (int) $asset->getId(), $asset->getName()),
                );
                break;

            case 'incident':
                $entity = $this->incidentRepository->find($id);
                if (!$entity) {
                    $this->addFlash('error', $this->translator->trans('admin.data_repair.entity_not_found', [], 'admin'));
                    return $this->redirectToRoute('admin_data_repair_index');
                }
                // Incidents have ManyToMany relationship with assets
                $alreadyLinked = $entity->getAffectedAssets()->contains($asset);
                if (!$alreadyLinked) {
                    $entity->addAffectedAsset($asset);
                }
                $entityName = $entity->getTitle();
                $this->auditLogger->logCustom(
                    'admin.data_repair.asset_assigned',
                    'Incident',
                    $id,
                    ['affected_asset_linked' => $alreadyLinked],
                    ['asset_id' => $asset->getId(), 'asset_name' => $asset->getName(), 'affected_asset_linked' => true],
                    sprintf('Incident#%d "%s" gained affected asset Asset#%d "%s"', $id, $entityName, (int) $asset->getId(), $asset->getName()),
                );
                break;

            default:
                $this->addFlash('error', $this->translator->trans('admin.data_repair.invalid_entity_type', [], 'admin'));
                return $this->redirectToRoute('admin_data_repair_index');
        }

        $this->entityManager->flush();

        $this->addFlash('success', sprintf(
            '%s wurde erfolgreich dem Asset "%s" zugewiesen.',
            $entityName,
            $asset->getName()
        ));

        return $this->redirectToRoute('admin_data_repair_index');
    }

    #[Route('/admin/data-repair/assign-risk/{id}', name: 'admin_data_repair_assign_risk', methods: ['POST'])]
    public function assignRisk(Request $request, int $id): Response
    {
        $riskId = $request->request->get('risk_id');

        if (!$this->isCsrfTokenValid('assign_risk_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('common.csrf_error', [], 'messages'));
            return $this->redirectToRoute('admin_data_repair_index');
        }

        if (!$riskId) {
            $this->addFlash('error', $this->translator->trans('admin.data_repair.select_risk', [], 'admin'));
            return $this->redirectToRoute('admin_data_repair_index');
        }

        $control = $this->controlRepository->find($id);
        if (!$control) {
            $this->addFlash('error', $this->translator->trans('admin.data_repair.entity_not_found', [], 'admin'));
            return $this->redirectToRoute('admin_data_repair_index');
        }

        $risk = $this->riskRepository->find($riskId);
        if (!$risk) {
            $this->addFlash('error', $this->translator->trans('admin.data_repair.entity_not_found', [], 'admin'));
            return $this->redirectToRoute('admin_data_repair_index');
        }

        // Add risk to control
        $alreadyLinked = $control->getRisks()->contains($risk);
        $control->addRisk($risk);
        $this->auditLogger->logCustom(
            'admin.data_repair.risk_assigned',
            'Control',
            $id,
            ['risk_linked' => $alreadyLinked],
            ['risk_id' => $risk->getId(), 'risk_title' => $risk->getTitle(), 'risk_linked' => true],
            sprintf('Control#%d "%s" linked to Risk#%d "%s"', $id, $control->getName(), (int) $risk->getId(), $risk->getTitle()),
        );
        $this->entityManager->flush();

        $this->addFlash('success', sprintf(
            'Risiko "%s" wurde erfolgreich der Maßnahme "%s" zugeordnet.',
            $risk->getTitle(),
            $control->getName()
        ));

        return $this->redirectToRoute('admin_data_repair_index');
    }

    #[Route('/admin/data-repair/assign-asset-to-control/{id}', name: 'admin_data_repair_assign_asset_to_control', methods: ['POST'])]
    public function assignAssetToControl(Request $request, int $id): Response
    {
        $assetId = $request->request->get('asset_id');

        if (!$this->isCsrfTokenValid('assign_asset_to_control_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('common.csrf_error', [], 'messages'));
            return $this->redirectToRoute('admin_data_repair_index');
        }

        if (!$assetId) {
            $this->addFlash('error', $this->translator->trans('admin.data_repair.select_asset', [], 'admin'));
            return $this->redirectToRoute('admin_data_repair_index');
        }

        $control = $this->controlRepository->find($id);
        if (!$control) {
            $this->addFlash('error', $this->translator->trans('admin.data_repair.entity_not_found', [], 'admin'));
            return $this->redirectToRoute('admin_data_repair_index');
        }

        $asset = $this->assetRepository->find($assetId);
        if (!$asset) {
            $this->addFlash('error', $this->translator->trans('admin.data_repair.asset_not_found', [], 'admin'));
            return $this->redirectToRoute('admin_data_repair_index');
        }

        // Add asset to control's protected assets
        $alreadyLinked = $control->getProtectedAssets()->contains($asset);
        $control->addProtectedAsset($asset);
        $this->auditLogger->logCustom(
            'admin.data_repair.asset_to_control_assigned',
            'Control',
            $id,
            ['protected_asset_linked' => $alreadyLinked],
            ['asset_id' => $asset->getId(), 'asset_name' => $asset->getName(), 'protected_asset_linked' => true],
            sprintf('Control#%d "%s" now protects Asset#%d "%s"', $id, $control->getName(), (int) $asset->getId(), $asset->getName()),
        );
        $this->entityManager->flush();

        $this->addFlash('success', sprintf(
            'Asset "%s" wurde erfolgreich der Maßnahme "%s" zugeordnet.',
            $asset->getName(),
            $control->getName()
        ));

        return $this->redirectToRoute('admin_data_repair_index');
    }

    /**
     * Dispatches the fix-all-orphans job asynchronously via Symfony Messenger.
     *
     * Returns immediately with a progress-polling page. The actual work runs in
     * the worker process (messenger:consume async), avoiding PHP-FPM timeout.
     * CSRF-protected identically to the sync route.
     */
    #[Route('/admin/data-repair/fix-all-orphans-async/{tenantId}', name: 'admin_data_repair_fix_all_orphans_async', methods: ['POST'])]
    public function fixAllOrphansAsync(Request $request, int $tenantId): Response
    {
        if (!$this->isCsrfTokenValid('fix_all_orphans', $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('common.csrf_error', [], 'messages'));
            return $this->redirectToRoute('admin_data_repair_index');
        }

        $tenant = $this->tenantRepository->find($tenantId);
        if (!$tenant) {
            $this->addFlash('error', $this->translator->trans('admin.data_repair.tenant_not_found', [], 'admin'));
            return $this->redirectToRoute('admin_data_repair_index');
        }

        // Create job status record and dispatch
        $jobId = $this->jobStatusService->create(
            'admin.data_repair.fix_all_orphans',
            [
                'tenantId' => $tenantId,
                'tenantName' => $tenant->getName(),
                '_label' => sprintf(
                    '%s — %s',
                    $this->translator->trans('admin.data_repair.job.fix_all_orphans_label', [], 'admin'),
                    $tenant->getName(),
                ),
                '_subtitle' => $this->translator->trans(
                    'admin.data_repair.job.fix_all_orphans_subtitle',
                    ['%tenant%' => $tenant->getName()],
                    'admin',
                ),
            ],
        );

        // PRG: 303 redirect to the shared progress page — see runIntegrityCheck() rationale.
        $response = $this->redirectToRoute('admin_job_progress_page', [
            'id'     => $jobId,
            'return' => $this->generateUrl('admin_data_repair_index'),
        ], Response::HTTP_SEE_OTHER);

        return $this->jobDispatcher->dispatch(
            FixAllOrphansJob::class,
            ['tenantId' => $tenantId],
            $jobId,
            $response,
            $request->getSession(),
        );
    }

    /**
     * Bulk-assigns every orphaned entity (across all types) to the selected tenant.
     *
     * Consultant-Review A2 (docs/DB_REPAIR_REVIEW_CONSULTANT.md): this is a
     * DSGVO incident trigger in multi-tenant deployments — a misclick can
     * silently reassign assets/risks/incidents that belong to tenant A into
     * tenant B's namespace. The bulk path is therefore gated:
     *
     *   1. Rejected outright when more than one tenant exists. Admins must
     *      use the per-entity routes (assign-orphans, reassign-entity,
     *      assign-asset, assign-risk, assign-asset-to-control).
     *   2. Requires a second-layer confirm hash that matches the orphan
     *      count shown at preview time — a stale browser tab can't
     *      reassign more rows than the admin actually saw.
     *   3. Audit-logs every reassignment individually with the current
     *      actor_role (ISB Sprint-2 gate) before flush.
     */
    #[Route('/admin/data-repair/fix-all-orphans/{tenantId}', name: 'admin_data_repair_fix_all_orphans', methods: ['POST'])]
    public function fixAllOrphans(Request $request, int $tenantId): Response
    {
        if (!$this->isCsrfTokenValid('fix_all_orphans', $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('common.csrf_error', [], 'messages'));
            return $this->redirectToRoute('admin_data_repair_index');
        }

        // Guard 1: block bulk reassign in any multi-tenant deployment.
        $tenantCount = (int) $this->tenantRepository->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->getQuery()
            ->getSingleScalarResult();
        if ($tenantCount > 1) {
            $this->addFlash('danger', $this->translator->trans(
                'admin.data_repair.bulk_blocked_multi_tenant',
                ['%count%' => $tenantCount],
                'admin',
            ));
            return $this->redirectToRoute('admin_data_repair_index');
        }

        $tenant = $this->tenantRepository->find($tenantId);
        if (!$tenant) {
            $this->addFlash('error', $this->translator->trans('admin.data_repair.tenant_not_found', [], 'admin'));
            return $this->redirectToRoute('admin_data_repair_index');
        }

        // Guard 2 (confirm_hash-Drift-Check) entfernt — CSRF + JS-Bestätigungs-
        // Dialog reichen; der Hash war fragil, weil Orphan-Counts sich zwischen
        // Render und Submit ändern können (z.B. neue Imports) und der Nutzer
        // dann aus einer gültigen Aktion ausgeschlossen wird.
        $totalFixed = 0;
        $totalSkipped = 0;
        $this->withoutTenantFilter(function () use ($tenant, &$totalFixed, &$totalSkipped): void {
            $orphaned = $this->dataIntegrityService->findAllOrphanedEntities();

            foreach ($orphaned as $className => $entities) {
                foreach ($entities as $entity) {
                    if (!method_exists($entity, 'setTenant') || !method_exists($entity, 'getId')) {
                        continue;
                    }
                    // Guard: if a previous iteration's flush closed the EM
                    // (e.g. constraint violation), reset before continuing so
                    // remaining orphans can still be processed.
                    $this->resetEntityManagerIfClosed();
                    // Re-fetch tenant via repository so the entity attaches to
                    // the (possibly reset) EM. tenantRepository is autowired
                    // and resolves via the active EM.
                    $tenant = $this->tenantRepository->find($tenant->getId());
                    if (!$tenant) {
                        return;
                    }
                    try {
                        $entity->setTenant($tenant);
                        $this->auditLogger->logCustom(
                            'admin.data_repair.orphan_reassigned',
                            $className,
                            (int) $entity->getId(),
                            ['tenant_id' => null],
                            ['tenant_id' => $tenant->getId(), 'tenant_name' => $tenant->getName()],
                            sprintf('Orphan %s#%d reassigned to tenant %s', $className, (int) $entity->getId(), $tenant->getName()),
                        );
                        $this->entityManager->flush();
                        $totalFixed++;
                    } catch (\Throwable $e) {
                        $totalSkipped++;
                        // EM may be closed after constraint violation; next
                        // iteration's guard above will reset it.
                    }
                }
            }
        });

        $message = $this->translator->trans('admin.data_repair.fixed_all_orphans', [
            '%count%' => $totalFixed,
            '%tenant%' => $tenant->getName(),
        ], 'admin');
        if ($totalSkipped > 0) {
            $message .= ' · ' . $this->translator->trans('admin.data_repair.orphans_skipped', [
                '%count%' => $totalSkipped,
            ], 'admin');
        }
        $this->addFlash($totalSkipped > 0 ? 'warning' : 'success', $message);

        return $this->redirectToRoute('admin_data_repair_index');
    }

    /**
     * Resolves cross-entity tenant mismatches by forcing the child's tenant
     * to match its related Asset. ISB MINOR-5 / A.5.3: this is a judgement
     * call (could be a data leak OR a reparation) — therefore:
     *   - a reason ≥ 20 chars is mandatory,
     *   - every reassignment is audit-logged with the before/after tenant.
     *
     * Dispatches {@see FixTenantMismatchesJob} via Symfony Messenger so the
     * polling progress page replaces a blocking request that previously
     * risked the PHP-FPM 30 s timeout on large broken-reference lists.
     */
    #[Route('/admin/data-repair/fix-tenant-mismatches', name: 'admin_data_repair_fix_tenant_mismatches', methods: ['POST'])]
    public function fixTenantMismatches(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('fix_tenant_mismatches', $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('common.csrf_error', [], 'messages'));
            return $this->redirectToRoute('admin_data_repair_index');
        }

        $reason = trim((string) $request->request->get('reason', ''));
        if (mb_strlen($reason) < 20) {
            $this->addFlash('danger', $this->translator->trans(
                'admin.data_repair.reason_required',
                ['%min%' => 20, '%actual%' => mb_strlen($reason)],
                'admin',
            ));
            return $this->redirectToRoute('admin_data_repair_index');
        }

        $jobId = $this->jobStatusService->create(
            'admin.data_repair.fix_tenant_mismatches',
            [
                'reason_length' => mb_strlen($reason),
                '_label' => $this->translator->trans('admin.data_repair.job.fix_tenant_mismatches_label', [], 'admin'),
                '_subtitle' => $this->translator->trans('admin.data_repair.job.fix_tenant_mismatches_subtitle', [], 'admin'),
            ],
        );

        // PRG: 303 redirect to the shared progress page — see runIntegrityCheck() rationale.
        $response = $this->redirectToRoute('admin_job_progress_page', [
            'id'     => $jobId,
            'return' => $this->generateUrl('admin_data_repair_index'),
        ], Response::HTTP_SEE_OTHER);

        return $this->jobDispatcher->dispatch(
            FixTenantMismatchesJob::class,
            ['reason' => $reason],
            $jobId,
            $response,
            $request->getSession(),
        );
    }

    /**
     * Merges duplicate entities for a given entity type.
     * Keeps the entity with the lowest ID (oldest) and removes newer duplicates.
     *
     * Supported entity types: audits, assets, risks, incidents, documents
     *
     * Dispatches {@see MergeDuplicatesJob} via Symfony Messenger so very large
     * duplicate groups (>10 k rows on legacy imports) don't hit the PHP-FPM
     * 30 s timeout. Audit-log is written from the job.
     */
    #[Route('/admin/data-repair/fix-duplicates/{entityType}', name: 'admin_data_repair_fix_duplicates', methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function fixDuplicates(
        Request $request,
        string $entityType,
        #[CurrentUser] User $user,
    ): Response {
        $allowedTypes = ['audits', 'assets', 'risks', 'incidents', 'documents'];

        if (!$this->isCsrfTokenValid('fix_duplicates_' . $entityType, $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('common.csrf_error', [], 'messages'));
            return $this->redirectToRoute('admin_data_repair_index');
        }

        if (!in_array($entityType, $allowedTypes, true)) {
            $this->addFlash('error', $this->translator->trans('admin.data_repair.invalid_entity_type', [], 'admin'));
            return $this->redirectToRoute('admin_data_repair_index');
        }

        $actor = (string) ($user->getEmail() ?? 'admin');

        $jobId = $this->jobStatusService->create(
            'admin.data_repair.merge_duplicates',
            [
                'entityType' => $entityType,
                'actor' => $actor,
                '_label' => $this->translator->trans('admin.data_repair.job.merge_duplicates_label', ['%type%' => $entityType], 'admin'),
                '_subtitle' => $this->translator->trans('admin.data_repair.job.merge_duplicates_subtitle', [], 'admin'),
            ],
        );

        // PRG: 303 redirect to the shared progress page — see runIntegrityCheck() rationale.
        $response = $this->redirectToRoute('admin_job_progress_page', [
            'id'     => $jobId,
            'return' => $this->generateUrl('admin_data_repair_index'),
        ], Response::HTTP_SEE_OTHER);

        return $this->jobDispatcher->dispatch(
            MergeDuplicatesJob::class,
            ['entityType' => $entityType, 'actor' => $actor],
            $jobId,
            $response,
            $request->getSession(),
        );
    }

    /**
     * Executes every pending Doctrine migration. Idempotent — when no
     * migration is pending the route returns a neutral flash and the
     * Migrator is not even spun up.
     *
     * Audit-log + ISB visibility live in
     * {@see SchemaMaintenanceService::executePendingMigrations()}.
     */
    #[Route('/admin/data-repair/schema/migrations', name: 'admin_data_repair_migrations_execute', methods: ['POST'])]
    #[IsGranted(TenantScopedAdminVoter::ADMIN_GLOBAL_OP)]
    public function executeMigrations(
        Request $request,
        #[CurrentUser] User $user,
    ): Response {
        if (!$this->isCsrfTokenValid('migrations_execute', (string) $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('common.csrf_error', [], 'messages'));
            return $this->redirectToRoute('admin_data_repair_index');
        }

        $actor = (string) ($user->getEmail() ?? 'admin');

        $jobId = $this->jobStatusService->create(
            'admin.data_repair.execute_migrations',
            [
                'actor' => $actor,
                '_label' => $this->translator->trans('admin.data_repair.job.execute_migrations_label', [], 'admin'),
                '_subtitle' => $this->translator->trans('admin.data_repair.job.execute_migrations_subtitle', [], 'admin'),
            ],
        );

        // PRG: 303 redirect to the shared progress page — see runIntegrityCheck() rationale.
        $response = $this->redirectToRoute('admin_job_progress_page', [
            'id'     => $jobId,
            'return' => $this->generateUrl('admin_data_repair_index'),
        ], Response::HTTP_SEE_OTHER);

        return $this->jobDispatcher->dispatch(
            ExecutePendingMigrationsJob::class,
            ['actor' => $actor],
            $jobId,
            $response,
            $request->getSession(),
        );
    }

    /**
     * Moves orphaned upload files (files on disk with no DB owner) to
     * `var/quarantine/<YYYY-MM-DD>/`. NEVER `unlink` — quarantine is
     * reversible. Every move logged through AuditLogger::logBulk() under
     * a single batch_id (ISO 27001 Clause 7.5.3).
     */
    #[Route('/admin/data-repair/quarantine-uploads', name: 'admin_data_repair_quarantine_uploads', methods: ['POST'])]
    public function quarantineOrphanedUploads(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('quarantine_uploads', (string) $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('common.csrf_error', [], 'messages'));
            return $this->redirectToRoute('admin_data_repair_index');
        }

        $orphans = $this->withoutTenantFilter(
            fn() => $this->dataIntegrityService->findOrphanedUploads()
        );
        $uploadsDir = $orphans['uploads_dir'] ?? null;
        if (!is_string($uploadsDir) || $uploadsDir === '' || count($orphans['files']) === 0) {
            $this->addFlash('info', $this->translator->trans('admin.data_repair.uploads.nothing_to_quarantine', [], 'admin'));
            return $this->redirectToRoute('admin_data_repair_index');
        }

        // var/quarantine/<date>/ — created lazily; never auto-deleted.
        $projectDir = dirname($uploadsDir, 2); // public/uploads → project root
        $stamp = (new \DateTimeImmutable())->format('Y-m-d_His');
        $quarantineDir = $projectDir . '/var/quarantine/' . $stamp;
        if (!is_dir($quarantineDir) && !@mkdir($quarantineDir, 0775, true) && !is_dir($quarantineDir)) {
            $this->addFlash('error', $this->translator->trans('admin.data_repair.uploads.quarantine_dir_failed', ['%dir%' => $quarantineDir], 'admin'));
            return $this->redirectToRoute('admin_data_repair_index');
        }

        $perEntity = [];
        $moved = 0;
        foreach ($orphans['files'] as $orphan) {
            $src = (string) ($orphan['path'] ?? '');
            $relative = (string) ($orphan['relative'] ?? '');
            if ($src === '' || !is_file($src)) {
                continue;
            }
            // Path-traversal-safe: only files whose realpath is inside uploadsDir
            // ever reach this list (the scanner filters them already).
            $basename = basename($src);
            $target = $quarantineDir . '/' . $basename;
            // Tolerate collisions: append a counter when needed.
            $counter = 0;
            while (file_exists($target)) {
                $counter++;
                $target = $quarantineDir . '/' . $counter . '_' . $basename;
            }
            if (@rename($src, $target)) {
                $moved++;
                $perEntity[] = [
                    'entity_id' => null,
                    'action' => 'delete',
                    'old_values' => ['path' => $relative, 'size' => (int) ($orphan['size'] ?? 0)],
                    'new_values' => ['quarantine_path' => $target],
                ];
            }
        }

        if ($moved > 0) {
            $this->auditLogger->logBulk(
                'admin.data_repair.uploads_quarantined',
                'UploadFile',
                [
                    'quarantine_dir' => $quarantineDir,
                    'scanned' => (int) ($orphans['scanned'] ?? 0),
                    'referenced' => (int) ($orphans['referenced'] ?? 0),
                ],
                $perEntity,
                sprintf('Quarantined %d orphaned upload files to %s', $moved, $quarantineDir),
            );
        }

        $this->addFlash('success', $this->translator->trans(
            'admin.data_repair.uploads.quarantined',
            ['%count%' => $moved, '%dir%' => $quarantineDir],
            'admin',
        ));

        return $this->redirectToRoute('admin_data_repair_index');
    }

    /**
     * Cleans up entities whose ManyToOne target row was deleted but the
     * cascade never fired. Five categories (workflow_instances, mfa_tokens,
     * sso_user_approvals, evidence_tasks, notification_deliveries) are
     * processed under ONE AuditLogger::logBulk() batch so an auditor can
     * answer "show me the cleanup of $batch_id" in one query.
     */
    #[Route('/admin/data-repair/cleanup-dangling-refs', name: 'admin_data_repair_cleanup_dangling_refs', methods: ['POST'])]
    public function cleanupDanglingRefs(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('cleanup_dangling_refs', (string) $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('common.csrf_error', [], 'messages'));
            return $this->redirectToRoute('admin_data_repair_index');
        }

        // Reason is mandatory for cascade-cleanup — a misclick could remove an
        // SSO approval that an admin still needed for forensics.
        $reason = trim((string) $request->request->get('reason', ''));
        if (mb_strlen($reason) < 20) {
            $this->addFlash('danger', $this->translator->trans(
                'admin.data_repair.reason_required',
                ['%min%' => 20, '%actual%' => mb_strlen($reason)],
                'admin',
            ));
            return $this->redirectToRoute('admin_data_repair_index');
        }

        $categoryClassMap = [
            'workflow_instances' => \App\Entity\WorkflowInstance::class,
            'mfa_tokens' => \App\Entity\MfaToken::class,
            'sso_user_approvals' => \App\Entity\SsoUserApproval::class,
            'evidence_tasks' => \App\Entity\EvidenceReverificationTask::class,
            'notification_deliveries' => \App\Entity\Notification\NotificationDelivery::class,
        ];

        $perEntity = [];
        $deleted = 0;
        $this->withoutTenantFilter(function () use ($categoryClassMap, &$perEntity, &$deleted): void {
            $cascadeOrphans = $this->dataIntegrityService->findCascadeOrphans();
            foreach ($categoryClassMap as $category => $fqcn) {
                $items = $cascadeOrphans[$category] ?? [];
                foreach ($items as $item) {
                    $id = (int) ($item['id'] ?? 0);
                    if ($id <= 0) {
                        continue;
                    }
                    $entity = $this->entityManager->find($fqcn, $id);
                    if ($entity === null) {
                        continue;
                    }
                    $perEntity[] = [
                        'entity_id' => $id,
                        'action' => 'delete',
                        'old_values' => ['class' => $fqcn, 'label' => (string) ($item['label'] ?? '')],
                        'new_values' => null,
                    ];
                    $this->entityManager->remove($entity);
                    $deleted++;
                }
            }
            if ($deleted > 0) {
                $this->entityManager->flush();
            }
        });

        if ($deleted > 0) {
            $this->auditLogger->logBulk(
                'admin.data_repair.cascade_cleaned',
                'CascadeOrphan',
                ['reason' => $reason, 'category_count' => 5],
                $perEntity,
                sprintf('Cleaned %d cascade-orphan rows across 5 categories: %s', $deleted, $reason),
            );
        }

        $this->addFlash('success', $this->translator->trans(
            'admin.data_repair.cascade.cleaned',
            ['%count%' => $deleted],
            'admin',
        ));

        return $this->redirectToRoute('admin_data_repair_index');
    }

    /**
     * Reconciles entity metadata against the live DB. Idempotent — drift = 0
     * means no SQL is executed. Destructive statements run unconditionally
     * here (the UI button is only enabled with explicit operator intent),
     * but the service still audit-logs every executed statement bundle.
     */
    #[Route('/admin/data-repair/schema/reconcile', name: 'admin_data_repair_schema_reconcile', methods: ['POST'])]
    #[IsGranted(TenantScopedAdminVoter::ADMIN_GLOBAL_OP)]
    public function reconcileSchema(
        Request $request,
        #[CurrentUser] User $user,
    ): Response {
        if (!$this->isCsrfTokenValid('schema_reconcile', (string) $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('common.csrf_error', [], 'messages'));
            return $this->redirectToRoute('admin_data_repair_index');
        }

        $actor = (string) ($user->getEmail() ?? 'admin');

        // Reconcile from the data-repair page intentionally bypasses the
        // pending-migration gate: an admin who's looking at a populated
        // drift card has already seen any pending migrations on the same
        // page — the UX here is "apply both buttons explicitly".
        $jobId = $this->jobStatusService->create(
            'admin.data_repair.reconcile_schema',
            [
                'actor' => $actor,
                'bypassMigrationGate' => true,
                '_label' => $this->translator->trans('admin.data_repair.job.reconcile_schema_label', [], 'admin'),
                '_subtitle' => $this->translator->trans('admin.data_repair.job.reconcile_schema_subtitle', [], 'admin'),
            ],
        );

        // PRG: 303 redirect to the shared progress page — see runIntegrityCheck() rationale.
        $response = $this->redirectToRoute('admin_job_progress_page', [
            'id'     => $jobId,
            'return' => $this->generateUrl('admin_data_repair_index'),
        ], Response::HTTP_SEE_OTHER);

        return $this->jobDispatcher->dispatch(
            ReconcileSchemaJob::class,
            ['actor' => $actor, 'bypassMigrationGate' => true],
            $jobId,
            $response,
            $request->getSession(),
        );
    }
}

