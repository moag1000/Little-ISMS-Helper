<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\AuditLogger;
use App\Service\IndustryBaselineService;
use App\Service\TenantContext;
use App\Exception\AppException;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Admin module for cross-framework industry baselines.
 *
 * Lists every framework that ships baselines and lets a tenant manager
 * preview (`dry_run=1`) or apply a baseline; only `maturityTarget` is set,
 * existing self-assessment values stay intact.
 */
// @no-methods-required — class-level path prefix, methods declared per action
#[Route('/admin/industry-baselines', name: 'admin_industry_baselines_')]
#[IsGranted('ROLE_ADMIN')]
final class IndustryBaselineController extends AbstractController
{
    public function __construct(
        private readonly IndustryBaselineService $service,
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $audit,
        private readonly TranslatorInterface $translator,
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
        $known = array_column($this->service->listFrameworksWithBaselines(), 'code');
        if (!in_array($framework, $known, true)) {
            throw $this->createNotFoundException();
        }

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
            $this->addFlash('error', 'industry_baseline.flash.tenant_required');
            return $this->redirectToRoute('admin_industry_baselines_show', ['framework' => $framework]);
        }

        $baselineId = (string) $request->request->get('baseline_id', '');
        $dryRun = $request->request->get('dry_run') === '1';

        try {
            $result = $this->service->applyBaseline($tenant, $framework, $baselineId, $dryRun);
        } catch (AppException | DomainException $e) {
            $this->addFlash('error', $this->translator->trans('industry_baseline.flash.apply_failed', ['%message%' => $e->getMessage()], 'industry_baseline'));
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
        $msg = $this->translator->trans(
            $dryRun ? 'industry_baseline.flash.summary_preview' : 'industry_baseline.flash.summary_applied',
            [
                '%baseline%' => $result['baseline'],
                '%applied%' => $result['applied'],
                '%skipped%' => $result['skipped'],
                '%missing%' => count($missing),
            ],
            'industry_baseline',
        );
        if ($missing !== []) {
            $msg .= ' ' . $this->translator->trans('industry_baseline.flash.unknown_ids', [
                '%ids%' => implode(', ', array_slice($missing, 0, 8)) . (count($missing) > 8 ? ' …' : ''),
            ], 'industry_baseline');
        }
        $this->addFlash($dryRun ? 'info' : 'success', $msg);

        return $this->redirectToRoute('admin_industry_baselines_show', ['framework' => $framework]);
    }
}
