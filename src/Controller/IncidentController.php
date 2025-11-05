<?php

namespace App\Controller;

use App\Repository\IncidentRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/incident')]
class IncidentController extends AbstractController
{
    public function __construct(private IncidentRepository $incidentRepository) {}

    #[Route('/', name: 'app_incident_index')]
    public function index(): Response
    {
        $openIncidents = $this->incidentRepository->findOpenIncidents();
        $allIncidents = $this->incidentRepository->findAll();
        $categoryStats = $this->incidentRepository->countByCategory();

        return $this->render('incident/index.html.twig', [
            'openIncidents' => $openIncidents,
            'allIncidents' => $allIncidents,
            'categoryStats' => $categoryStats,
        ]);
    }
}
