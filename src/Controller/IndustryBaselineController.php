<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\AppliedBaselineRepository;
use App\Repository\IndustryBaselineRepository;
use App\Service\IndustryBaselineApplier;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/industry-baselines', name: 'app_industry_baseline_')]
#[IsGranted('ROLE_MANAGER')]
final class IndustryBaselineController extends AbstractController
{
    public function __construct(
        private readonly IndustryBaselineRepository $baselineRepository,
        private readonly AppliedBaselineRepository $appliedRepository,
        private readonly IndustryBaselineApplier $applier,
        private readonly TenantContext $tenantContext,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        $applied = $tenant !== null ? $this->appliedRepository->findByTenant($tenant) : [];
        $appliedCodes = array_map(static fn($a) => $a->getBaselineCode(), $applied);

        return $this->render('industry_baseline/index.html.twig', [
            'baselines' => $this->baselineRepository->findAllOrdered(),
            'applied_codes' => $appliedCodes,
            'applied' => $applied,
        ]);
    }

    #[Route('/{code}', name: 'show', methods: ['GET'], requirements: ['code' => '[A-Za-z0-9_\-]+'])]
    public function show(string $code): Response
    {
        $baseline = $this->baselineRepository->findByCode($code);
        if ($baseline === null) {
            throw $this->createNotFoundException();
        }
        $tenant = $this->tenantContext->getCurrentTenant();
        $appliedRecord = $tenant !== null
            ? $this->appliedRepository->findOneByTenantAndCode($tenant, $baseline->getCode())
            : null;

        return $this->render('industry_baseline/show.html.twig', [
            'baseline' => $baseline,
            'applied_record' => $appliedRecord,
        ]);
    }

    #[Route('/{code}/apply', name: 'apply', methods: ['POST'], requirements: ['code' => '[A-Za-z0-9_\-]+'])]
    public function apply(string $code, Request $request): Response
    {
        $baseline = $this->baselineRepository->findByCode($code);
        if ($baseline === null) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('apply_baseline_' . $baseline->getCode(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', $this->translator->trans('industry_baseline.flash.invalid_csrf', [], 'industry_baseline'));
            return $this->redirectToRoute('app_industry_baseline_show', ['code' => $baseline->getCode()]);
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            $this->addFlash('warning', $this->translator->trans('industry_baseline.flash.no_tenant', [], 'industry_baseline'));
            return $this->redirectToRoute('app_industry_baseline_index');
        }

        /** @var User|null $user */
        $user = $this->getUser();
        $result = $this->applier->apply($baseline, $tenant, $user instanceof User ? $user : null);

        if ($result['already_applied']) {
            $this->addFlash('info', $this->translator->trans('industry_baseline.flash.already_applied', [], 'industry_baseline'));
        } else {
            $this->addFlash('success', $this->translator->trans(
                'industry_baseline.flash.applied_summary',
                [
                    '%risks%' => $result['risks_created'],
                    '%assets%' => $result['assets_created'],
                    '%controls%' => $result['controls_marked_applicable'],
                ],
                'industry_baseline',
            ));
            if ($result['frameworks_missing'] !== []) {
                $this->addFlash('warning', $this->translator->trans(
                    'industry_baseline.flash.frameworks_missing',
                    ['%frameworks%' => implode(', ', $result['frameworks_missing'])],
                    'industry_baseline',
                ));
            }
        }

        return $this->redirectToRoute('app_industry_baseline_show', ['code' => $baseline->getCode()]);
    }
}
