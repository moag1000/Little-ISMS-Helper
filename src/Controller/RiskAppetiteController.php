<?php

namespace App\Controller;

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

#[Route('/risk-appetite')]
class RiskAppetiteController extends AbstractController
{
    public function __construct(
        private RiskAppetiteRepository $riskAppetiteRepository,
        private RiskRepository $riskRepository,
        private AuditLogRepository $auditLogRepository,
        private EntityManagerInterface $entityManager,
        private TranslatorInterface $translator
    ) {}

    #[Route('/', name: 'app_risk_appetite_index')]
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
                $appetites = array_filter($appetites, fn($appetite) => $appetite->isGlobal());
            } else {
                $appetites = array_filter($appetites, fn($appetite) => $appetite->getCategory() === $category);
            }
        }

        if ($activeOnly === '1') {
            $appetites = array_filter($appetites, fn($appetite) => $appetite->isActive());
        }

        // Re-index array after filtering to avoid gaps in keys
        $appetites = array_values($appetites);

        // Get all risks to show appetite vs actual comparison
        $allRisks = $this->riskRepository->findAll();
        $globalAppetite = $this->riskAppetiteRepository->findGlobalAppetiteForTenant(null);
        $categories = $this->riskAppetiteRepository->findDistinctCategories(null);

        // Calculate risks exceeding appetite
        $risksExceedingAppetite = [];
        if ($globalAppetite) {
            foreach ($allRisks as $risk) {
                if (!$globalAppetite->isRiskAcceptable($risk->getRiskScore())) {
                    $risksExceedingAppetite[] = $risk;
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

    #[Route('/new', name: 'app_risk_appetite_new')]
    #[IsGranted('ROLE_ADMIN')]
    public function new(Request $request): Response
    {
        $appetite = new RiskAppetite();
        $form = $this->createForm(RiskAppetiteType::class, $appetite);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($appetite);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('risk_appetite.success.created'));
            return $this->redirectToRoute('app_risk_appetite_show', ['id' => $appetite->getId()]);
        }

        return $this->render('risk_appetite/new.html.twig', [
            'appetite' => $appetite,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_risk_appetite_show', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function show(RiskAppetite $appetite): Response
    {
        // Get all risks to show which ones exceed this appetite
        $allRisks = $this->riskRepository->findAll();
        $risksExceedingAppetite = [];
        $risksWithinAppetite = [];

        foreach ($allRisks as $risk) {
            if ($appetite->isRiskAcceptable($risk->getRiskScore())) {
                $risksWithinAppetite[] = $risk;
            } else {
                $risksExceedingAppetite[] = $risk;
            }
        }

        // Get audit log history for this appetite (last 10 entries)
        $auditLogs = $this->auditLogRepository->findByEntity('RiskAppetite', $appetite->getId());
        $recentAuditLogs = array_slice($auditLogs, 0, 10);

        return $this->render('risk_appetite/show.html.twig', [
            'appetite' => $appetite,
            'risksExceedingAppetite' => $risksExceedingAppetite,
            'risksWithinAppetite' => $risksWithinAppetite,
            'auditLogs' => $recentAuditLogs,
            'totalAuditLogs' => count($auditLogs),
        ]);
    }

    #[Route('/{id}/edit', name: 'app_risk_appetite_edit', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function edit(Request $request, RiskAppetite $appetite): Response
    {
        $form = $this->createForm(RiskAppetiteType::class, $appetite);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $appetite->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('risk_appetite.success.updated'));
            return $this->redirectToRoute('app_risk_appetite_show', ['id' => $appetite->getId()]);
        }

        return $this->render('risk_appetite/edit.html.twig', [
            'appetite' => $appetite,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_risk_appetite_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, RiskAppetite $appetite): Response
    {
        if ($this->isCsrfTokenValid('delete'.$appetite->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($appetite);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('risk_appetite.success.deleted'));
        }

        return $this->redirectToRoute('app_risk_appetite_index');
    }
}
