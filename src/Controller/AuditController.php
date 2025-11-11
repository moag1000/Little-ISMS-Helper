<?php

namespace App\Controller;

use App\Entity\InternalAudit;
use App\Form\InternalAuditType;
use App\Repository\AuditLogRepository;
use App\Repository\InternalAuditRepository;
use App\Service\PdfExportService;
use App\Service\ExcelExportService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/audit')]
class AuditController extends AbstractController
{
    public function __construct(
        private InternalAuditRepository $auditRepository,
        private AuditLogRepository $auditLogRepository,
        private EntityManagerInterface $entityManager,
        private PdfExportService $pdfService,
        private ExcelExportService $excelService,
        private TranslatorInterface $translator
    ) {}

    #[Route('/', name: 'app_audit_index')]
    public function index(Request $request): Response
    {
        // Get filter parameters
        $status = $request->query->get('status');
        $scopeType = $request->query->get('scope_type');
        $dateFrom = $request->query->get('date_from');
        $dateTo = $request->query->get('date_to');

        // Get all audits
        $allAudits = $this->auditRepository->findAll();

        // Apply filters
        if ($status) {
            $allAudits = array_filter($allAudits, fn($audit) => $audit->getStatus() === $status);
        }

        if ($scopeType) {
            $allAudits = array_filter($allAudits, fn($audit) => $audit->getScopeType() === $scopeType);
        }

        if ($dateFrom) {
            $dateFromObj = new \DateTime($dateFrom);
            $allAudits = array_filter($allAudits, fn($audit) => $audit->getPlannedDate() >= $dateFromObj);
        }

        if ($dateTo) {
            $dateToObj = new \DateTime($dateTo);
            $allAudits = array_filter($allAudits, fn($audit) => $audit->getPlannedDate() <= $dateToObj);
        }

        // Re-index array after filtering to avoid gaps in keys
        $allAudits = array_values($allAudits);

        $upcoming = $this->auditRepository->findUpcoming();

        return $this->render('audit/index.html.twig', [
            'audits' => $allAudits,
            'upcoming' => $upcoming,
        ]);
    }

    #[Route('/new', name: 'app_audit_new')]
    #[IsGranted('ROLE_USER')]
    public function new(Request $request): Response
    {
        $audit = new InternalAudit();
        $form = $this->createForm(InternalAuditType::class, $audit);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($audit);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('audit.success.created'));
            return $this->redirectToRoute('app_audit_show', ['id' => $audit->getId()]);
        }

        return $this->render('audit/new.html.twig', [
            'audit' => $audit,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_audit_show', requirements: ['id' => '\d+'])]
    public function show(InternalAudit $audit): Response
    {
        // Get audit log history for this audit (last 10 entries)
        $auditLogs = $this->auditLogRepository->findByEntity('InternalAudit', $audit->getId());
        $totalAuditLogs = count($auditLogs);
        $auditLogs = array_slice($auditLogs, 0, 10);

        return $this->render('audit/show.html.twig', [
            'audit' => $audit,
            'auditLogs' => $auditLogs,
            'totalAuditLogs' => $totalAuditLogs,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_audit_edit', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function edit(Request $request, InternalAudit $audit): Response
    {
        $form = $this->createForm(InternalAuditType::class, $audit);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('audit.success.updated'));
            return $this->redirectToRoute('app_audit_show', ['id' => $audit->getId()]);
        }

        return $this->render('audit/edit.html.twig', [
            'audit' => $audit,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_audit_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, InternalAudit $audit): Response
    {
        if ($this->isCsrfTokenValid('delete'.$audit->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($audit);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('audit.success.deleted'));
        }

        return $this->redirectToRoute('app_audit_index');
    }

    #[Route('/{id}/export/pdf', name: 'app_audit_export_pdf', requirements: ['id' => '\d+'])]
    public function exportPdf(InternalAudit $audit): Response
    {
        $pdf = $this->pdfService->generatePdf('audit/export_pdf.html.twig', [
            'audit' => $audit,
        ]);

        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="audit_' . $audit->getId() . '.pdf"',
        ]);
    }

    #[Route('/export/excel', name: 'app_audit_export_excel')]
    public function exportExcel(): Response
    {
        $audits = $this->auditRepository->findAll();

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

        $spreadsheet = $this->excelService->exportArray($data, $headers, 'Audits');
        $excel = $this->excelService->generateExcel($spreadsheet);

        return new Response($excel, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="audits_' . date('Y-m-d') . '.xlsx"',
        ]);
    }
}
