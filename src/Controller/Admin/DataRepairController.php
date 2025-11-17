<?php

namespace App\Controller\Admin;

use App\Entity\Tenant;
use App\Repository\AssetRepository;
use App\Repository\RiskRepository;
use App\Repository\IncidentRepository;
use App\Repository\TenantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/data-repair')]
#[IsGranted('ROLE_ADMIN')]
class DataRepairController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AssetRepository $assetRepository,
        private readonly RiskRepository $riskRepository,
        private readonly IncidentRepository $incidentRepository,
        private readonly TenantRepository $tenantRepository,
        private readonly TranslatorInterface $translator
    ) {
    }

    #[Route('/', name: 'admin_data_repair_index')]
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

        return $this->render('admin/data_repair/index.html.twig', [
            'tenants' => $tenants,
            'orphanedAssets' => $orphanedAssets,
            'orphanedRisks' => $orphanedRisks,
            'orphanedIncidents' => $orphanedIncidents,
            'tenantStats' => $tenantStats,
            'allRisks' => $allRisks,
            'allIncidents' => $allIncidents,
            'allAssets' => $allAssets,
        ]);
    }

    #[Route('/assign-orphans', name: 'admin_data_repair_assign_orphans', methods: ['POST'])]
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
                foreach ($orphanedIncidents as $entity) {
                    $entity->setTenant($tenant);
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

    #[Route('/reassign-entity/{type}/{id}', name: 'admin_data_repair_reassign_entity', methods: ['POST'])]
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

    #[Route('/assign-asset/{type}/{id}', name: 'admin_data_repair_assign_asset', methods: ['POST'])]
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
}
