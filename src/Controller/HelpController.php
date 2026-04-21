<?php

namespace App\Controller;

use App\Service\GuidedTourService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class HelpController extends AbstractController
{
    public function __construct(
        private readonly ?GuidedTourService $tourService = null,
    ) {
    }

    #[Route('/help/iso9001-bridge', name: 'app_help_iso9001_bridge')]
    public function iso9001Bridge(): Response
    {
        return $this->render('help/iso9001_bridge.html.twig');
    }

    #[Route('/help/glossary', name: 'app_help_glossary')]
    public function glossary(): Response
    {
        return $this->render('help/glossary.html.twig');
    }

    /**
     * Sprint 13 / P6-alt — Statische Übersicht aller Tour-Rollen als
     * druckbares Handout-Material. Ersetzt den ursprünglich geplanten
     * PDF-Export (~75 % Aufwand bei gleichem Consultant-Nutzen).
     */
    #[Route('/help/tour', name: 'app_help_tour_index')]
    public function tourIndex(): Response
    {
        $tours = $this->tourService?->allMeta() ?? [];
        return $this->render('help/tour/index.html.twig', [
            'tours' => $tours,
        ]);
    }

    #[Route('/help/tour/{role}', name: 'app_help_tour_role', requirements: ['role' => '[a-z_]+'])]
    public function tourRole(string $role): Response
    {
        if ($this->tourService === null || !in_array($role, GuidedTourService::ALL_TOURS, true)) {
            throw $this->createNotFoundException();
        }

        return $this->render('help/tour/role.html.twig', [
            'tour_id' => $role,
            'tour_meta' => $this->tourService->metaFor($role),
            'steps' => $this->tourService->stepsFor($role),
        ]);
    }
}
