<?php

namespace App\Controller;

use DateTimeImmutable;
use App\Entity\RiskAppetite;
use App\Form\RiskAppetiteType;
use App\Repository\AuditLogRepository;
use App\Repository\RiskAppetiteRepository;
use App\Repository\RiskRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

class RiskAppetiteController extends AbstractController
{
    public function __construct(
        private readonly RiskAppetiteRepository $riskAppetiteRepository,
        private readonly RiskRepository $riskRepository,
        private readonly AuditLogRepository $auditLogRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator
    ) {}
    #[Route('/risk-appetite/', name: 'app_risk_appetite_index')]
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
        $globalAppetite = $this->riskAppetiteRepository->findGlobalAppetiteForTenant(null);
        $categories = $this->riskAppetiteRepository->findDistinctCategories(null);

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
            'appetites' => $appetites,
            'categories' => $categories,
            'globalAppetite' => $globalAppetite,
            'totalRisks' => count($allRisks),
            'risksExceedingAppetite' => $risksExceedingAppetite,
        ]);
    }
    #[Route('/risk-appetite/new', name: 'app_risk_appetite_new')]
    #[IsGranted('ROLE_ADMIN')]
    public function new(Request $request): Response
    {
        $riskAppetite = new RiskAppetite();
        $form = $this->createForm(RiskAppetiteType::class, $riskAppetite);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($riskAppetite);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('risk_appetite.success.created'));
            return $this->redirectToRoute('app_risk_appetite_show', ['id' => $riskAppetite->getId()]);
        }

        return $this->render('risk_appetite/new.html.twig', [
            'appetite' => $riskAppetite,
            'form' => $form,
        ]);
    }
    #[Route('/risk-appetite/{id}', name: 'app_risk_appetite_show', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function show(RiskAppetite $riskAppetite): Response
    {
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
    #[Route('/risk-appetite/{id}/edit', name: 'app_risk_appetite_edit', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function edit(Request $request, RiskAppetite $riskAppetite): Response
    {
        $form = $this->createForm(RiskAppetiteType::class, $riskAppetite);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $riskAppetite->setUpdatedAt(new DateTimeImmutable());
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('risk_appetite.success.updated'));
            return $this->redirectToRoute('app_risk_appetite_show', ['id' => $riskAppetite->getId()]);
        }

        return $this->render('risk_appetite/edit.html.twig', [
            'appetite' => $riskAppetite,
            'form' => $form,
        ]);
    }
    #[Route('/risk-appetite/{id}/delete', name: 'app_risk_appetite_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, RiskAppetite $riskAppetite): Response
    {
        if ($this->isCsrfTokenValid('delete'.$riskAppetite->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($riskAppetite);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('risk_appetite.success.deleted'));
        }

        return $this->redirectToRoute('app_risk_appetite_index');
    }
}
