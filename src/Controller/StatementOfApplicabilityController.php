<?php

namespace App\Controller;

use App\Entity\Control;
use App\Repository\ControlRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/soa')]
class StatementOfApplicabilityController extends AbstractController
{
    public function __construct(
        private ControlRepository $controlRepository,
        private EntityManagerInterface $entityManager
    ) {}

    #[Route('/', name: 'app_soa_index')]
    public function index(): Response
    {
        $controls = $this->controlRepository->findAll();
        $stats = $this->controlRepository->getImplementationStats();
        $categoryStats = $this->controlRepository->countByCategory();

        return $this->render('soa/index.html.twig', [
            'controls' => $controls,
            'stats' => $stats,
            'categoryStats' => $categoryStats,
        ]);
    }

    #[Route('/category/{category}', name: 'app_soa_by_category')]
    public function byCategory(string $category): Response
    {
        $controls = $this->controlRepository->findBy(['category' => $category], ['controlId' => 'ASC']);

        return $this->render('soa/category.html.twig', [
            'category' => $category,
            'controls' => $controls,
        ]);
    }

    #[Route('/{id}', name: 'app_soa_show')]
    public function show(Control $control): Response
    {
        return $this->render('soa/show.html.twig', [
            'control' => $control,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_soa_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Control $control): Response
    {
        if ($request->isMethod('POST')) {
            $control->setApplicable($request->request->get('applicable') === '1');
            $control->setJustification($request->request->get('justification'));
            $control->setImplementationStatus($request->request->get('implementationStatus'));
            $control->setImplementationPercentage((int)$request->request->get('implementationPercentage'));
            $control->setImplementationNotes($request->request->get('implementationNotes'));
            $control->setResponsiblePerson($request->request->get('responsiblePerson'));

            if ($request->request->get('targetDate')) {
                $control->setTargetDate(new \DateTime($request->request->get('targetDate')));
            }

            $control->setUpdatedAt(new \DateTime());

            $this->entityManager->flush();

            $this->addFlash('success', 'Control erfolgreich aktualisiert.');

            return $this->redirectToRoute('app_soa_show', ['id' => $control->getId()]);
        }

        return $this->render('soa/edit.html.twig', [
            'control' => $control,
        ]);
    }

    #[Route('/report/export', name: 'app_soa_export')]
    public function export(): Response
    {
        $controls = $this->controlRepository->findAll();

        return $this->render('soa/export.html.twig', [
            'controls' => $controls,
            'generatedAt' => new \DateTime(),
        ]);
    }
}
