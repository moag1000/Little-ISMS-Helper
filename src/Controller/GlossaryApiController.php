<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\GlossaryService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * JSON endpoint backing the fa-glossary-tooltip component. Loaded WITHOUT the
 * locale prefix (see config/routes.yaml) so the client can fetch
 * /api/glossary/{acronym} directly; the UI locale is passed as ?locale.
 */
#[IsGranted('ROLE_USER')]
class GlossaryApiController extends AbstractController
{
    public function __construct(
        private readonly GlossaryService $glossary,
    ) {
    }

    #[Route('/api/glossary/{acronym}', name: 'app_api_glossary', methods: ['GET'])]
    public function term(string $acronym, Request $request): JsonResponse
    {
        $locale = substr((string) ($request->query->get('locale') ?: $request->getLocale() ?: 'de'), 0, 2);
        $entry = $this->glossary->lookup($acronym, $locale);

        if ($entry === null) {
            return new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($entry);
    }
}
