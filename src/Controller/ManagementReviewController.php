<?php

declare(strict_types=1);

namespace App\Controller;

use DateTimeInterface;
use DateTimeImmutable;
use App\Entity\ManagementReview;
use App\Enum\ManagementReviewStatus;
use App\Form\ManagementReviewType;
use App\Repository\ManagementReviewRepository;
use App\Service\ManagementReportService;
use App\Service\PdfExportService;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

class ManagementReviewController extends AbstractController
{
    public function __construct(
        private readonly ManagementReviewRepository $managementReviewRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly TenantContext $tenantContext,
        private readonly PdfExportService $pdfExportService,
        private readonly ManagementReportService $managementReportService,
        private readonly Security $security,
    ) {}
    #[Route('/management-review/', name: 'app_management_review_index', methods: ['GET'])]
    public function index(): Response
    {
        $reviews = $this->managementReviewRepository->findAll();

        // Calculate statistics
        $statistics = [
            'total' => count($reviews),
            'planned' => count($this->managementReviewRepository->findBy(['status' => ManagementReviewStatus::Planned->value])),
            'completed' => count($this->managementReviewRepository->findBy(['status' => ManagementReviewStatus::Completed->value])),
            'this_year' => count(array_filter($reviews, fn(ManagementReview $review): bool => $review->getReviewDate() instanceof DateTimeInterface &&
                   $review->getReviewDate()->format('Y') === date('Y'))),
        ];

        return $this->render('management_review/index.html.twig', [
            'reviews' => $reviews,
            'statistics' => $statistics,
        ]);
    }
    #[Route('/management-review/new', name: 'app_management_review_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function new(Request $request): Response
    {
        $managementReview = new ManagementReview();
        $managementReview->setTenant($this->tenantContext->getCurrentTenant());

        $form = $this->createForm(ManagementReviewType::class, $managementReview);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $managementReview->setUpdatedAt(new DateTimeImmutable());
            $this->entityManager->persist($managementReview);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('management_review.success.created'));
            return $this->redirectToRoute('app_management_review_show', ['id' => $managementReview->getId()]);
        }

        return $this->render('management_review/new.html.twig', [
            'review' => $managementReview,
            'form' => $form,
        ]);
    }
    #[Route('/management-review/{id}', name: 'app_management_review_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(ManagementReview $managementReview): Response
    {
        return $this->render('management_review/show.html.twig', [
            'review' => $managementReview,
        ]);
    }
    #[Route('/management-review/{id}/edit', name: 'app_management_review_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function edit(Request $request, ManagementReview $managementReview): Response
    {
        $form = $this->createForm(ManagementReviewType::class, $managementReview);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $managementReview->setUpdatedAt(new DateTimeImmutable());
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('management_review.success.updated'));
            return $this->redirectToRoute('app_management_review_show', ['id' => $managementReview->getId()]);
        }

        return $this->render('management_review/edit.html.twig', [
            'review' => $managementReview,
            'form' => $form,
        ]);
    }
    #[Route('/management-review/{id}/pdf', name: 'app_management_review_pdf', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_MANAGER')]
    public function pdf(ManagementReview $managementReview): Response
    {
        $generatedAt = new DateTimeImmutable();

        $pdf = $this->pdfExportService->generatePdf(
            'management_review/pdf/report.html.twig',
            [
                'review' => $managementReview,
                'generated_at' => $generatedAt,
            ],
            [
                'classification' => $managementReview->getTenant()?->getName(),
            ]
        );

        $dateSlug = $managementReview->getReviewDate()?->format('Y-m-d') ?? $generatedAt->format('Y-m-d');
        $titleSlug = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $managementReview->getTitle()) ?? 'management-review';
        $filename = sprintf('management-review_%s_%s.pdf', $dateSlug, trim($titleSlug, '-') ?: 'review');

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * V3 B4 / EF-1: Auto-Collect ein Management-Review aus existierender §9.3-Datenlage.
     * Pre-fills Risk-/Audit-/NC-/KPI-Narratives, anschliessend redirect auf edit.
     */
    #[Route('/management-review/auto-collect', name: 'app_management_review_auto_collect', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function autoCollect(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('auto_collect_review', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_management_review_index');
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            $this->addFlash('error', $this->translator->trans('common.error.no_tenant', [], 'messages'));
            return $this->redirectToRoute('app_management_review_index');
        }

        $referenceDate = new DateTimeImmutable();
        $locale = $request->getLocale() === 'de' ? 'de' : 'en';

        $review = $this->managementReportService->createManagementReviewFromReport(
            $tenant,
            $referenceDate,
            $locale
        );

        $this->addFlash('success', $this->translator->trans('management_review.auto_collect.success', [], 'management_review'));
        return $this->redirectToRoute('app_management_review_edit', ['id' => $review->getId()]);
    }

    #[Route('/management-review/{id}/delete', name: 'app_management_review_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, ManagementReview $managementReview): Response
    {
        if ($this->isCsrfTokenValid('delete'.$managementReview->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($managementReview);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('management_review.success.deleted'));
        }

        return $this->redirectToRoute('app_management_review_index');
    }

    #[Route('/management-review/bulk-delete', name: 'app_management_review_bulk_delete', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function bulkDelete(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $ids = $data['ids'] ?? [];

        if (empty($ids)) {
            return $this->json(['error' => 'No items selected'], 400);
        }

        $tenant = $this->security->getUser()?->getTenant();
        $deleted = 0;
        $errors = [];

        foreach ($ids as $id) {
            try {
                $review = $this->managementReviewRepository->find($id);
                if (!$review) {
                    $errors[] = "ManagementReview ID $id not found";
                    continue;
                }
                if ($tenant && $review->getTenant() !== $tenant) {
                    $errors[] = "ManagementReview ID $id does not belong to your organization";
                    continue;
                }
                $this->entityManager->remove($review);
                $deleted++;
            } catch (Exception $e) {
                $errors[] = "Error deleting ManagementReview ID $id: " . $e->getMessage();
            }
        }

        if ($deleted > 0) {
            $this->entityManager->flush();
        }

        return $this->json([
            'success' => $deleted > 0,
            'deleted' => $deleted,
            'errors' => $errors,
            'message' => "$deleted management reviews deleted successfully",
        ]);
    }
}
