<?php

declare(strict_types=1);

namespace App\Controller;

use DateTime;
use DateTimeImmutable;
use Exception;
use App\Entity\InternalAudit;
use App\Entity\User;
use App\Form\InternalAuditType;
use App\Lifecycle\LifecycleService;
use App\Repository\AuditChecklistRepository;
use App\Repository\AuditLogRepository;
use App\Repository\InternalAuditRepository;
use App\Service\AuditLogger;
use App\Service\ExcelExportService;
use App\Service\InternalAuditCloner;
use App\Service\PdfExportService;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
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
        private readonly TenantContext $tenantContext,
        private readonly AuditChecklistRepository $auditChecklistRepository,
        private readonly AuditLogger $auditLogger,
        private readonly LifecycleService $lifecycleService,
        private readonly ?InternalAuditCloner $internalAuditCloner = null,
    ) {}
    #[Route('/audit/', name: 'app_audit_index', methods: ['GET'])]
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
    #[Route('/audit/new', name: 'app_audit_new', methods: ['GET', 'POST'])]
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

            $this->addFlash('success', $this->translator->trans('audit.success.created', [], 'audit'));
            return $this->redirectToRoute('app_audit_show', ['id' => $internalAudit->getId()]);
        }

        return $this->render('audit/new.html.twig', [
            'audit' => $internalAudit,
            'form' => $form,
        ]);
    }
    #[Route('/audit/export/excel', name: 'app_audit_export_excel', methods: ['GET'])]
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
    #[Route('/audit/{id}', name: 'app_audit_show', requirements: ['id' => '\d+'], methods: ['GET'])]
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
    #[Route('/audit/{id}/edit', name: 'app_audit_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function edit(Request $request, InternalAudit $internalAudit): Response
    {
        $form = $this->createForm(InternalAuditType::class, $internalAudit);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('audit.success.updated', [], 'audit'));
            return $this->redirectToRoute('app_audit_show', ['id' => $internalAudit->getId()]);
        }

        return $this->render('audit/edit.html.twig', [
            'audit' => $internalAudit,
            'form' => $form,
        ]);
    }
    /**
     * Sprint 3 / C1 — Clone an existing audit into a new planned audit.
     * Shallow clone: title/scope/objectives/frameworks/team copied,
     * findings and reports intentionally not copied (audit history
     * must stay intact).
     */
    #[Route('/audit/{id}/clone', name: 'app_audit_clone', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function clone(Request $request, InternalAudit $internalAudit): Response
    {
        if (!$this->isCsrfTokenValid('clone_audit_' . $internalAudit->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        if ($this->internalAuditCloner === null) {
            throw $this->createNotFoundException('Audit clone service is not available.');
        }

        $plannedDate = null;
        $plannedDateRaw = trim((string) $request->request->get('planned_date', ''));
        if ($plannedDateRaw !== '') {
            try {
                $plannedDate = new \DateTimeImmutable($plannedDateRaw);
            } catch (\Throwable) {
                $plannedDate = null;
            }
        }

        $titleOverride = trim((string) $request->request->get('title', ''));
        $clone = $this->internalAuditCloner->clone(
            source: $internalAudit,
            targetTenant: $this->tenantContext->getCurrentTenant(),
            plannedDate: $plannedDate,
            titleOverride: $titleOverride !== '' ? $titleOverride : null,
        );
        $this->entityManager->flush();

        $this->addFlash('success', $this->translator->trans('audit.flash.cloned', [
            '%title%' => (string) $clone->getTitle(),
        ], 'audits'));

        return $this->redirectToRoute('app_audit_show', ['id' => $clone->getId()]);
    }

    #[Route('/audit/{id}/checklist', name: 'app_audit_checklist', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function checklist(InternalAudit $internalAudit): Response
    {
        $items = $this->auditChecklistRepository->findByAudit($internalAudit);

        // Group items by framework (for non-compliance audits with multiple frameworks)
        $grouped = [];
        foreach ($items as $item) {
            $frameworkName = $item->getRequirement()?->getFramework()?->getName() ?? 'general';
            $grouped[$frameworkName] ??= [];
            $grouped[$frameworkName][] = $item;
        }

        // Compute stats
        $stats = [
            'total' => count($items),
            'verified' => 0,
            'compliant' => 0,
            'partial' => 0,
            'non_compliant' => 0,
            'not_applicable' => 0,
        ];
        foreach ($items as $item) {
            $status = $item->getVerificationStatus();
            if ($status !== 'not_checked') {
                $stats['verified']++;
            }
            if (isset($stats[$status])) {
                $stats[$status]++;
            }
        }

        return $this->render('audit/checklist.html.twig', [
            'audit' => $internalAudit,
            'checklist_items' => $items,
            'grouped_items' => $grouped,
            'stats' => $stats,
        ]);
    }

    #[Route('/audit/{id}/checklist/save', name: 'app_audit_checklist_save', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function checklistSave(Request $request, InternalAudit $internalAudit): Response
    {
        $data = json_decode($request->getContent(), true) ?? [];
        if (!isset($data['_token']) || !$this->isCsrfTokenValid('audit_checklist_save_' . $internalAudit->getId(), (string) $data['_token'])) {
            return new Response(json_encode(['error' => 'Invalid CSRF token']), Response::HTTP_FORBIDDEN, ['Content-Type' => 'application/json']);
        }

        $items = $data['items'] ?? [];
        $updated = 0;
        foreach ($items as $itemData) {
            $itemId = (int) ($itemData['id'] ?? 0);
            if ($itemId <= 0) {
                continue;
            }
            $item = $this->auditChecklistRepository->find($itemId);
            if ($item === null || $item->getAudit()?->getId() !== $internalAudit->getId()) {
                continue;
            }
            if (isset($itemData['verificationStatus'])) {
                $item->setVerificationStatus((string) $itemData['verificationStatus']);
            }
            if (isset($itemData['auditNotes'])) {
                $item->setAuditNotes((string) $itemData['auditNotes']);
            }
            if (isset($itemData['evidenceFound'])) {
                $item->setEvidenceFound((string) $itemData['evidenceFound']);
            }
            if (isset($itemData['findings'])) {
                $item->setFindings((string) $itemData['findings']);
            }
            if (isset($itemData['recommendations'])) {
                $item->setRecommendations((string) $itemData['recommendations']);
            }
            if (isset($itemData['complianceScore'])) {
                $item->setComplianceScore((int) $itemData['complianceScore']);
            }
            $item->setUpdatedAt(new \DateTimeImmutable());
            $updated++;
        }
        $this->entityManager->flush();

        return new Response(
            json_encode(['saved' => $updated, 'message' => $this->translator->trans('checklist.flash.saved', ['%count%' => $updated], 'audit')]),
            Response::HTTP_OK,
            ['Content-Type' => 'application/json']
        );
    }

    #[Route('/audit/{id}/delete', name: 'app_audit_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, InternalAudit $internalAudit): Response
    {
        if ($this->isCsrfTokenValid('delete'.$internalAudit->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($internalAudit);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('audit.success.deleted', [], 'audit'));
        }

        return $this->redirectToRoute('app_audit_index');
    }
    // ==========================================================================
    // S3 P0-26 — Audit-Bericht 4-Augen-Approval-Workflow (ISO 27001 Cl. 9.2.2 d)
    // ==========================================================================

    /**
     * Submit the audit report. Auditor moves the audit from
     * `conducted` (or legacy `in_progress`/`completed`) to `reported`.
     * Audit is read-only for fields after this transition.
     */
    #[Route('/audit/{id}/submit-report', name: 'app_audit_submit_report', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[IsCsrfTokenValid('audit_submit_report', tokenKey: '_token')]
    public function submitReport(InternalAudit $internalAudit): Response
    {
        if (!$internalAudit->canTransitionTo('reported')) {
            $this->addFlash('error', $this->translator->trans('audit.error.invalid_transition', [], 'audit'));

            return $this->redirectToRoute('app_audit_show', ['id' => $internalAudit->getId()]);
        }

        $currentUser = $this->getCurrentUserOrThrow();
        $oldStatus = (string) $internalAudit->getStatus();

        $internalAudit->setReportedBy($currentUser);
        $internalAudit->setReportedAt(new DateTimeImmutable());
        $internalAudit->setUpdatedAt(new DateTimeImmutable());

        $this->entityManager->flush();
        $this->lifecycleService->transition($internalAudit, 'internal_audit_lifecycle', 'report', $currentUser);

        $this->auditLogger->logCustom(
            action: 'audit.report.submitted',
            entityType: 'InternalAudit',
            entityId: $internalAudit->getId(),
            oldValues: ['status' => $oldStatus],
            newValues: ['status' => 'reported', 'reported_by_id' => $currentUser->getId()],
            description: sprintf('Audit report submitted by %s', (string) $currentUser->getEmail()),
        );

        $this->addFlash('success', $this->translator->trans('audit.flash.report_submitted', [], 'audit'));

        return $this->redirectToRoute('app_audit_show', ['id' => $internalAudit->getId()]);
    }

    /**
     * Approve the audit report. Enforces 4-eyes principle server-side:
     * the approver must differ from the reporter. Requires ROLE_AUDITOR
     * (or higher in the role hierarchy).
     */
    #[Route('/audit/{id}/approve', name: 'app_audit_approve_report', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_AUDITOR')]
    #[IsCsrfTokenValid('audit_approve_report', tokenKey: '_token')]
    public function approveReport(InternalAudit $internalAudit): Response
    {
        if (!$internalAudit->canTransitionTo('approved')) {
            $this->addFlash('error', $this->translator->trans('audit.error.invalid_transition', [], 'audit'));

            return $this->redirectToRoute('app_audit_show', ['id' => $internalAudit->getId()]);
        }

        $currentUser = $this->getCurrentUserOrThrow();

        // 4-eyes principle: approver must differ from reporter.
        $reporter = $internalAudit->getReportedBy();
        if ($reporter instanceof User && $reporter->getId() === $currentUser->getId()) {
            $this->addFlash('error', $this->translator->trans('audit.error.same_user_approve', [], 'audit'));

            return $this->redirectToRoute('app_audit_show', ['id' => $internalAudit->getId()]);
        }

        $internalAudit->setApprovedBy($currentUser);
        $internalAudit->setApprovedAt(new DateTimeImmutable());
        // Clear any previous rejection reason — the report is now approved.
        $internalAudit->setRejectionReason(null);
        $internalAudit->setUpdatedAt(new DateTimeImmutable());

        $this->entityManager->flush();
        $this->lifecycleService->transition($internalAudit, 'internal_audit_lifecycle', 'approve', $currentUser);

        $this->auditLogger->logCustom(
            action: 'audit.report.approved',
            entityType: 'InternalAudit',
            entityId: $internalAudit->getId(),
            oldValues: ['status' => 'reported'],
            newValues: ['status' => 'approved', 'approved_by_id' => $currentUser->getId()],
            description: sprintf('Audit report approved by %s (4-eyes vs. reporter %s)', (string) $currentUser->getEmail(), $reporter?->getEmail() ?? '—'),
        );

        $this->addFlash('success', $this->translator->trans('audit.flash.report_approved', [], 'audit'));

        return $this->redirectToRoute('app_audit_show', ['id' => $internalAudit->getId()]);
    }

    /**
     * Reject the audit report. Requires a textual rejectionReason
     * (POST body field `rejection_reason`). Moves back to `rejected`
     * — auditor can revise and resubmit (rejected → reported).
     */
    #[Route('/audit/{id}/reject', name: 'app_audit_reject_report', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_AUDITOR')]
    #[IsCsrfTokenValid('audit_reject_report', tokenKey: '_token')]
    public function rejectReport(Request $request, InternalAudit $internalAudit): Response
    {
        if (!$internalAudit->canTransitionTo('rejected')) {
            $this->addFlash('error', $this->translator->trans('audit.error.invalid_transition', [], 'audit'));

            return $this->redirectToRoute('app_audit_show', ['id' => $internalAudit->getId()]);
        }

        $reason = trim((string) $request->request->get('rejection_reason', ''));
        if ($reason === '') {
            $this->addFlash('error', $this->translator->trans('audit.error.rejection_reason_required', [], 'audit'));

            return $this->redirectToRoute('app_audit_show', ['id' => $internalAudit->getId()]);
        }

        $currentUser = $this->getCurrentUserOrThrow();

        $internalAudit->setRejectionReason($reason);
        // approvedBy/approvedAt stay null — a rejection is not an approval.
        $internalAudit->setUpdatedAt(new DateTimeImmutable());

        $this->entityManager->flush();
        $this->lifecycleService->transition($internalAudit, 'internal_audit_lifecycle', 'reject', $currentUser, $reason);

        $this->auditLogger->logCustom(
            action: 'audit.report.rejected',
            entityType: 'InternalAudit',
            entityId: $internalAudit->getId(),
            oldValues: ['status' => 'reported'],
            newValues: ['status' => 'rejected', 'rejection_reason' => $reason],
            description: sprintf('Audit report rejected by %s', (string) $currentUser->getEmail()),
        );

        $this->addFlash('warning', $this->translator->trans('audit.flash.report_rejected', [], 'audit'));

        return $this->redirectToRoute('app_audit_show', ['id' => $internalAudit->getId()]);
    }

    /**
     * Resubmit a previously rejected report (rejected → reported).
     * Sets reportedBy/reportedAt to the current user/time so the
     * 4-eyes-check against the *current* submitter is meaningful.
     */
    #[Route('/audit/{id}/resubmit', name: 'app_audit_resubmit_report', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[IsCsrfTokenValid('audit_resubmit_report', tokenKey: '_token')]
    public function resubmitReport(InternalAudit $internalAudit): Response
    {
        if (!$internalAudit->canTransitionTo('reported')) {
            $this->addFlash('error', $this->translator->trans('audit.error.invalid_transition', [], 'audit'));

            return $this->redirectToRoute('app_audit_show', ['id' => $internalAudit->getId()]);
        }

        $currentUser = $this->getCurrentUserOrThrow();
        $oldStatus = (string) $internalAudit->getStatus();

        $internalAudit->setReportedBy($currentUser);
        $internalAudit->setReportedAt(new DateTimeImmutable());
        $internalAudit->setUpdatedAt(new DateTimeImmutable());

        $this->entityManager->flush();
        // Workflow: rejected → reported via the 'rework' transition (single hop per workflow YAML)
        $this->lifecycleService->transition($internalAudit, 'internal_audit_lifecycle', 'rework', $currentUser);

        $this->auditLogger->logCustom(
            action: 'audit.report.resubmitted',
            entityType: 'InternalAudit',
            entityId: $internalAudit->getId(),
            oldValues: ['status' => $oldStatus],
            newValues: ['status' => 'reported', 'reported_by_id' => $currentUser->getId()],
            description: sprintf('Audit report resubmitted by %s after rejection', (string) $currentUser->getEmail()),
        );

        $this->addFlash('success', $this->translator->trans('audit.flash.report_resubmitted', [], 'audit'));

        return $this->redirectToRoute('app_audit_show', ['id' => $internalAudit->getId()]);
    }

    /**
     * Close the audit cycle (approved → closed). Archives the audit;
     * no further state changes possible.
     */
    #[Route('/audit/{id}/close', name: 'app_audit_close', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    #[IsCsrfTokenValid('audit_close', tokenKey: '_token')]
    public function closeAudit(InternalAudit $internalAudit): Response
    {
        if (!$internalAudit->canTransitionTo('closed')) {
            $this->addFlash('error', $this->translator->trans('audit.error.invalid_transition', [], 'audit'));

            return $this->redirectToRoute('app_audit_show', ['id' => $internalAudit->getId()]);
        }

        $currentUser = $this->getCurrentUserOrThrow();

        $internalAudit->setClosedBy($currentUser);
        $internalAudit->setClosedAt(new DateTimeImmutable());
        $internalAudit->setUpdatedAt(new DateTimeImmutable());

        $this->entityManager->flush();
        $this->lifecycleService->transition($internalAudit, 'internal_audit_lifecycle', 'close', $currentUser);

        $this->auditLogger->logCustom(
            action: 'audit.cycle.closed',
            entityType: 'InternalAudit',
            entityId: $internalAudit->getId(),
            oldValues: ['status' => 'approved'],
            newValues: ['status' => 'closed', 'closed_by_id' => $currentUser->getId()],
            description: sprintf('Audit cycle closed by %s', (string) $currentUser->getEmail()),
        );

        $this->addFlash('success', $this->translator->trans('audit.flash.audit_closed', [], 'audit'));

        return $this->redirectToRoute('app_audit_show', ['id' => $internalAudit->getId()]);
    }

    private function getCurrentUserOrThrow(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('No authenticated user.');
        }

        return $user;
    }

    #[Route('/audit/{id}/export/pdf', name: 'app_audit_export_pdf', requirements: ['id' => '\d+'], methods: ['GET'])]
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
