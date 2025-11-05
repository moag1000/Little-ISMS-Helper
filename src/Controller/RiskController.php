<?php

namespace App\Controller;

use App\Repository\RiskRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/risk')]
class RiskController extends AbstractController
{
    public function __construct(private RiskRepository $riskRepository) {}

    #[Route('/', name: 'app_risk_index')]
    public function index(): Response
    {
        $risks = $this->riskRepository->findAll();
        $highRisks = $this->riskRepository->findHighRisks();
        $treatmentStats = $this->riskRepository->countByTreatmentStrategy();

        return $this->render('risk/index.html.twig', [
            'risks' => $risks,
            'highRisks' => $highRisks,
            'treatmentStats' => $treatmentStats,
        ]);
    }
}
