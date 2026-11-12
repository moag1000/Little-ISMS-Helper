<?php

declare(strict_types=1);
// @em-write-allowed: one risk appetite per request (create, edit, approve stamp, delete); the audit log runs in Doctrine listeners.

namespace App\Controller;

use App\Attribute\RequiresModule;
use DateTimeImmutable;
use App\Controller\Trait\InPageFormTrait;
use App\Entity\RiskAppetite;
use App\Util\ListPage;
use App\Form\RiskAppetiteType;
use App\Repository\AuditLogRepository;
use App\Repository\RiskAppetiteRepository;
use App\Repository\RiskRepository;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[RequiresModule('risk_appetite')]
class RiskAppetiteController extends AbstractController
{
    use InPageFormTrait;

    public function __construct(
        private readonly RiskAppetiteRepository $riskAppetiteRepository,
        private readonly RiskRepository $riskRepository,
        private readonly AuditLogRepository $auditLogRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly TenantContext $tenantContext,
    ) {}
    #[Route('/risk-appetite', name: 'app_risk_appetite_index', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function index(Request $request): Response
    {
        // Get filter parameters
        $category = $request->query->get('category');
        $activeOnly = $request->query->get('active_only');

        // Get all risk appetites
        $appetites = $this->riskAppetiteRepository->findAll();

        // Apply filters
        if ($category !== null && $category !== '') {
            if ($category === 'global') {
                $appetites = array_filter($appetites, fn(RiskAppetite $riskAppetite): bool => $riskAppetite->isGlobal());
            } else {
                $appetites = array_filter($appetites, fn(RiskAppetite $riskAppetite): bool => $riskAppetite->getCategory() === $category);
            }
        }

        if ($activeOnly === '1') {
            $appetites = array_filter($appetites, fn(RiskAppetite $riskAppetite): bool => $riskAppetite->isActive());
        }

        // Re-index array after filtering to avoid gaps in keys
        $appetites = array_values($appetites);

        // Get all risks to show appetite vs actual comparison
        $allRisks = $this->riskRepository->findAll();
        $globalAppetite = $this->riskAppetiteRepository->findGlobalAppetiteForTenant($this->tenantContext->getCurrentTenant());
        $categories = $this->riskAppetiteRepository->findDistinctCategories($this->tenantContext->getCurrentTenant());

        // Calculate risks exceeding appetite
        $risksExceedingAppetite = [];
        if ($globalAppetite instanceof RiskAppetite) {
            foreach ($allRisks as $allRisk) {
                if (!$globalAppetite->isRiskAcceptable($allRisk->getRiskScore())) {
                    $risksExceedingAppetite[] = $allRisk;
                }
            }
        }

        return $this->render('risk_appetite/index.html.twig', [
            'page' => ListPage::fromRequest(
                $appetites,
                $request,
                static fn(RiskAppetite $a): array => [$a->getCategory(), $a->getDescription()],
            ),
            'active_count' => count(array_filter($appetites, static fn(RiskAppetite $a): bool => $a->isActive())),
            'categories' => $categories,
            'globalAppetite' => $globalAppetite,
            'risksExceedingAppetite' => $risksExceedingAppetite,
        ]);
    }
    #[Route('/risk-appetite/new', name: 'app_risk_appetite_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function new(Request $request): Response
    {
        $riskAppetite = new RiskAppetite();
        // Without a tenant the TenantFilter hides the new row from its own organisation.
        $riskAppetite->setTenant($this->tenantContext->getCurrentTenant());
        $form = $this->createForm(RiskAppetiteType::class, $riskAppetite);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($riskAppetite);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('risk_appetite.success.created', [], 'messages'));

