<?php

namespace App\Controller;

use App\Repository\AssetRepository;
use App\Repository\RiskRepository;
use App\Repository\IncidentRepository;
use App\Repository\TrainingRepository;
use App\Repository\ControlRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
class SearchController extends AbstractController
{
    public function __construct(
        private AssetRepository $assetRepository,
        private RiskRepository $riskRepository,
        private IncidentRepository $incidentRepository,
        private TrainingRepository $trainingRepository,
        private ControlRepository $controlRepository
    ) {}

    /**
     * Global search endpoint
     * Searches across all entities: Assets, Risks, Controls, Incidents, Trainings
     */
    #[Route('/search', name: 'app_api_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $query = $request->query->get('q', '');

        if (strlen($query) < 2) {
            return $this->json([
                'total' => 0,
                'assets' => [],
                'risks' => [],
                'controls' => [],
                'incidents' => [],
                'trainings' => []
            ]);
        }

        // Search in each entity
        $assets = $this->searchAssets($query);
        $risks = $this->searchRisks($query);
        $controls = $this->searchControls($query);
        $incidents = $this->searchIncidents($query);
        $trainings = $this->searchTrainings($query);

        $total = count($assets) + count($risks) + count($controls) + count($incidents) + count($trainings);

        return $this->json([
            'total' => $total,
            'assets' => $assets,
            'risks' => $risks,
            'controls' => $controls,
            'incidents' => $incidents,
            'trainings' => $trainings,
            'query' => $query
        ]);
    }

    /**
     * Quick view endpoint for assets
     */
    #[Route('/asset/{id}/preview', name: 'app_api_asset_preview', methods: ['GET'])]
    public function assetPreview(int $id): Response
    {
        $asset = $this->assetRepository->find($id);

        if (!$asset) {
            return new Response('Asset nicht gefunden', 404);
        }

        return $this->render('_previews/_asset_preview.html.twig', [
            'asset' => $asset
        ]);
    }

    /**
     * Quick view endpoint for risks
     */
    #[Route('/risk/{id}/preview', name: 'app_api_risk_preview', methods: ['GET'])]
    public function riskPreview(int $id): Response
    {
        $risk = $this->riskRepository->find($id);

        if (!$risk) {
            return new Response('Risiko nicht gefunden', 404);
        }

        return $this->render('_previews/_risk_preview.html.twig', [
            'risk' => $risk
        ]);
    }

    /**
     * Quick view endpoint for incidents
     */
    #[Route('/incident/{id}/preview', name: 'app_api_incident_preview', methods: ['GET'])]
    public function incidentPreview(int $id): Response
    {
        $incident = $this->incidentRepository->find($id);

        if (!$incident) {
            return new Response('Vorfall nicht gefunden', 404);
        }

        return $this->render('_previews/_incident_preview.html.twig', [
            'incident' => $incident
        ]);
    }

    private function searchAssets(string $query): array
    {
        $assets = $this->assetRepository->createQueryBuilder('a')
            ->where('a.name LIKE :query OR a.description LIKE :query OR a.owner LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(5)
            ->getQuery()
            ->getResult();

        return array_map(function($asset) {
            return [
                'id' => $asset->getId(),
                'title' => $asset->getName(),
                'description' => $this->truncate($asset->getDescription(), 100),
                'url' => $this->generateUrl('app_asset_show', ['id' => $asset->getId()]),
                'badge' => $asset->getAssetType()
            ];
        }, $assets);
    }

    private function searchRisks(string $query): array
    {
        $risks = $this->riskRepository->createQueryBuilder('r')
            ->where('r.title LIKE :query OR r.description LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(5)
            ->getQuery()
            ->getResult();

        return array_map(function($risk) {
            $level = $risk->getInherentRiskLevel();
            $badge = $level >= 15 ? 'Hoch' : ($level >= 9 ? 'Mittel' : 'Niedrig');

            return [
                'id' => $risk->getId(),
                'title' => $risk->getTitle(),
                'description' => $this->truncate($risk->getDescription(), 100),
                'url' => $this->generateUrl('app_risk_show', ['id' => $risk->getId()]),
                'badge' => $badge
            ];
        }, $risks);
    }

    private function searchControls(string $query): array
    {
        $controls = $this->controlRepository->createQueryBuilder('c')
            ->where('c.controlId LIKE :query OR c.name LIKE :query OR c.description LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(5)
            ->getQuery()
            ->getResult();

        return array_map(function($control) {
            return [
                'id' => $control->getId(),
                'title' => $control->getControlId() . ' - ' . $control->getName(),
                'description' => $this->truncate($control->getDescription(), 100),
                'url' => $this->generateUrl('app_soa_show', ['id' => $control->getId()]),
                'badge' => $control->getImplementationStatus()
            ];
        }, $controls);
    }

    private function searchIncidents(string $query): array
    {
        $incidents = $this->incidentRepository->createQueryBuilder('i')
            ->where('i.title LIKE :query OR i.description LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(5)
            ->getQuery()
            ->getResult();

        return array_map(function($incident) {
            return [
                'id' => $incident->getId(),
                'title' => $incident->getTitle(),
                'description' => $this->truncate($incident->getDescription(), 100),
                'url' => $this->generateUrl('app_incident_show', ['id' => $incident->getId()]),
                'badge' => $incident->getSeverity()
            ];
        }, $incidents);
    }

    private function searchTrainings(string $query): array
    {
        $trainings = $this->trainingRepository->createQueryBuilder('t')
            ->where('t.title LIKE :query OR t.description LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(5)
            ->getQuery()
            ->getResult();

        return array_map(function($training) {
            return [
                'id' => $training->getId(),
                'title' => $training->getTitle(),
                'description' => $this->truncate($training->getDescription(), 100),
                'url' => $this->generateUrl('app_training_show', ['id' => $training->getId()]),
                'badge' => $training->getStatus()
            ];
        }, $trainings);
    }

    private function truncate(?string $text, int $length): string
    {
        if (!$text) {
            return '';
        }

        if (strlen($text) <= $length) {
            return $text;
        }

        return substr($text, 0, $length) . '...';
    }
}
