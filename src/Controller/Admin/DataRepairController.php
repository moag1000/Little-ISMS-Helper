<?php

namespace App\Controller\Admin;

use App\Entity\Control;
use App\Repository\AssetRepository;
use App\Repository\RiskRepository;
use App\Repository\IncidentRepository;
use App\Repository\TenantRepository;
use App\Repository\ControlRepository;
use App\Repository\ComplianceRequirementRepository;
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
        private readonly TranslatorInterface $translator
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

        switch ($entityType) {
            case 'assets':
                $orphaned = $this->assetRepository->createQueryBuilder('a')
                    ->where('a.tenant IS NULL')
                    ->getQuery()
                    ->getResult();
                foreach ($orphaned as $entity) {
                    $entity->setTenant($tenant);
                    $count++;
                }
                break;

            case 'risks':
                $orphaned = $this->riskRepository->createQueryBuilder('r')
                    ->where('r.tenant IS NULL')
                    ->getQuery()
                    ->getResult();
                foreach ($orphaned as $entity) {
                    $entity->setTenant($tenant);
                    $count++;
                }
                break;

            case 'incidents':
                $orphaned = $this->incidentRepository->createQueryBuilder('i')
                    ->where('i.tenant IS NULL')
                    ->getQuery()
                    ->getResult();
                foreach ($orphaned as $entity) {
                    $entity->setTenant($tenant);
                    $count++;
                }
                break;

            case 'all':
                // Assign all orphaned entities to the selected tenant
                $orphanedAssets = $this->assetRepository->createQueryBuilder('a')
                    ->where('a.tenant IS NULL')
                    ->getQuery()
                    ->getResult();
                foreach ($orphanedAssets as $entity) {
                    $entity->setTenant($tenant);
                    $count++;
                }

                $orphanedRisks = $this->riskRepository->createQueryBuilder('r')
                    ->where('r.tenant IS NULL')
                    ->getQuery()
                    ->getResult();
                foreach ($orphanedRisks as $entity) {
                    $entity->setTenant($tenant);
                    $count++;
                }

                $orphanedIncidents = $this->incidentRepository->createQueryBuilder('i')
                    ->where('i.tenant IS NULL')
                    ->getQuery()
                    ->getResult();
                foreach ($orphanedIncidents as $orphanedIncident) {
                    $orphanedIncident->setTenant($tenant);
                    $count++;
                }
                break;

            default:
                $this->addFlash('error', $this->translator->trans('admin.data_repair.invalid_entity_type'));
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

        $entity = null;
        $entityName = '';

        switch ($type) {
            case 'asset':
                $entity = $this->assetRepository->find($id);
                $entityName = $entity ? $entity->getName() : '';
                break;
            case 'risk':
                $entity = $this->riskRepository->find($id);
                $entityName = $entity ? $entity->getTitle() : '';
                break;
            case 'incident':
                $entity = $this->incidentRepository->find($id);
                $entityName = $entity ? $entity->getTitle() : '';
                break;
        }

        if (!$entity) {
            $this->addFlash('error', $this->translator->trans('admin.data_repair.entity_not_found'));
            return $this->redirectToRoute('admin_data_repair_index');
        }

        $entity->setTenant($tenant);
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
                $entity->setAsset($asset);
                $entityName = $entity->getTitle();
                break;

            case 'incident':
                $entity = $this->incidentRepository->find($id);
                if (!$entity) {
                    $this->addFlash('error', $this->translator->trans('admin.data_repair.entity_not_found'));
                    return $this->redirectToRoute('admin_data_repair_index');
                }
                // Incidents have ManyToMany relationship with assets
                if (!$entity->getAffectedAssets()->contains($asset)) {
                    $entity->addAffectedAsset($asset);
                }
                $entityName = $entity->getTitle();
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
        $control->addRisk($risk);
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
        $control->addProtectedAsset($asset);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf(
            'Asset "%s" wurde erfolgreich der Maßnahme "%s" zugeordnet.',
            $asset->getName(),
            $control->getName()
        ));

        return $this->redirectToRoute('admin_data_repair_index');
    }

    #[Route('/admin/data-repair/fix-all-orphans/{tenantId}', name: 'admin_data_repair_fix_all_orphans', methods: ['POST'])]
    public function fixAllOrphans(Request $request, int $tenantId): Response
    {
        if (!$this->isCsrfTokenValid('fix_all_orphans', $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('common.csrf_error'));
            return $this->redirectToRoute('admin_data_repair_index');
        }

        $tenant = $this->tenantRepository->find($tenantId);
        if (!$tenant) {
            $this->addFlash('error', $this->translator->trans('admin.data_repair.tenant_not_found'));
            return $this->redirectToRoute('admin_data_repair_index');
        }

        $orphaned = $this->dataIntegrityService->findAllOrphanedEntities();
        $totalFixed = 0;

        // Assign all orphaned entities to the selected tenant
        foreach ($orphaned as $entities) {
            foreach ($entities as $entity) {
                if (method_exists($entity, 'setTenant')) {
                    $entity->setTenant($tenant);
                    $totalFixed++;
                }
            }
        }

        $this->entityManager->flush();

        $this->addFlash('success', $this->translator->trans('admin.data_repair.fixed_all_orphans', [
            '%count%' => $totalFixed,
            '%tenant%' => $tenant->getName(),
        ],'admin'));;

        return $this->redirectToRoute('admin_data_repair_index');
    }

    #[Route('/admin/data-repair/fix-tenant-mismatches', name: 'admin_data_repair_fix_tenant_mismatches', methods: ['POST'])]
    public function fixTenantMismatches(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('fix_tenant_mismatches', $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('common.csrf_error'));
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
                    $risk->setTenant($asset->getTenant());
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
                        $incident->setTenant($firstAsset->getTenant());
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