            if ($this->isTurboFrameRequest($request)) {
                return $this->riskAppetiteStreamSave($riskAppetite, isNew: true);
            }
            return $this->redirectToRoute('app_risk_appetite_show', ['id' => $riskAppetite->getId()]);
        }

        $status = ($form->isSubmitted() && !$form->isValid())
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        if ($this->isTurboFrameRequest($request)) {
            return $this->render('risk_appetite/_form_modal.html.twig', [
                'appetite' => $riskAppetite,
                'form' => $form,
            ], new Response(status: $status));
        }

        return $this->render('risk_appetite/new.html.twig', [
            'appetite' => $riskAppetite,
            'form' => $form,
        ], new Response(status: $status));
    }
    #[Route('/risk-appetite/{id}', name: 'app_risk_appetite_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function show(Request $request, RiskAppetite $riskAppetite): Response
    {
        // In-modal → condensed read-only detail; direct URL → full page (fallback).
        if ($this->isTurboFrameRequest($request)) {
            return $this->render('risk_appetite/_detail_modal.html.twig', [
                'appetite' => $riskAppetite,
            ]);
        }

        // Get all risks to show which ones exceed this appetite
        $allRisks = $this->riskRepository->findAll();
        $risksExceedingAppetite = [];
        $risksWithinAppetite = [];

        foreach ($allRisks as $allRisk) {
            if ($riskAppetite->isRiskAcceptable($allRisk->getRiskScore())) {
                $risksWithinAppetite[] = $allRisk;
            } else {
                $risksExceedingAppetite[] = $allRisk;
            }
        }

        // Get audit log history for this appetite (last 10 entries)
        $auditLogs = $this->auditLogRepository->findByEntity('RiskAppetite', $riskAppetite->getId());
        $recentAuditLogs = array_slice($auditLogs, 0, 10);

        return $this->render('risk_appetite/show.html.twig', [
            'appetite' => $riskAppetite,
            'risksExceedingAppetite' => $risksExceedingAppetite,
            'risksWithinAppetite' => $risksWithinAppetite,
            'auditLogs' => $recentAuditLogs,
            'totalAuditLogs' => count($auditLogs),
        ]);
    }
    #[Route('/risk-appetite/{id}/edit', name: 'app_risk_appetite_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function edit(Request $request, RiskAppetite $riskAppetite): Response
    {
        $before = $riskAppetite->valueFingerprint();
        $form = $this->createForm(RiskAppetiteType::class, $riskAppetite);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($riskAppetite->isApproved() && $riskAppetite->valueFingerprint() !== $before) {
                // S34 (R6): the approval vouched for the old values.
                $riskAppetite->withdrawApproval();
                $this->addFlash('warning', $this->translator->trans('risk_appetite.flash.approval_withdrawn', [], 'risk_appetite'));
            }
            $riskAppetite->setUpdatedAt(new DateTimeImmutable());
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('risk_appetite.success.updated', [], 'messages'));

            if ($this->isTurboFrameRequest($request)) {
                return $this->riskAppetiteStreamSave($riskAppetite, isNew: false);
            }
            return $this->redirectToRoute('app_risk_appetite_show', ['id' => $riskAppetite->getId()]);
        }

        $status = ($form->isSubmitted() && !$form->isValid())
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        if ($this->isTurboFrameRequest($request)) {
            return $this->render('risk_appetite/_form_modal.html.twig', [
                'appetite' => $riskAppetite,
                'form' => $form,
            ], new Response(status: $status));
        }

        return $this->render('risk_appetite/edit.html.twig', [
            'appetite' => $riskAppetite,
            'form' => $form,
        ], new Response(status: $status));
    }

    /** Turbo Stream after a successful in-modal RiskAppetite save (row replace/append). */
    private function riskAppetiteStreamSave(RiskAppetite $riskAppetite, bool $isNew): Response
    {
        return $this->render('risk_appetite/_stream_save.html.twig', [
            'appetite' => $riskAppetite,
            'is_new' => $isNew,
        ], new Response(headers: ['Content-Type' => 'text/vnd.turbo-stream.html']));
    }
    /** S34 (R6): the formal approval (ISO 27005, 27001 6.1.2) — a recorded action, never a form field. */
    #[Route('/risk-appetite/{id}/approve', name: 'app_risk_appetite_approve', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(new Expression('is_granted("ROLE_TOP_MGMT") or is_granted("ROLE_CISO")'))]
    #[IsCsrfTokenValid(new Expression('"risk_appetite_approve_" ~ args["riskAppetite"].getId()'))]
    public function approve(RiskAppetite $riskAppetite): Response
    {
        $user = $this->getUser();
        if ($user instanceof \App\Entity\User) {
            $riskAppetite->approve($user, new DateTimeImmutable());
            $riskAppetite->setUpdatedAt(new DateTimeImmutable());
            $this->entityManager->flush();
            $this->addFlash('success', $this->translator->trans('risk_appetite.flash.approved', [], 'risk_appetite'));
        }

        return $this->redirectToRoute('app_risk_appetite_show', ['id' => $riskAppetite->getId()]);
    }

    #[Route('/risk-appetite/{id}/delete', name: 'app_risk_appetite_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, RiskAppetite $riskAppetite): Response
    {
        if ($this->isCsrfTokenValid('delete'.$riskAppetite->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($riskAppetite);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('risk_appetite.success.deleted', [], 'messages'));
        }

        return $this->redirectToRoute('app_risk_appetite_index');
    }
}
