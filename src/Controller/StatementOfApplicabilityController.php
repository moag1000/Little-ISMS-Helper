<?php

namespace App\Controller;

use App\Entity\Control;
use App\Repository\ControlRepository;
use App\Service\SoAReportService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/soa')]
class StatementOfApplicabilityController extends AbstractController
{
    public function __construct(
        private ControlRepository $controlRepository,
        private EntityManagerInterface $entityManager,
        private TranslatorInterface $translator,
        private SoAReportService $soaReportService
    ) {}

    #[Route('/', name: 'app_soa_index')]
    public function index(): Response
    {
        $controls = $this->controlRepository->findAllInIsoOrder();
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
        $controls = $this->controlRepository->findByCategoryInIsoOrder($category);

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

            $this->addFlash('success', $this->translator->trans('control.success.updated'));

            return $this->redirectToRoute('app_soa_show', ['id' => $control->getId()]);
        }

        return $this->render('soa/edit.html.twig', [
            'control' => $control,
        ]);
    }

    #[Route('/report/export', name: 'app_soa_export')]
    public function export(Request $request): Response
    {
        $controls = $this->controlRepository->findAllInIsoOrder();

        // Close session to prevent blocking other requests
        $request->getSession()->save();

        return $this->render('soa/export.html.twig', [
            'controls' => $controls,
            'generatedAt' => new \DateTime(),
        ]);
    }

    /**
     * Export Statement of Applicability as PDF
     * Phase 6F-C: Professional SoA PDF Report with all 93 controls
     */
    #[Route('/report/pdf', name: 'app_soa_export_pdf')]
    public function exportPdf(Request $request): Response
    {
        // Close session to prevent blocking other requests during PDF generation
        $request->getSession()->save();

        return $this->soaReportService->downloadSoAReport();
    }

    /**
     * Preview SoA PDF Report (inline display)
     * Phase 6F-C: View PDF in browser before downloading
     */
    #[Route('/report/pdf/preview', name: 'app_soa_preview_pdf')]
    public function previewPdf(): Response
    {
        return $this->soaReportService->streamSoAReport();
    }
}
