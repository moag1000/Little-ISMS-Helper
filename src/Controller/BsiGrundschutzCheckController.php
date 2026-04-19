<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\BsiGrundschutzCheckService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * BSI IT-Grundschutz-Check view — Soll/Ist per Baustein.
 *
 * Renders the `BsiGrundschutzCheckService` report with an
 * Absicherungsstufe filter (basis | standard | kern | all).
 */
#[IsGranted('ROLE_MANAGER')]
class BsiGrundschutzCheckController extends AbstractController
{
    public function __construct(
        private readonly BsiGrundschutzCheckService $bsiGrundschutzCheckService,
    ) {
    }

    #[Route('/bsi-grundschutz-check', name: 'app_bsi_grundschutz_check')]
    public function index(Request $request): Response
    {
        $raw = (string) $request->query->get('absicherungs_stufe', '');
        $absicherungsStufe = in_array($raw, ['basis', 'standard', 'kern'], true) ? $raw : null;

        $report = $this->bsiGrundschutzCheckService->getCheckReport($absicherungsStufe);

        return $this->render('bsi_grundschutz_check/index.html.twig', [
            'report' => $report,
            'absicherungsStufe' => $absicherungsStufe,
        ]);
    }
}
