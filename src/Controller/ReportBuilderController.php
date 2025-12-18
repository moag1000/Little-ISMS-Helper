<?php

namespace App\Controller;

use App\Entity\CustomReport;
use App\Entity\User;
use App\Repository\CustomReportRepository;
use App\Repository\UserRepository;
use App\Service\ReportBuilderService;
use App\Service\TenantContext;
use App\Service\PdfExportService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Report Builder Controller
 *
 * Phase 7C: Manages custom report creation, editing, and generation.
 * Provides drag & drop visual designer for building custom reports.
 */
#[Route('/report-builder')]
#[IsGranted('ROLE_USER')]
class ReportBuilderController extends AbstractController
{
    public function __construct(
        private readonly ReportBuilderService $reportBuilderService,
        private readonly CustomReportRepository $customReportRepository,
        private readonly TenantContext $tenantContext,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly UserRepository $userRepository,
        private readonly PdfExportService $pdfExportService,
    ) {
    }

    /**
     * Report Builder Index - List user's reports
     */
    #[Route('', name: 'report_builder_index', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->getUser();
        $tenantId = $this->tenantContext->getCurrentTenantId();

        $reports = $this->customReportRepository->findOwnedByUser($user, $tenantId);
        $favorites = $this->customReportRepository->findFavoritesByUser($user, $tenantId);
        $recentlyUsed = $this->customReportRepository->findRecentlyUsed($user, $tenantId);
        $templates = $this->customReportRepository->findAvailableTemplates($tenantId);
        $predefinedTemplates = $this->reportBuilderService->getPredefinedTemplates();

