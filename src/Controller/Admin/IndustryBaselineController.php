<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\AuditLogger;
use App\Service\IndustryBaselineService;
use App\Service\TenantContext;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin module for cross-framework industry baselines.
 *
 * Lists every framework that ships baselines and lets a tenant manager
 * preview (`dry_run=1`) or apply a baseline; only `maturityTarget` is set,
 * existing self-assessment values stay intact.
 */
#[Route('/admin/industry-baselines', name: 'admin_industry_baselines_')]
#[IsGranted('ROLE_ADMIN')]
final class IndustryBaselineController extends AbstractController
{
    public function __construct(
        private readonly IndustryBaselineService $service,
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $audit,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/industry_baseline/index.html.twig', [
            'frameworks' => $this->service->listFrameworksWithBaselines(),
            'tenant' => $this->tenantContext->getCurrentTenant(),
        ]);
    }

    #[Route('/{framework}', name: 'show', methods: ['GET'], requirements: ['framework' => '[A-Za-z0-9_.\-]+'])]
    public function show(string $framework): Response
    {
        return $this->render('admin/industry_baseline/show.html.twig', [
            'framework' => $framework,
            'baselines' => $this->service->listBaselinesForFramework($framework),
            'tenant' => $this->tenantContext->getCurrentTenant(),
        ]);
    }

    #[Route('/{framework}/apply', name: 'apply', methods: ['POST'], requirements: ['framework' => '[A-Za-z0-9_.\-]+'])]
    #[IsCsrfTokenValid('industry_baseline_apply')]
    public function apply(string $framework, Request $request): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            $this->addFlash('error', 'Mandantenkontext erforderlich.');
            return $this->redirectToRoute('admin_industry_baselines_show', ['framework' => $framework]);
        }

        $baselineId = (string) $request->request->get('baseline_id', '');
        $dryRun = $request->request->get('dry_run') === '1';

        try {
            $result = $this->service->applyBaseline($tenant, $framework, $baselineId, $dryRun);
        } catch (DomainException $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('admin_industry_baselines_show', ['framework' => $framework]);
        }

        if (!$dryRun) {
            $this->audit->logCustom('compliance.baseline.apply', 'ComplianceFramework', null, null, [
                'framework' => $framework,
                'baseline' => $result['baseline'],
                'applied' => $result['applied'],
                'missing' => count($result['missing']),
            ]);
        }

        $missing = $result['missing'];
        $msg = sprintf(
            '%s "%s": %d Targets gesetzt, %d übersprungen, %d unbekannte Requirement-IDs.',
            $dryRun ? 'Vorschau' : 'Angewendet',
            $result['baseline'],
            $result['applied'],
            $result['skipped'],
            count($missing)
        );
        if ($missing !== []) {
            $msg .= ' Unbekannt: ' . implode(', ', array_slice($missing, 0, 8));
            if (count($missing) > 8) {
                $msg .= ' …';
            }
        }
        $this->addFlash($dryRun ? 'info' : 'success', $msg);

        return $this->redirectToRoute('admin_industry_baselines_show', ['framework' => $framework]);
    }
}
