<?php

namespace App\Controller\Admin;

use App\Entity\Control;
use App\Repository\AssetRepository;
use App\Repository\RiskRepository;
use App\Repository\IncidentRepository;
use App\Repository\TenantRepository;
use App\Repository\ControlRepository;
use App\Repository\ComplianceRequirementRepository;
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
        private readonly TranslatorInterface $translator
    ) {
    }

    #[Route('/admin/data-repair/', name: 'admin_data_repair_index')]
    public function index(): Response
    {
        // Get all tenants
        $tenants = $this->tenantRepository->findAll();

        // Find orphaned entities (no tenant assigned)
        $orphanedAssets = $this->assetRepository->createQueryBuilder('a')
            ->where('a.tenant IS NULL')
            ->getQuery()
            ->getResult();

        $orphanedRisks = $this->riskRepository->createQueryBuilder('r')
            ->where('r.tenant IS NULL')
            ->getQuery()
            ->getResult();

        $orphanedIncidents = $this->incidentRepository->createQueryBuilder('i')
            ->where('i.tenant IS NULL')
            ->getQuery()
            ->getResult();

        // Get entity counts per tenant
        $tenantStats = [];
        foreach ($tenants as $tenant) {
            $tenantStats[$tenant->getId()] = [
                'tenant' => $tenant,
                'assets' => count($this->assetRepository->findByTenant($tenant)),
                'risks' => count($this->riskRepository->findByTenant($tenant)),
                'incidents' => count($this->incidentRepository->findByTenant($tenant)),
            ];
        }

        // Get all risks and incidents for asset assignment repair
        $allRisks = $this->riskRepository->findAll();
        $allIncidents = $this->incidentRepository->findAll();
        $allAssets = $this->assetRepository->findAll();

        // Find controls without risks AND without framework assignments
        // (applicable controls that have neither risk nor framework assignments)
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

        // Find controls without assets (applicable controls with no protected assets)
        $controlsWithoutAssets = array_filter($allControls, fn(Control $control): bool => $control->isApplicable() && $control->getProtectedAssets()->isEmpty());

        // Find broken references
        $brokenReferences = $this->findBrokenReferences();

        return $this->render('admin/data_repair/index.html.twig', [
            'tenants' => $tenants,
            'orphanedAssets' => $orphanedAssets,
            'orphanedRisks' => $orphanedRisks,
            'orphanedIncidents' => $orphanedIncidents,
            'tenantStats' => $tenantStats,
            'allRisks' => $allRisks,
            'allIncidents' => $allIncidents,
            'allAssets' => $allAssets,
            'controlsWithoutRisks' => $controlsWithoutRisks,
            'controlsWithoutAssets' => $controlsWithoutAssets,
            'brokenReferences' => $brokenReferences,
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

    /**
     * Find broken references in the database
     * Checks for foreign key references that point to non-existent entities
     */
    private function findBrokenReferences(): array
    {
        $broken = [];

        // Check risks with invalid asset references
        $allRisks = $this->riskRepository->findAll();
        foreach ($allRisks as $risk) {
            $asset = $risk->getAsset();
            if ($asset && !$this->entityManager->contains($asset)) {
                $broken[] = [
                    'type' => 'risk_invalid_asset',
                    'entity_type' => 'Risk',
                    'entity_id' => $risk->getId(),
                    'entity_name' => $risk->getTitle(),
                    'issue' => 'References non-existent asset',
                ];
            }
        }

        // Check incidents with invalid asset references
        $allIncidents = $this->incidentRepository->findAll();
        foreach ($allIncidents as $allIncident) {
            foreach ($allIncident->getAffectedAssets() as $asset) {
                if (!$this->entityManager->contains($asset)) {
                    $broken[] = [
                        'type' => 'incident_invalid_asset',
                        'entity_type' => 'Incident',
                        'entity_id' => $allIncident->getId(),
                        'entity_name' => $allIncident->getTitle(),
                        'issue' => 'References non-existent asset',
                    ];
                    break;
                }
            }
        }

        // Check controls with invalid risk references
        $allControls = $this->controlRepository->findAll();
        foreach ($allControls as $allControl) {
            foreach ($allControl->getRisks() as $risk) {
                if (!$this->entityManager->contains($risk)) {
                    $broken[] = [
                        'type' => 'control_invalid_risk',
                        'entity_type' => 'Control',
                        'entity_id' => $allControl->getId(),
                        'entity_name' => $allControl->getName(),
                        'issue' => 'References non-existent risk',
                    ];
                    break;
                }
            }
        }

        return $broken;
    }
}
