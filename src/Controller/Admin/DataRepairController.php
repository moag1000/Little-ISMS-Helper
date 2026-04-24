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
    ) {
    }

    #[Route('/admin/data-repair/', name: 'admin_data_repair_index')]
    public function index(): Response
    {
        // Run comprehensive integrity check
        $integrityCheck = $this->dataIntegrityService->runFullIntegrityCheck();
        $summary = $this->dataIntegrityService->getSummaryStatistics();

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

        return $this->render('admin/data_repair/index.html.twig', [
            // Tenants & Summary
            'tenants' => $tenants,
            'summary' => $summary,

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
        // TenantFilter muss für Orphan-Queries aus — sonst AND tenant_id = :current
        // widerspricht WHERE tenant IS NULL und liefert 0.
        $filters = $this->entityManager->getFilters();
        $filterWasEnabled = $filters->isEnabled('tenant_filter');
        if ($filterWasEnabled) {
            $filters->disable('tenant_filter');
        }

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

        switch ($entityType) {
            case 'assets':
                $orphaned = $this->assetRepository->createQueryBuilder('a')
                    ->where('a.tenant IS NULL')
                    ->getQuery()
                    ->getResult();
                foreach ($orphaned as $entity) {
                    $assignFn($entity, 'Asset');
                }
                break;

            case 'risks':
                $orphaned = $this->riskRepository->createQueryBuilder('r')
                    ->where('r.tenant IS NULL')
                    ->getQuery()
                    ->getResult();
                foreach ($orphaned as $entity) {
                    $assignFn($entity, 'Risk');
                }
                break;

            case 'incidents':
                $orphaned = $this->incidentRepository->createQueryBuilder('i')
                    ->where('i.tenant IS NULL')
                    ->getQuery()
                    ->getResult();
                foreach ($orphaned as $entity) {
                    $assignFn($entity, 'Incident');
                }
                break;

            case 'all':
                // Alle Orphans aller Entity-Typen — generischer Scan via Service.
                // Vermeidet hardcoded Asset/Risk/Incident-Liste, deckt auch
                // seltenere Typen (Supplier, Location, Document, BCPlan, …) ab.
                $allOrphans = $this->dataIntegrityService->findAllOrphanedEntities();
                foreach ($allOrphans as $entities) {
                    foreach ($entities as $entity) {
                        $assignFn($entity, (new \ReflectionClass($entity))->getShortName());
                    }
                }
                break;

            default:
                $this->addFlash('error', $this->translator->trans('admin.data_repair.invalid_entity_type'));
                return $this->redirectToRoute('admin_data_repair_index');
        }

        $this->entityManager->flush();

        if ($filterWasEnabled) {
            $filters->enable('tenant_filter');
        }

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

        $entity = null;
        $entityName = '';
        $className = '';

        switch ($type) {
            case 'asset':
                $entity = $this->assetRepository->find($id);
                $entityName = $entity ? $entity->getName() : '';
                $className = 'Asset';
                break;
            case 'risk':
                $entity = $this->riskRepository->find($id);
                $entityName = $entity ? $entity->getTitle() : '';
                $className = 'Risk';
                break;
            case 'incident':
                $entity = $this->incidentRepository->find($id);
                $entityName = $entity ? $entity->getTitle() : '';
                $className = 'Incident';
                break;
        }

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

        $orphaned = $this->dataIntegrityService->findAllOrphanedEntities();

        // Guard 2: confirm hash must match the orphan counts shown at preview
        // time. Client sends `confirm_hash = sha256(expected_total|tenant_id)`.
        $expectedTotal = 0;
        $classCounts = [];
        foreach ($orphaned as $className => $entities) {
            $expectedTotal += count($entities);
            $classCounts[$className] = count($entities);
        }
        $expectedHash = hash('sha256', $expectedTotal . '|' . $tenant->getId());
        $submittedHash = (string) $request->request->get('confirm_hash', '');
        if (!hash_equals($expectedHash, $submittedHash)) {
            $this->addFlash('danger', $this->translator->trans(
                'admin.data_repair.bulk_confirm_hash_mismatch',
                ['%expected%' => $expectedTotal],
                'admin',
            ));
            return $this->redirectToRoute('admin_data_repair_index');
        }

        // Apply + audit per entity, not per batch, so auditor can answer
        // "show me each reassignment" without diff guessing.
        $totalFixed = 0;
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

        $brokenReferences = $this->dataIntegrityService->findBrokenReferences();
        $fixedCount = 0;

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

        $this->addFlash('success', $this->translator->trans('admin.data_repair.fixed_mismatches', [
            '%count%' => $fixedCount,
        ], 'admin'));

        return $this->redirectToRoute('admin_data_repair_index');
    }
}

