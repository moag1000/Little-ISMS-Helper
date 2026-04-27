<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\MrisBaselineService;
use App\Service\TenantContext;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Anwender-UI für MRIS-Branchen-Baselines.
 *
 * Listet alle verfügbaren Baselines (KRITIS, Finance, Automotive/TISAX, SaaS/CRA)
 * und erlaubt das Anwenden inkl. Dry-Run auf den aktiven Mandanten.
 *
 * Console-Pendant: {@see \App\Command\MrisApplyBaselineCommand}
 *
 * Quelle MRIS-Konzepte: Peddi, R. (2026). MRIS — Mythos-resistente
 * Informationssicherheit, v1.5. Lizenz: CC BY 4.0.
 */
#[IsGranted('ROLE_USER')]
final class MrisBaselineUiController extends AbstractController
{
    public function __construct(
        private readonly MrisBaselineService $baselineService,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route('/mris/baselines', name: 'app_mris_baselines_index', methods: ['GET'])]
    public function index(): Response
    {
        $baselines = $this->baselineService->listBaselines();

        return $this->render('mris/baselines.html.twig', [
            'baselines' => $baselines,
            'tenant' => $this->tenantContext->getCurrentTenant(),
        ]);
    }

    #[Route('/mris/baselines/apply', name: 'app_mris_baselines_apply', methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    #[IsCsrfTokenValid('mris_baseline_apply', tokenKey: '_token')]
    public function apply(Request $request): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            $this->addFlash('warning', 'Kein Mandant zugewiesen — Baselines benötigen einen Mandantenkontext.');
            return $this->redirectToRoute('app_mris_baselines_index');
        }

        $baselineId = trim((string) $request->request->get('baseline_id'));
        if ($baselineId === '') {
            $this->addFlash('error', 'Keine Baseline ausgewählt.');
            return $this->redirectToRoute('app_mris_baselines_index');
        }

        $dryRun = (string) $request->request->get('dry_run', '') === '1';

        try {
            $result = $this->baselineService->applyBaseline($tenant, $baselineId, $dryRun);
        } catch (DomainException $e) {
            $this->addFlash('error', sprintf('Baseline "%s" konnte nicht angewendet werden: %s', $baselineId, $e->getMessage()));
            return $this->redirectToRoute('app_mris_baselines_index');
        }

        $message = sprintf(
            '%sBaseline "%s": %d Soll-Stufen %s, %d übersprungen.',
            $dryRun ? '[Dry-Run] ' : '',
            $result['baseline'],
            $result['applied'],
            $dryRun ? 'erkannt' : 'gesetzt',
            $result['skipped'],
        );
        if (!empty($result['missing_mhcs'])) {
            $message .= sprintf(' Fehlende MHCs im Framework: %s.', implode(', ', $result['missing_mhcs']));
            $this->addFlash('warning', $message);
        } else {
            $this->addFlash('success', $message);
        }

        return $this->redirectToRoute('app_mris_baselines_index');
    }
}
