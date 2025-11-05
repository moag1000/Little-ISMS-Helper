<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(): Response
    {
        return $this->render('home/index.html.twig', [
            'controller_name' => 'HomeController',
        ]);
    }

    #[Route('/dashboard', name: 'app_dashboard')]
    public function dashboard(): Response
    {
        return $this->render('home/dashboard.html.twig', [
            'kpis' => $this->getExampleKPIs(),
        ]);
    }

    /**
     * Beispiel-KPIs für das ISMS Dashboard
     */
    private function getExampleKPIs(): array
    {
        return [
            [
                'name' => 'Erfasste Assets',
                'value' => 0,
                'unit' => 'Stück',
                'icon' => '🖥️',
                'trend' => 'neutral',
            ],
            [
                'name' => 'Identifizierte Risiken',
                'value' => 0,
                'unit' => 'Stück',
                'icon' => '⚠️',
                'trend' => 'neutral',
            ],
            [
                'name' => 'Offene Vorfälle',
                'value' => 0,
                'unit' => 'Stück',
                'icon' => '🚨',
                'trend' => 'neutral',
            ],
            [
                'name' => 'Compliance-Status',
                'value' => 0,
                'unit' => '%',
                'icon' => '✅',
                'trend' => 'neutral',
            ],
        ];
    }
}
