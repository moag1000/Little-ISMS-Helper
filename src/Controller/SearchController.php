<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Search\SearchService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class SearchController extends AbstractController
{
    public function __construct(
        private readonly SearchService $searchService,
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
}