        return $this->render('report_builder/index.html.twig', [
            'reports' => $reports,
            'favorites' => $favorites,
            'recently_used' => $recentlyUsed,
            'templates' => $templates,
            'predefined_templates' => $predefinedTemplates,
            'categories' => CustomReport::getCategories(),
        ]);
    }

    /**
     * Create new custom report
     */
    #[Route('/new', name: 'report_builder_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $user = $this->getUser();
        $tenantId = $this->tenantContext->getCurrentTenantId();

        if ($request->isMethod('POST')) {
            $data = $request->request->all();

            $report = new CustomReport();
            $report->setName($data['name'] ?? 'New Report');
            $report->setDescription($data['description'] ?? null);
            $report->setCategory($data['category'] ?? CustomReport::CATEGORY_GENERAL);
            $report->setLayout($data['layout'] ?? CustomReport::LAYOUT_DASHBOARD);
            $report->setOwner($user);
            $report->setTenantId($tenantId);

            $this->entityManager->persist($report);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('report_builder.created', [], 'report_builder'));

            return $this->redirectToRoute('report_builder_edit', ['id' => $report->getId()]);
        }

        return $this->render('report_builder/new.html.twig', [
            'categories' => CustomReport::getCategories(),
            'layouts' => CustomReport::getLayouts(),
        ]);
    }

    /**
     * Create report from template
     */
    #[Route('/from-template/{templateKey}', name: 'report_builder_from_template', methods: ['GET'])]
    public function createFromTemplate(string $templateKey): Response
    {
        $user = $this->getUser();
        $tenantId = $this->tenantContext->getCurrentTenantId();

        $report = $this->reportBuilderService->createFromTemplate($templateKey, $user, $tenantId);

        if (!$report) {
            $this->addFlash('error', $this->translator->trans('report_builder.template_not_found', [], 'report_builder'));
            return $this->redirectToRoute('report_builder_index');
        }

        $this->entityManager->persist($report);
        $this->entityManager->flush();

        $this->addFlash('success', $this->translator->trans('report_builder.created_from_template', [], 'report_builder'));

        return $this->redirectToRoute('report_builder_edit', ['id' => $report->getId()]);
    }

    /**
     * Clone an existing report
     */
    #[Route('/{id}/clone', name: 'report_builder_clone', methods: ['POST'])]
    public function clone(CustomReport $report): Response
    {
        $user = $this->getUser();

        if (!$report->canAccess($user)) {
            throw $this->createAccessDeniedException();
        }

        $clone = $report->cloneAsNew($user);
        $this->entityManager->persist($clone);
        $this->entityManager->flush();

        $this->addFlash('success', $this->translator->trans('report_builder.cloned', [], 'report_builder'));

        return $this->redirectToRoute('report_builder_edit', ['id' => $clone->getId()]);
    }

    /**
     * Visual Report Designer
     */
    #[Route('/{id}/edit', name: 'report_builder_edit', methods: ['GET'])]
    public function edit(CustomReport $report): Response
    {
        $user = $this->getUser();

        if (!$report->canAccess($user) && $report->getOwner() !== $user) {
            throw $this->createAccessDeniedException();
        }

        $widgetLibrary = $this->reportBuilderService->getWidgetLibrary();

        return $this->render('report_builder/designer.html.twig', [
            'report' => $report,
            'widget_library' => $widgetLibrary,
            'categories' => CustomReport::getCategories(),
            'layouts' => CustomReport::getLayouts(),
        ]);
    }

    /**
     * Save report configuration (AJAX)
     */
    #[Route('/{id}/save', name: 'report_builder_save', methods: ['POST'])]
    public function save(CustomReport $report, Request $request): JsonResponse
    {
        $user = $this->getUser();

        if ($report->getOwner() !== $user) {
            return new JsonResponse(['error' => 'Access denied'], 403);
        }

        $data = json_decode($request->getContent(), true);

        if (isset($data['name'])) {
            $report->setName($data['name']);
        }
        if (isset($data['description'])) {
            $report->setDescription($data['description']);
        }
        if (isset($data['category'])) {
            $report->setCategory($data['category']);
        }
        if (isset($data['layout'])) {
            $report->setLayout($data['layout']);
        }
        if (isset($data['widgets'])) {
            $report->setWidgets($data['widgets']);
        }
        if (isset($data['filters'])) {
            $report->setFilters($data['filters']);
        }
        if (isset($data['styles'])) {
            $report->setStyles($data['styles']);
        }

        $report->incrementVersion();
        $this->entityManager->flush();

        return new JsonResponse([
            'success' => true,
            'version' => $report->getVersion(),
            'message' => $this->translator->trans('report_builder.saved', [], 'report_builder'),
        ]);
    }

    /**
     * Preview report
     */
    #[Route('/{id}/preview', name: 'report_builder_preview', methods: ['GET'])]
    public function preview(CustomReport $report): Response
    {
        $user = $this->getUser();

        if (!$report->canAccess($user) && $report->getOwner() !== $user) {
            throw $this->createAccessDeniedException();
        }

        $reportData = $this->reportBuilderService->generateReportData($report);
        $report->incrementUsageCount();
        $this->entityManager->flush();

        return $this->render('report_builder/preview.html.twig', [
            'report' => $report,
            'report_data' => $reportData,
        ]);
    }

    /**
     * Export report as PDF
     */
    #[Route('/{id}/export/pdf', name: 'report_builder_export_pdf', methods: ['GET'])]
    public function exportPdf(CustomReport $report): Response
    {
        $user = $this->getUser();

        if (!$report->canAccess($user) && $report->getOwner() !== $user) {
            throw $this->createAccessDeniedException();
        }

        $reportData = $this->reportBuilderService->generateReportData($report);
        $report->incrementUsageCount();
        $this->entityManager->flush();

        $filename = sprintf('%s_%s.pdf',
            preg_replace('/[^a-zA-Z0-9_-]/', '_', $report->getName()),
            date('Y-m-d')
        );

        $pdfContent = $this->pdfExportService->generatePdf('report_builder/pdf.html.twig', [
            'report' => $report,
            'report_data' => $reportData,
        ], [
            'orientation' => $report->getStyles()['pageOrientation'] ?? 'portrait',
        ]);

        return new Response($pdfContent, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * Get widget data (AJAX)
     */
    #[Route('/api/widget-data', name: 'report_builder_widget_data', methods: ['POST'])]
    public function getWidgetData(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        $widgetType = $data['type'] ?? '';
        $config = $data['config'] ?? [];
        $filters = $data['filters'] ?? [];

        $widgetData = $this->reportBuilderService->getWidgetData($widgetType, $config, $filters);

        return new JsonResponse($widgetData);
    }

    /**
     * Get widget library (AJAX)
     */
    #[Route('/api/widget-library', name: 'report_builder_widget_library', methods: ['GET'])]
    public function getWidgetLibrary(): JsonResponse
    {
        $library = $this->reportBuilderService->getWidgetLibrary();
        return new JsonResponse($library);
    }

    /**
     * Toggle favorite status
     */
    #[Route('/{id}/favorite', name: 'report_builder_toggle_favorite', methods: ['POST'])]
    public function toggleFavorite(CustomReport $report): JsonResponse
    {
        $user = $this->getUser();

        if ($report->getOwner() !== $user) {
            return new JsonResponse(['error' => 'Access denied'], 403);
        }

        $report->setIsFavorite(!$report->isFavorite());
        $this->entityManager->flush();

        return new JsonResponse([
            'success' => true,
            'is_favorite' => $report->isFavorite(),
        ]);
    }

    /**
     * Share report with users
     */
    #[Route('/{id}/share', name: 'report_builder_share', methods: ['POST'])]
    public function share(CustomReport $report, Request $request): JsonResponse
    {
        $user = $this->getUser();

        if ($report->getOwner() !== $user) {
            return new JsonResponse(['error' => 'Access denied'], 403);
        }

        $data = json_decode($request->getContent(), true);
        $userIds = $data['user_ids'] ?? [];

        $report->setSharedWith($userIds);
        $report->setIsShared(!empty($userIds));
        $this->entityManager->flush();

        return new JsonResponse([
            'success' => true,
            'shared_with' => $report->getSharedWith(),
        ]);
    }

    /**
     * Save as template
     */
    #[Route('/{id}/save-as-template', name: 'report_builder_save_template', methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function saveAsTemplate(CustomReport $report, Request $request): JsonResponse
    {
        $user = $this->getUser();

        if ($report->getOwner() !== $user) {
            return new JsonResponse(['error' => 'Access denied'], 403);
        }

        $data = json_decode($request->getContent(), true);

        $template = $report->cloneAsNew($user);
        $template->setName($data['name'] ?? $report->getName() . ' (Template)');
        $template->setDescription($data['description'] ?? $report->getDescription());
        $template->setIsTemplate(true);
        $template->setIsShared($data['shared'] ?? true);

        $this->entityManager->persist($template);
        $this->entityManager->flush();

        return new JsonResponse([
            'success' => true,
            'template_id' => $template->getId(),
            'message' => $this->translator->trans('report_builder.template_created', [], 'report_builder'),
        ]);
    }

    /**
     * Delete report
     */
    #[Route('/{id}/delete', name: 'report_builder_delete', methods: ['POST'])]
    public function delete(CustomReport $report): Response
    {
        $user = $this->getUser();

        if ($report->getOwner() !== $user) {
            throw $this->createAccessDeniedException();
        }

        $this->entityManager->remove($report);
        $this->entityManager->flush();

        $this->addFlash('success', $this->translator->trans('report_builder.deleted', [], 'report_builder'));

        return $this->redirectToRoute('report_builder_index');
    }

    /**
     * Get shareable users list (AJAX)
     */
    #[Route('/api/users', name: 'report_builder_users', methods: ['GET'])]
    public function getUsers(): JsonResponse
    {
        $tenantId = $this->tenantContext->getCurrentTenantId();
        $currentUser = $this->getUser();

        $users = $this->userRepository->findBy(['tenantId' => $tenantId]);

        $result = [];
        foreach ($users as $user) {
            if ($user->getId() !== $currentUser->getId()) {
                $result[] = [
                    'id' => $user->getId(),
                    'name' => $user->getFullName(),
                    'email' => $user->getEmail(),
                ];
            }
        }

        return new JsonResponse($result);
    }
}
