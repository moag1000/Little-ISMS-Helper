<?php

namespace App\Controller;

use DateTime;
use Exception;
use App\Entity\InternalAudit;
use App\Form\InternalAuditType;
use App\Repository\AuditLogRepository;
use App\Repository\InternalAuditRepository;
use App\Service\PdfExportService;
use App\Service\ExcelExportService;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

class AuditController extends AbstractController
{
    public function __construct(
        private readonly InternalAuditRepository $internalAuditRepository,
        private readonly AuditLogRepository $auditLogRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly PdfExportService $pdfExportService,
        private readonly ExcelExportService $excelExportService,
        private readonly TranslatorInterface $translator,
        private readonly TenantContext $tenantContext
    ) {}
    #[Route('/audit/', name: 'app_audit_index')]
    public function index(Request $request): Response
    {
        // Get filter parameters
        $status = $request->query->get('status');
        $scopeType = $request->query->get('scope_type');
        $dateFrom = $request->query->get('date_from');
        $dateTo = $request->query->get('date_to');

        // Get all audits
        $allAudits = $this->internalAuditRepository->findAll();

        // Apply filters
        if ($status) {
            $allAudits = array_filter($allAudits, fn(InternalAudit $audit): bool => $audit->getStatus() === $status);
        }

        if ($scopeType) {
            $allAudits = array_filter($allAudits, fn(InternalAudit $audit): bool => $audit->getScopeType() === $scopeType);
        }

        if ($dateFrom) {
            $dateFromObj = new DateTime($dateFrom);
            $allAudits = array_filter($allAudits, fn(InternalAudit $audit): bool => $audit->getPlannedDate() >= $dateFromObj);
        }

        if ($dateTo) {
            $dateToObj = new DateTime($dateTo);
            $allAudits = array_filter($allAudits, fn(InternalAudit $audit): bool => $audit->getPlannedDate() <= $dateToObj);
        }

        // Re-index array after filtering to avoid gaps in keys
        $allAudits = array_values($allAudits);

        $upcoming = $this->internalAuditRepository->findUpcoming();

        return $this->render('audit/index.html.twig', [
            'audits' => $allAudits,
            'upcoming' => $upcoming,
        ]);
    }
    #[Route('/audit/new', name: 'app_audit_new')]
    #[IsGranted('ROLE_USER')]
    public function new(Request $request): Response
    {
        $internalAudit = new InternalAudit();
        $internalAudit->setTenant($this->tenantContext->getCurrentTenant());
        // Auto-generate audit number before form handling (required for validation)
        $internalAudit->setAuditNumber($this->generateAuditNumber());

        $form = $this->createForm(InternalAuditType::class, $internalAudit);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($internalAudit);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('audit.success.created'));
            return $this->redirectToRoute('app_audit_show', ['id' => $internalAudit->getId()]);
        }

        return $this->render('audit/new.html.twig', [
            'audit' => $internalAudit,
            'form' => $form,
        ]);
    }
    #[Route('/audit/export/excel', name: 'app_audit_export_excel')]
    public function exportExcel(Request $request): Response
    {
        $audits = $this->internalAuditRepository->findAll();

        $headers = ['ID', 'Title', 'Scope', 'Status', 'Planned Date', 'Actual Date'];
        $data = [];

        foreach ($audits as $audit) {
            $data[] = [
                $audit->getId(),
                $audit->getTitle(),
                $audit->getScope(),
                $audit->getStatus(),
                $audit->getPlannedDate()?->format('d.m.Y') ?? '',
                $audit->getActualDate()?->format('d.m.Y') ?? '',
            ];
        }

        // Close session to prevent blocking other requests during Excel generation
        $request->getSession()->save();

        $spreadsheet = $this->excelExportService->exportArray($data, $headers, 'Audits');
        $excel = $this->excelExportService->generateExcel($spreadsheet);

        return new Response($excel, Response::HTTP_OK, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="audits_' . date('Y-m-d') . '.xlsx"',
        ]);
    }
    #[Route('/audit/bulk-delete', name: 'app_audit_bulk_delete', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function bulkDelete(Request $request): Response
    {
        $data = json_decode($request->getContent(), true);
        $ids = $data['ids'] ?? [];

        if (empty($ids)) {
            return $this->json(['error' => 'No items selected'], 400);
        }

        $deleted = 0;
        $errors = [];

        foreach ($ids as $id) {
            try {
                $audit = $this->internalAuditRepository->find($id);

                if (!$audit) {
                    $errors[] = "Audit ID $id not found";
                    continue;
                }

                $this->entityManager->remove($audit);
                $deleted++;
            } catch (Exception $e) {
                $errors[] = "Error deleting audit ID $id: " . $e->getMessage();
            }
        }

        if ($deleted > 0) {
            $this->entityManager->flush();
        }

        if ($errors !== []) {
            return $this->json([
                'success' => $deleted > 0,
                'deleted' => $deleted,
                'errors' => $errors
            ], $deleted > 0 ? 200 : 400);
        }

        return $this->json([
            'success' => true,
            'deleted' => $deleted,
            'message' => "$deleted audits deleted successfully"
        ]);
    }
    #[Route('/audit/{id}', name: 'app_audit_show', requirements: ['id' => '\d+'])]
    public function show(InternalAudit $internalAudit): Response
    {
        // Get audit log history for this audit (last 10 entries)
        $auditLogs = $this->auditLogRepository->findByEntity('InternalAudit', $internalAudit->getId());
        $totalAuditLogs = count($auditLogs);
        $auditLogs = array_slice($auditLogs, 0, 10);

        return $this->render('audit/show.html.twig', [
            'audit' => $internalAudit,
            'auditLogs' => $auditLogs,
            'totalAuditLogs' => $totalAuditLogs,
        ]);
    }
    #[Route('/audit/{id}/edit', name: 'app_audit_edit', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function edit(Request $request, InternalAudit $internalAudit): Response
    {
        $form = $this->createForm(InternalAuditType::class, $internalAudit);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('audit.success.updated'));
            return $this->redirectToRoute('app_audit_show', ['id' => $internalAudit->getId()]);
        }

        return $this->render('audit/edit.html.twig', [
            'audit' => $internalAudit,
            'form' => $form,
        ]);
    }
    #[Route('/audit/{id}/delete', name: 'app_audit_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, InternalAudit $internalAudit): Response
    {
        if ($this->isCsrfTokenValid('delete'.$internalAudit->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($internalAudit);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('audit.success.deleted'));
        }

        return $this->redirectToRoute('app_audit_index');
    }
    #[Route('/audit/{id}/export/pdf', name: 'app_audit_export_pdf', requirements: ['id' => '\d+'])]
    public function exportPdf(Request $request, InternalAudit $internalAudit): Response
    {
        // Close session to prevent blocking other requests during PDF generation
        $request->getSession()->save();

        $pdf = $this->pdfExportService->generatePdf('audit/export_pdf.html.twig', [
            'audit' => $internalAudit,
        ]);

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="audit_' . $internalAudit->getId() . '.pdf"',
        ]);
    }
    /**
     * Generate unique audit number
     * Format: AUDIT-YYYY-NNN (e.g., AUDIT-2025-001)
     */
    private function generateAuditNumber(): string
    {
        $year = date('Y');
        $prefix = 'AUDIT-' . $year . '-';

        // Find the highest audit number for current year
        $lastAudit = $this->internalAuditRepository->createQueryBuilder('a')
            ->where('a.auditNumber LIKE :prefix')
            ->setParameter('prefix', $prefix . '%')
            ->orderBy('a.auditNumber', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($lastAudit) {
            // Extract number from AUDIT-2025-123 → 123
            $lastNumber = (int) substr((string) $lastAudit->getAuditNumber(), -3);
            $nextNumber = $lastNumber + 1;
        } else {
            $nextNumber = 1;
        }

        return $prefix . str_pad((string)$nextNumber, 3, '0', STR_PAD_LEFT);
    }
}
