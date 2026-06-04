<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\CurrentUserTrait;
use App\Controller\Trait\ModuleGatedControllerTrait;
use App\Entity\AccessReviewCampaign;
use App\Entity\AccessReviewItem;
use App\Form\AccessReviewCampaignType;
use App\Repository\AccessReviewCampaignRepository;
use App\Repository\AccessReviewItemRepository;
use App\Service\AccessReviewCampaignService;
use App\Service\ModuleConfigurationService;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Access Review Controller — User-Access-Recertification (UAR) campaigns.
 *
 * ISO 27001 A.5.18 / A.8.2, NIS2 Art. 21(2)(e), BSI ORP.4.
 *
 * Routes are locale-prefixed (/{_locale}/access-review/…) following the
 * application-wide route convention.
 *
 * Module gate: access_review — checked at the top of every action.
 */
#[Route('/access-review', name: 'app_access_review_')]
#[IsGranted('ROLE_MANAGER')]
class AccessReviewController extends AbstractController
{
    use CurrentUserTrait;
    use ModuleGatedControllerTrait;

    public function __construct(
        private readonly AccessReviewCampaignService    $campaignService,
        private readonly AccessReviewCampaignRepository $campaignRepository,
        private readonly AccessReviewItemRepository     $itemRepository,
        private readonly TenantContext                  $tenantContext,
        private readonly ModuleConfigurationService     $moduleService,
        private readonly TranslatorInterface            $translator,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // Campaign list
    // ─────────────────────────────────────────────────────────────────────────

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if ($redirect = $this->checkModuleActive('access_review')) {
            return $redirect;
        }

        $tenant    = $this->tenantContext->getCurrentTenant();
        $campaigns = $tenant
            ? $this->campaignRepository->findByTenant($tenant)
            : [];

        return $this->render('access_review/index.html.twig', [
            'campaigns' => $campaigns,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // New campaign — GET renders form, POST creates
    // ─────────────────────────────────────────────────────────────────────────

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        if ($redirect = $this->checkModuleActive('access_review')) {
            return $redirect;
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            $this->addFlash('danger', $this->translator->trans('common.tenant_required', [], 'messages'));
            return $this->redirectToRoute('app_access_review_index', ['_locale' => $request->getLocale()]);
        }

        $campaign = new AccessReviewCampaign();
        $form     = $this->createForm(AccessReviewCampaignType::class, $campaign);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->campaignService->createCampaign(
                scope:   $campaign->getScope(),
                dueDate: $campaign->getDueDate(),
                creator: $this->currentUser(),
                name:    (string) $campaign->getName(),
                tenant:  $tenant,
            );

            $this->addFlash('success', $this->translator->trans(
                'access_review.flash.campaign_created',
                [],
                'access_review',
            ));

            return $this->redirectToRoute('app_access_review_index', ['_locale' => $request->getLocale()]);
        }

        return $this->render('access_review/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Campaign show — items table + approval stages
    // ─────────────────────────────────────────────────────────────────────────

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id, Request $request): Response
    {
        if ($redirect = $this->checkModuleActive('access_review')) {
            return $redirect;
        }

        $tenant   = $this->tenantContext->getCurrentTenant();
        $campaign = $tenant
            ? $this->campaignRepository->findOneForTenant($id, $tenant)
            : null;

        if ($campaign === null) {
            throw $this->createNotFoundException();
        }

        $items = $this->itemRepository->findByCampaign($campaign);

        // Build stages array for _isms_approval_stages
        $pendingCount  = 0;
        $approvedCount = 0;
        $revokedCount  = 0;
        foreach ($items as $item) {
            match ($item->getDecision()) {
                AccessReviewItem::DECISION_PENDING   => $pendingCount++,
                AccessReviewItem::DECISION_APPROVED  => $approvedCount++,
                AccessReviewItem::DECISION_REVOKED,
                AccessReviewItem::DECISION_ESCALATED => $revokedCount++,
                default => null,
            };
        }
        $total = count($items);

        $stages = [
            [
                'name'        => $this->translator->trans('access_review.stage.campaign_open', [], 'access_review'),
                'state'       => 'completed',
                'description' => $this->translator->trans('access_review.stage.campaign_open_desc', ['%total%' => $total], 'access_review'),
            ],
            [
                'name'        => $this->translator->trans('access_review.stage.review_in_progress', [], 'access_review'),
                'state'       => $campaign->isOpen() ? 'current' : 'completed',
                'description' => $this->translator->trans('access_review.stage.review_in_progress_desc', ['%pending%' => $pendingCount, '%total%' => $total], 'access_review'),
                'badge'       => $pendingCount > 0
                    ? $this->translator->trans('access_review.stage.badge_pending', ['%count%' => $pendingCount], 'access_review')
                    : null,
                'badge_variant' => $pendingCount > 0 ? 'warning' : 'success',
            ],
            [
                'name'        => $this->translator->trans('access_review.stage.campaign_closed', [], 'access_review'),
                'state'       => $campaign->isClosed() ? 'completed' : 'pending',
                'description' => $campaign->isClosed()
                    ? $this->translator->trans('access_review.stage.campaign_closed_desc', ['%approved%' => $approvedCount, '%revoked%' => $revokedCount], 'access_review')
                    : null,
            ],
        ];

        return $this->render('access_review/show.html.twig', [
            'campaign'      => $campaign,
            'items'         => $items,
            'stages'        => $stages,
            'pendingCount'  => $pendingCount,
            'approvedCount' => $approvedCount,
            'revokedCount'  => $revokedCount,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Per-item decision — POST only, CSRF-protected
    // ─────────────────────────────────────────────────────────────────────────

    #[Route('/{campaignId}/item/{itemId}/decide', name: 'item_decide',
        methods: ['POST'],
        requirements: ['campaignId' => '\d+', 'itemId' => '\d+'],
    )]
    #[IsCsrfTokenValid('access_review_decide')]
    public function decide(int $campaignId, int $itemId, Request $request): Response
    {
        if ($redirect = $this->checkModuleActive('access_review')) {
            return $redirect;
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            throw $this->createAccessDeniedException();
        }

        $campaign = $this->campaignRepository->findOneForTenant($campaignId, $tenant);
        $item     = $this->itemRepository->findOneForTenant($itemId, $tenant);

        if ($campaign === null || $item === null || $item->getCampaign()?->getId() !== $campaignId) {
            throw $this->createNotFoundException();
        }

        if (!$campaign->isOpen()) {
            $this->addFlash('warning', $this->translator->trans('access_review.flash.campaign_already_closed', [], 'access_review'));
            return $this->redirectToRoute('app_access_review_show', [
                '_locale' => $request->getLocale(),
                'id'      => $campaignId,
            ]);
        }

        $decision = $request->request->get('decision', '');
        if (!in_array($decision, AccessReviewItem::ALLOWED_DECISIONS, true) || $decision === AccessReviewItem::DECISION_PENDING) {
            $this->addFlash('danger', $this->translator->trans('access_review.flash.invalid_decision', [], 'access_review'));
            return $this->redirectToRoute('app_access_review_show', [
                '_locale' => $request->getLocale(),
                'id'      => $campaignId,
            ]);
        }

        $comment = $request->request->get('comment');

        $this->campaignService->decide($item, $decision, $this->currentUser(), $comment ?: null);

        $this->addFlash('success', $this->translator->trans(
            'access_review.flash.decision_saved',
            ['%decision%' => $this->translator->trans('access_review.decision.' . $decision, [], 'access_review')],
            'access_review',
        ));

        return $this->redirectToRoute('app_access_review_show', [
            '_locale' => $request->getLocale(),
            'id'      => $campaignId,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Close campaign — POST only, CSRF-protected
    // ─────────────────────────────────────────────────────────────────────────

    #[Route('/{id}/close', name: 'close', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsCsrfTokenValid('access_review_close')]
    public function close(int $id, Request $request): Response
    {
        if ($redirect = $this->checkModuleActive('access_review')) {
            return $redirect;
        }

        $tenant   = $this->tenantContext->getCurrentTenant();
        $campaign = $tenant
            ? $this->campaignRepository->findOneForTenant($id, $tenant)
            : null;

        if ($campaign === null) {
            throw $this->createNotFoundException();
        }

        $this->campaignService->close($campaign, $this->currentUser());

        $this->addFlash('success', $this->translator->trans('access_review.flash.campaign_closed', [], 'access_review'));

        return $this->redirectToRoute('app_access_review_show', [
            '_locale' => $request->getLocale(),
            'id'      => $id,
        ]);
    }
}
