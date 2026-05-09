<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Document;
use App\Entity\User;
use App\Repository\DocumentRepository;
use App\Security\Voter\PolicyWizardVoter;
use App\Service\AuditLogger;
use App\Service\PolicyWizard\Export\ExportOptions;
use App\Service\PolicyWizard\Export\PolicyPdfExporter;
use App\Service\PolicyWizard\Export\PolicyZipExporter;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Policy-Wizard W7-A — PDF + ZIP export endpoints.
 *
 * Two routes:
 *  - GET  `/{_locale}/policy-wizard/export/document/{id}/pdf` — single
 *    Document → PDF download.
 *  - POST `/{_locale}/policy-wizard/export/tenant/zip` — full tenant
 *    policy set → ZIP download (CSRF-protected).
 *
 * Both routes are gated behind the new
 * {@see PolicyWizardVoter::EXPORT} attribute (admin / CISO / auditor).
 * Every successful export writes an `export` audit-log entry.
 *
 * Spec: `docs/plans/policy-wizard/07-phase4-sprint-reconciliation.md`
 * lines 295-302.
 */
#[Route('/policy-wizard/export', name: 'app_policy_export_')]
final class PolicyExportController extends AbstractController
{
    public function __construct(
        private readonly PolicyPdfExporter $pdfExporter,
        private readonly PolicyZipExporter $zipExporter,
        private readonly DocumentRepository $documentRepository,
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * Render one Document → PDF binary, served inline as
     * `application/pdf`. Authorisation: {@see PolicyWizardVoter::EXPORT}
     * on the document's tenant.
     */
    #[Route('/document/{id}/pdf', name: 'document_pdf', methods: ['GET'])]
    #[IsGranted(PolicyWizardVoter::EXPORT)]
    public function exportDocumentPdf(int $id): Response
    {
        $document = $this->documentRepository->find($id);
        if (!$document instanceof Document) {
            throw $this->createNotFoundException('Document not found.');
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null || $document->getTenant()?->getId() !== $tenant->getId()) {
            throw $this->createAccessDeniedException('Document is not in the current tenant scope.');
        }

        $pdf = $this->pdfExporter->exportDocument($document);

        $this->auditLogger->logExport(
            'Document',
            $document->getId(),
            sprintf(
                'Policy-Wizard PDF export of Document #%d (%s) by %s',
                $document->getId() ?? 0,
                $document->getOriginalFilename() ?? '',
                $this->getUser() instanceof User ? $this->getUser()->getEmail() : 'unknown',
            ),
        );

        $filename = sprintf(
            '%s.pdf',
            preg_replace('/[^A-Za-z0-9_.-]+/', '-', (string) ($document->getOriginalFilename() ?? ('document-' . $id))),
        );

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
            'Content-Length'      => (string) strlen($pdf),
            'X-Robots-Tag'        => 'noindex',
        ]);
    }

    /**
     * Render the tenant's policy set as a ZIP audit-pack. Posted (not
     * GET) so the CSRF attribute can validate the form token, and so
     * the action does not get prefetched / cached by intermediaries.
     */
    #[Route('/tenant/zip', name: 'tenant_zip', methods: ['POST'])]
    #[IsGranted(PolicyWizardVoter::EXPORT)]
    #[IsCsrfTokenValid('policy_export_tenant_zip', tokenKey: '_token')]
    public function exportTenantZip(Request $request): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            throw $this->createAccessDeniedException('No tenant in scope.');
        }

        $standardsRaw = $request->request->all('standards');
        $standards = [];
        foreach ($standardsRaw as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                $standards[] = $candidate;
            }
        }
        if ($standards === []) {
            $standards = ExportOptions::DEFAULT_STANDARDS;
        }

        $options = new ExportOptions(
            includeArchived: $request->request->getBoolean('include_archived', false),
            includeStandards: $standards,
            includeEvidence: $request->request->getBoolean('include_evidence', true),
        );

        $zip = $this->zipExporter->exportTenantPolicySet($tenant, $options);

        $this->auditLogger->logExport(
            'Tenant',
            $tenant->getId(),
            sprintf(
                'Policy-Wizard ZIP audit-pack export for tenant "%s" (standards: %s, archived: %s, evidence: %s) by %s',
                (string) ($tenant->getName() ?? $tenant->getCode() ?? $tenant->getId() ?? ''),
                implode(',', $options->includeStandards),
                $options->includeArchived ? 'yes' : 'no',
                $options->includeEvidence ? 'yes' : 'no',
                $this->getUser() instanceof User ? $this->getUser()->getEmail() : 'unknown',
            ),
        );

        $filename = sprintf(
            '%s-%s.zip',
            preg_replace('/[^A-Za-z0-9_.-]+/', '-', (string) ($tenant->getCode() ?? $tenant->getName() ?? 'tenant')),
            (new \DateTimeImmutable())->format('Y-m-d'),
        );

        return new Response($zip, Response::HTTP_OK, [
            'Content-Type'        => 'application/zip',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
            'Content-Length'      => (string) strlen($zip),
            'X-Robots-Tag'        => 'noindex',
        ]);
    }
}
