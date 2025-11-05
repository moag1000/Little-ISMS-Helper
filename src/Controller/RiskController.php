<?php

namespace App\Controller;

use App\Entity\Risk;
use App\Form\RiskType;
use App\Repository\RiskRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/risk')]
class RiskController extends AbstractController
{
    public function __construct(
        private RiskRepository $riskRepository,
        private EntityManagerInterface $entityManager
    ) {}

    #[Route('/', name: 'app_risk_index')]
    #[IsGranted('ROLE_USER')]
    public function index(): Response
    {
        $risks = $this->riskRepository->findAll();
        $highRisks = $this->riskRepository->findHighRisks();
        $treatmentStats = $this->riskRepository->countByTreatmentStrategy();

        return $this->render('risk/index.html.twig', [
            'risks' => $risks,
            'highRisks' => $highRisks,
            'treatmentStats' => $treatmentStats,
        ]);
    }

    #[Route('/new', name: 'app_risk_new')]
    #[IsGranted('ROLE_USER')]
    public function new(Request $request): Response
    {
        $risk = new Risk();
        $form = $this->createForm(RiskType::class, $risk);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($risk);
            $this->entityManager->flush();

            $this->addFlash('success', 'Risk created successfully.');
            return $this->redirectToRoute('app_risk_show', ['id' => $risk->getId()]);
        }

        return $this->render('risk/new.html.twig', [
            'risk' => $risk,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_risk_show', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function show(Risk $risk): Response
    {
        return $this->render('risk/show.html.twig', [
            'risk' => $risk,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_risk_edit', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function edit(Request $request, Risk $risk): Response
    {
        $form = $this->createForm(RiskType::class, $risk);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', 'Risk updated successfully.');
            return $this->redirectToRoute('app_risk_show', ['id' => $risk->getId()]);
        }

        return $this->render('risk/edit.html.twig', [
            'risk' => $risk,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_risk_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, Risk $risk): Response
    {
        if ($this->isCsrfTokenValid('delete'.$risk->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($risk);
            $this->entityManager->flush();

            $this->addFlash('success', 'Risk deleted successfully.');
        }

        return $this->redirectToRoute('app_risk_index');
    }
}
