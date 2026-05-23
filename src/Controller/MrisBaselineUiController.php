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
use Symfony\Contracts\Translation\TranslatorInterface;

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
        private readonly TranslatorInterface $translator,
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
            $this->addFlash('warning', $this->translator->trans('mris.baseline.flash.no_tenant', [], 'mris'));
            return $this->redirectToRoute('app_mris_baselines_index');
        }

        $baselineId = trim((string) $request->request->get('baseline_id'));
        if ($baselineId === '') {
            $this->addFlash('error', $this->translator->trans('mris.baseline.flash.none_selected', [], 'mris'));
            return $this->redirectToRoute('app_mris_baselines_index');
        }

        $dryRun = (string) $request->request->get('dry_run', '') === '1';

        try {
            $result = $this->baselineService->applyBaseline($tenant, $baselineId, $dryRun);
        } catch (DomainException $e) {
            $this->addFlash('error', $this->translator->trans('mris.baseline.flash.apply_failed', [
                '%baseline%' => $baselineId,
                '%reason%' => $e->getMessage(),
            ], 'mris'));
            return $this->redirectToRoute('app_mris_baselines_index');
        }

        $message = $this->translator->trans('mris.baseline.flash.applied_summary', [
            '%prefix%' => $dryRun ? $this->translator->trans('mris.baseline.flash.dry_run_prefix', [], 'mris') : '',
            '%baseline%' => $result['baseline'],
            '%applied%' => $result['applied'],
            '%verb%' => $dryRun
                ? $this->translator->trans('mris.baseline.flash.verb_detected', [], 'mris')
                : $this->translator->trans('mris.baseline.flash.verb_set', [], 'mris'),
            '%skipped%' => $result['skipped'],
        ], 'mris');
        if (!empty($result['missing_mhcs'])) {
            $message .= ' ' . $this->translator->trans('mris.baseline.flash.missing_mhcs', [
                '%list%' => implode(', ', $result['missing_mhcs']),
            ], 'mris');
            $this->addFlash('warning', $message);
        } else {
            $this->addFlash('success', $message);
        }

        return $this->redirectToRoute('app_mris_baselines_index');
    }
}
