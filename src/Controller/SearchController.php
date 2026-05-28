<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\AssetRepository;
use App\Repository\IncidentRepository;
use App\Repository\RiskRepository;
use App\Service\Search\SearchService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class SearchController extends AbstractController
{
    public function __construct(
        private readonly SearchService $searchService,
        private readonly AssetRepository $assetRepository,
        private readonly RiskRepository $riskRepository,
        private readonly IncidentRepository $incidentRepository,
    ) {}

    /**
     * Global search endpoint — searches all entities, navigation targets, and admin pages.
     */
    #[Route('/api/search', name: 'app_api_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $query = trim((string) $request->query->get('q', ''));

        if (mb_strlen($query) < 2) {
            return $this->json(['total' => 0, 'query' => $query]);
        }

        $tenant = $this->getUser()?->getTenant();
        $results = $this->searchService->search($query, $tenant);

        return $this->json($results);
    }

    /**
     * Quick-view endpoint for assets.
     */
    #[Route('/api/asset/{id}/preview', name: 'app_api_asset_preview', methods: ['GET'])]
    public function assetPreview(int $id): Response
    {
        $asset = $this->assetRepository->find($id);
        $tenant = $this->getUser()?->getTenant();

        if (!$asset || ($tenant && $asset->getTenant() !== $tenant)) {
            return new Response('Not found', Response::HTTP_NOT_FOUND);
        }

        return $this->render('_previews/_asset_preview.html.twig', ['asset' => $asset]);
    }

    /**
     * Quick-view endpoint for risks.
     */
    #[Route('/api/risk/{id}/preview', name: 'app_api_risk_preview', methods: ['GET'])]
    public function riskPreview(int $id): Response
    {
        $risk = $this->riskRepository->find($id);
        $tenant = $this->getUser()?->getTenant();

        if (!$risk || ($tenant && $risk->getTenant() !== $tenant)) {
            return new Response('Not found', Response::HTTP_NOT_FOUND);
        }

        return $this->render('_previews/_risk_preview.html.twig', ['risk' => $risk]);
    }

    /**
     * Quick-view endpoint for incidents.
     */
    #[Route('/api/incident/{id}/preview', name: 'app_api_incident_preview', methods: ['GET'])]
    public function incidentPreview(int $id): Response
    {
        $incident = $this->incidentRepository->find($id);
        $tenant = $this->getUser()?->getTenant();

        if (!$incident || ($tenant && $incident->getTenant() !== $tenant)) {
            return new Response('Not found', Response::HTTP_NOT_FOUND);
        }

        return $this->render('_previews/_incident_preview.html.twig', ['incident' => $incident]);
    }
}
