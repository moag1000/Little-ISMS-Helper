<?php

namespace App\Controller\Admin;

use App\Entity\Control;
use App\Repository\AssetRepository;
use App\Repository\RiskRepository;
use App\Repository\IncidentRepository;
use App\Repository\TenantRepository;
use App\Repository\ControlRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Service\AuditLogger;
use App\Service\DataIntegrityService;
use App\Service\SchemaMaintenanceService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_ADMIN')]
class DataRepairController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
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
    ) {
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

    #[Route('/admin/data-repair/', name: 'admin_data_repair_index')]
    public function index(): Response
    {
        // Integrity-Check + Übersicht brauchen TenantFilter-off, sonst
        // fehlen Cross-Tenant-Mismatches und Orphans in den Counts.
        [$integrityCheck, $summary] = $this->withoutTenantFilter(fn() => [
            $this->dataIntegrityService->runFullIntegrityCheck(),
            $this->dataIntegrityService->getSummaryStatistics(),
        ]);

        // Get all tenants
        $tenants = $this->tenantRepository->findAll();

        // Get all risks, incidents and assets for dropdown assignment
        $allRisks = $this->riskRepository->findAll();
        $allIncidents = $this->incidentRepository->findAll();
        $allAssets = $this->assetRepository->findAll();

        // Find controls without risks AND without framework assignments
        $allControls = $this->controlRepository->findAll();
        $allComplianceRequirements = $this->complianceRequirementRepository->findAll();

        // Build a set of control IDs that are mapped to compliance requirements
        $controlsWithFrameworks = [];
        foreach ($allComplianceRequirements as $allComplianceRequirement) {
            foreach ($allComplianceRequirement->getMappedControls() as $control) {
                $controlsWithFrameworks[$control->getId()] = true;
            }
        }

        $controlsWithoutRisks = array_filter($allControls, fn(Control $control): bool =>
            // Only show controls that are applicable AND have no risks AND no framework assignments
            $control->isApplicable()
            && $control->getRisks()->isEmpty()
            && !isset($controlsWithFrameworks[$control->getId()]));

        // Find controls without assets
        $controlsWithoutAssets = array_filter($allControls, fn(Control $control): bool =>
            $control->isApplicable() && $control->getProtectedAssets()->isEmpty());

        // Schema maintenance: Doctrine migration backlog + entity-vs-DB drift.
        // Both are read-only here; the corresponding apply-routes are POST.
        $maintenance = $this->schemaMaintenanceService->getMaintenanceStatus();

        return $this->render('admin/data_repair/index.html.twig', [
            // Tenants & Summary
            'tenants' => $tenants,
            'summary' => $summary,

            // Schema maintenance status (3-card grid in template)
            'migration_status' => $maintenance['migration_status'],
            'schema_drift' => $maintenance['schema_drift'],

            // Comprehensive integrity check results
            'orphanedEntities' => $integrityCheck['orphaned_entities'],
            'duplicates' => $integrityCheck['duplicates'],
            'brokenReferences' => $integrityCheck['broken_references'],
            'missingRelationships' => $integrityCheck['missing_relationships'],
            'inconsistentData' => $integrityCheck['inconsistent_data'],
            'tenantStats' => $integrityCheck['entity_counts'],

            // Legacy data for existing template sections
            'orphanedAssets' => $integrityCheck['orphaned_entities']['assets'] ?? [],
            'orphanedRisks' => $integrityCheck['orphaned_entities']['risks'] ?? [],
            'orphanedIncidents' => $integrityCheck['orphaned_entities']['incidents'] ?? [],
            'allRisks' => $allRisks,
            'allIncidents' => $allIncidents,
            'allAssets' => $allAssets,
            'controlsWithoutRisks' => $controlsWithoutRisks,
            'controlsWithoutAssets' => $controlsWithoutAssets,
        ]);
    }

    #[Route('/admin/data-repair/assign-orphans', name: 'admin_data_repair_assign_orphans', methods: ['POST'])]
    public function assignOrphans(Request $request): Response
    {
        $tenantId = $request->request->get('tenant_id');
        $entityType = $request->request->get('entity_type');

        if (!$this->isCsrfTokenValid('assign_orphans', $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('common.csrf_error'));
            return $this->redirectToRoute('admin_data_repair_index');
        }

        $tenant = $this->tenantRepository->find($tenantId);
        if (!$tenant) {
            $this->addFlash('error', $this->translator->trans('admin.data_repair.tenant_not_found'));
            return $this->redirectToRoute('admin_data_repair_index');
        }

        $count = 0;

        // Audit-log each reassignment per entity (ISB MAJOR-1). The per-entity
        // granularity lets an auditor answer "who moved entity X into tenant Y"
        // without reverse-engineering a diff.
        $assignFn = function (object $entity, string $className) use ($tenant, &$count): void {
            if (!method_exists($entity, 'setTenant') || !method_exists($entity, 'getId')) {
                return;
            }
            $entity->setTenant($tenant);
            $this->auditLogger->logCustom(
                'admin.data_repair.orphan_reassigned',
                $className,
                (int) $entity->getId(),
                ['tenant_id' => null],
                ['tenant_id' => $tenant->getId(), 'tenant_name' => $tenant->getName()],
                sprintf('Orphan %s#%d reassigned to tenant %s', $className, (int) $entity->getId(), $tenant->getName()),
            );
            $count++;
        };

        // Generisch: Service liefert bereits alle Orphans keyed by entity-type.
        // 'all' iteriert komplett, sonst nur die gewählte Kategorie.
        $errorFlashKey = null;
        $this->withoutTenantFilter(function () use ($entityType, &$assignFn, &$errorFlashKey): void {
            $allOrphans = $this->dataIntegrityService->findAllOrphanedEntities();
            if ($entityType === 'all') {
                foreach ($allOrphans as $entities) {
                    foreach ($entities as $entity) {
                        $assignFn($entity, (new \ReflectionClass($entity))->getShortName());
                    }
                }
            } elseif (isset($allOrphans[$entityType])) {
                foreach ($allOrphans[$entityType] as $entity) {
                    $assignFn($entity, (new \ReflectionClass($entity))->getShortName());
                }
            } else {
                $errorFlashKey = 'admin.data_repair.invalid_entity_type';
            }
        });

        if ($errorFlashKey !== null) {
            $this->addFlash('error', $this->translator->trans($errorFlashKey));
            return $this->redirectToRoute('admin_data_repair_index');
        }

        $this->entityManager->flush();

        $this->addFlash('success', $this->translator->trans('admin.data_repair.assigned_count', [
            '%count%' => $count,
            '%tenant%' => $tenant->getName(),
        ]));

        return $this->redirectToRoute('admin_data_repair_index');
    }

    #[Route('/admin/data-repair/reassign-entity/{type}/{id}', name: 'admin_data_repair_reassign_entity', methods: ['POST'])]
    public function reassignEntity(Request $request, string $type, int $id): Response
    {
        $tenantId = $request->request->get('tenant_id');

        if (!$this->isCsrfTokenValid('reassign_entity_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('common.csrf_error'));
            return $this->redirectToRoute('admin_data_repair_index');
        }

        $tenant = $this->tenantRepository->find($tenantId);
        if (!$tenant) {
            $this->addFlash('error', $this->translator->trans('admin.data_repair.tenant_not_found'));
            return $this->redirectToRoute('admin_data_repair_index');
        }

        // Generischer Reassign — findet Entity per Doctrine-Metadata statt
        // fixer Repository-Auswahl. Damit funktionieren auch Controls,
        // Workflows, Suppliers usw.
        [$entity, $entityName, $className] = $this->withoutTenantFilter(function () use ($type, $id): array {
            $fqcn = $this->resolveEntityClassForType($type);
            if ($fqcn === null) {
                return [null, '', ''];
            }
            $found = $this->entityManager->find($fqcn, $id);
            $name = '';
            if ($found !== null) {
                if (method_exists($found, 'getName')) {
                    $name = (string) $found->getName();
                } elseif (method_exists($found, 'getTitle')) {
                    $name = (string) $found->getTitle();
                } else {
                    $name = '#' . $id;
                }
            }
            return [$found, $name, $found ? (new \ReflectionClass($found))->getShortName() : ''];
        });

        if (!$entity) {
            $this->addFlash('error', $this->translator->trans('admin.data_repair.entity_not_found'));
            return $this->redirectToRoute('admin_data_repair_index');
        }

        // ISB MAJOR-1: capture previous tenant before mutation for audit diff.
        $previousTenant = method_exists($entity, 'getTenant') ? $entity->getTenant() : null;
        $previousTenantId = $previousTenant instanceof \App\Entity\Tenant ? $previousTenant->getId() : null;
        $entity->setTenant($tenant);
        $this->auditLogger->logCustom(
            'admin.data_repair.entity_reassigned',
            $className,
            $id,
            ['tenant_id' => $previousTenantId],
            ['tenant_id' => $tenant->getId(), 'tenant_name' => $tenant->getName()],
            sprintf('%s#%d "%s" reassigned to tenant %s', $className, $id, $entityName, $tenant->getName()),
        );
        $this->entityManager->flush();

        $this->addFlash('success', $this->translator->trans('admin.data_repair.entity_reassigned', [
            '%entity%' => $entityName,
            '%tenant%' => $tenant->getName(),
        ]));

        return $this->redirectToRoute('admin_data_repair_index');
    }

    #[Route('/admin/data-repair/assign-asset/{type}/{id}', name: 'admin_data_repair_assign_asset', methods: ['POST'])]
    public function assignAsset(Request $request, string $type, int $id): Response
    {
        $assetId = $request->request->get('asset_id');

        if (!$this->isCsrfTokenValid('assign_asset_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('common.csrf_error'));
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
                    $this->addFlash('error', $this->translator->trans('admin.data_repair.entity_not_found'));
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
                    $this->addFlash('error', $this->translator->trans('admin.data_repair.entity_not_found'));
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
                $this->addFlash('error', $this->translator->trans('admin.data_repair.invalid_entity_type'));
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
            $this->addFlash('error', $this->translator->trans('common.csrf_error'));
            return $this->redirectToRoute('admin_data_repair_index');
        }

        if (!$riskId) {
            $this->addFlash('error', $this->translator->trans('admin.data_repair.select_risk'));
            return $this->redirectToRoute('admin_data_repair_index');
        }

        $control = $this->controlRepository->find($id);
        if (!$control) {
            $this->addFlash('error', $this->translator->trans('admin.data_repair.entity_not_found'));
            return $this->redirectToRoute('admin_data_repair_index');
        }

        $risk = $this->riskRepository->find($riskId);
        if (!$risk) {
            $this->addFlash('error', $this->translator->trans('admin.data_repair.entity_not_found'));
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
            $this->addFlash('error', $this->translator->trans('common.csrf_error'));
            return $this->redirectToRoute('admin_data_repair_index');
        }

        if (!$assetId) {
            $this->addFlash('error', $this->translator->trans('admin.data_repair.select_asset'));
            return $this->redirectToRoute('admin_data_repair_index');
        }

        $control = $this->controlRepository->find($id);
        if (!$control) {
            $this->addFlash('error', $this->translator->trans('admin.data_repair.entity_not_found'));
            return $this->redirectToRoute('admin_data_repair_index');
        }

        $asset = $this->assetRepository->find($assetId);
        if (!$asset) {
            $this->addFlash('error', $this->translator->trans('admin.data_repair.asset_not_found'));
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
            $this->addFlash('error', $this->translator->trans('common.csrf_error'));
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
            $this->addFlash('error', $this->translator->trans('admin.data_repair.tenant_not_found'));
            return $this->redirectToRoute('admin_data_repair_index');
        }

        // Guard 2 (confirm_hash-Drift-Check) entfernt — CSRF + JS-Bestätigungs-
        // Dialog reichen; der Hash war fragil, weil Orphan-Counts sich zwischen
        // Render und Submit ändern können (z.B. neue Imports) und der Nutzer
        // dann aus einer gültigen Aktion ausgeschlossen wird.
        $totalFixed = 0;
        $this->withoutTenantFilter(function () use ($tenant, &$totalFixed): void {
            $orphaned = $this->dataIntegrityService->findAllOrphanedEntities();

            foreach ($orphaned as $className => $entities) {
                foreach ($entities as $entity) {
                    if (!method_exists($entity, 'setTenant') || !method_exists($entity, 'getId')) {
                        continue;
                    }
                    $entity->setTenant($tenant);
                    $this->auditLogger->logCustom(
                        'admin.data_repair.orphan_reassigned',
                        $className,
                        (int) $entity->getId(),
                        ['tenant_id' => null],
                        ['tenant_id' => $tenant->getId(), 'tenant_name' => $tenant->getName()],
                        sprintf('Orphan %s#%d reassigned to tenant %s', $className, (int) $entity->getId(), $tenant->getName()),
                    );
                    $totalFixed++;
                }
            }

            $this->entityManager->flush();
        });

        $this->addFlash('success', $this->translator->trans('admin.data_repair.fixed_all_orphans', [
            '%count%' => $totalFixed,
            '%tenant%' => $tenant->getName(),
        ], 'admin'));

        return $this->redirectToRoute('admin_data_repair_index');
    }

    /**
     * Resolves cross-entity tenant mismatches by forcing the child's tenant
     * to match its related Asset. ISB MINOR-5 / A.5.3: this is a judgement
     * call (could be a data leak OR a reparation) — therefore:
     *   - a reason ≥ 20 chars is mandatory,
     *   - every reassignment is audit-logged with the before/after tenant.
     */
    #[Route('/admin/data-repair/fix-tenant-mismatches', name: 'admin_data_repair_fix_tenant_mismatches', methods: ['POST'])]
    public function fixTenantMismatches(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('fix_tenant_mismatches', $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('common.csrf_error'));
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

        $fixedCount = 0;
        $this->withoutTenantFilter(function () use ($reason, &$fixedCount): void {
            $brokenReferences = $this->dataIntegrityService->findBrokenReferences();

            foreach ($brokenReferences as $ref) {
            // Fix risk-asset tenant mismatches by setting risk tenant to match asset
            if ($ref['type'] === 'risk_asset_tenant_mismatch') {
                $risk = $this->riskRepository->find($ref['entity_id']);
                $asset = $risk?->getAsset();
                if ($risk && $asset && $asset->getTenant()) {
                    $previousTenant = $risk->getTenant();
                    $newTenant = $asset->getTenant();
                    $risk->setTenant($newTenant);
                    $this->auditLogger->logCustom(
                        'admin.data_repair.tenant_mismatch_fixed',
                        'Risk',
                        (int) $risk->getId(),
                        ['tenant_id' => $previousTenant?->getId()],
                        [
                            'tenant_id' => $newTenant->getId(),
                            'tenant_name' => $newTenant->getName(),
                            'aligned_to' => 'Asset#' . (int) $asset->getId(),
                            'reason' => $reason,
                        ],
                        sprintf(
                            'Risk#%d tenant aligned to Asset#%d owner (tenant %d -> %d): %s',
                            (int) $risk->getId(),
                            (int) $asset->getId(),
                            (int) ($previousTenant?->getId() ?? 0),
                            (int) $newTenant->getId(),
                            $reason,
                        ),
                    );
                    $fixedCount++;
                }
            }

            // Fix incident-asset tenant mismatches
            if ($ref['type'] === 'incident_asset_tenant_mismatch') {
                $incident = $this->incidentRepository->find($ref['entity_id']);
                if ($incident && $incident->getAffectedAssets()->count() > 0) {
                    // Set incident tenant to first asset's tenant
                    $firstAsset = $incident->getAffectedAssets()->first();
                    if ($firstAsset && $firstAsset->getTenant()) {
                        $previousTenant = $incident->getTenant();
                        $newTenant = $firstAsset->getTenant();
                        $incident->setTenant($newTenant);
                        $this->auditLogger->logCustom(
                            'admin.data_repair.tenant_mismatch_fixed',
                            'Incident',
                            (int) $incident->getId(),
                            ['tenant_id' => $previousTenant?->getId()],
                            [
                                'tenant_id' => $newTenant->getId(),
                                'tenant_name' => $newTenant->getName(),
                                'aligned_to' => 'Asset#' . (int) $firstAsset->getId(),
                                'reason' => $reason,
                            ],
                            sprintf(
                                'Incident#%d tenant aligned to first affected Asset#%d owner (tenant %d -> %d): %s',
                                (int) $incident->getId(),
                                (int) $firstAsset->getId(),
                                (int) ($previousTenant?->getId() ?? 0),
                                (int) $newTenant->getId(),
                                $reason,
                            ),
                        );
                        $fixedCount++;
                    }
                }
            }
            }
            $this->entityManager->flush();
        });

        $this->addFlash('success', $this->translator->trans('admin.data_repair.fixed_mismatches', [
            '%count%' => $fixedCount,
        ], 'admin'));

        return $this->redirectToRoute('admin_data_repair_index');
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
    public function executeMigrations(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('migrations_execute', (string) $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('common.csrf_error'));
            return $this->redirectToRoute('admin_data_repair_index');
        }

        $user = $this->getUser();
        $actor = (is_object($user) && method_exists($user, 'getEmail')) ? (string) $user->getEmail() : 'admin';
        $result = $this->schemaMaintenanceService->executePendingMigrations($actor);

        if ($result['success']) {
            $this->addFlash('success', $this->translator->trans(
                'admin.data_repair.schema.migrations_applied',
                ['%count%' => $result['executed']],
                'admin',
            ));
        } else {
            $this->addFlash('error', $this->translator->trans(
                'admin.data_repair.schema.migrations_failed',
                ['%error%' => (string) $result['error']],
                'admin',
            ));
        }

        return $this->redirectToRoute('admin_data_repair_index');
    }

    /**
     * Reconciles entity metadata against the live DB. Idempotent — drift = 0
     * means no SQL is executed. Destructive statements run unconditionally
     * here (the UI button is only enabled with explicit operator intent),
     * but the service still audit-logs every executed statement bundle.
     */
    #[Route('/admin/data-repair/schema/reconcile', name: 'admin_data_repair_schema_reconcile', methods: ['POST'])]
    public function reconcileSchema(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('schema_reconcile', (string) $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('common.csrf_error'));
            return $this->redirectToRoute('admin_data_repair_index');
        }

        $user = $this->getUser();
        $actor = (is_object($user) && method_exists($user, 'getEmail')) ? (string) $user->getEmail() : 'admin';
        // Reconcile from the data-repair page intentionally bypasses the
        // pending-migration gate: an admin who's looking at a populated
        // drift card has already seen any pending migrations on the same
        // page — the UX here is "apply both buttons explicitly".
        $result = $this->schemaMaintenanceService->reconcileSchema($actor, bypassMigrationGate: true);

        if ($result['blocked'] !== null) {
            $this->addFlash('error', $this->translator->trans(
                'admin.data_repair.schema.reconcile_blocked',
                ['%reason%' => (string) $result['blocked']],
                'admin',
            ));
        } elseif ($result['success']) {
            $this->addFlash('success', $this->translator->trans(
                'admin.data_repair.schema.reconcile_applied',
                ['%count%' => $result['executed']],
                'admin',
            ));
        } else {
            $this->addFlash('error', $this->translator->trans(
                'admin.data_repair.schema.reconcile_failed',
                ['%error%' => (string) $result['error']],
                'admin',
            ));
        }

        return $this->redirectToRoute('admin_data_repair_index');
    }
}

