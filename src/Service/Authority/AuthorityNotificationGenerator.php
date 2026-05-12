<?php

declare(strict_types=1);

namespace App\Service\Authority;

use App\Entity\AuthorityTemplate;
use App\Entity\DataBreach;
use App\Entity\Incident;
use App\Entity\Tenant;
use App\Repository\AuthorityTemplateRepository;
use App\Service\AuditLogger;
use App\Service\PdfExportService;

/**
 * F26.2 — Authority Notification Generator
 *
 * Generates pre-filled notification exports for EU/DE supervisory authorities:
 *  - BSI-Meldestelle (NIS-2 Art. 23 incidents)
 *  - BfDI (GDPR Art. 33 data-breach notifications)
 *  - 16 LfDI / state DPAs (DE data-breach notifications)
 *
 * Supported output formats: PDF (via PdfExportService/DomPDF), JSON.
 * All exports are logged via AuditLogger for compliance traceability.
 */
final class AuthorityNotificationGenerator
{
    public function __construct(
        private readonly AuthorityTemplateRepository $templateRepository,
        private readonly PdfExportService $pdfExportService,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    // ── BSI-Meldestelle (NIS-2 Incident) ─────────────────────────────────────

    /**
     * Generate BSI-Meldestelle PDF for an NIS-2 incident.
     */
    public function generateBsiMeldestellePdf(Incident $incident, Tenant $tenant): string
    {
        $template = $this->templateRepository->findOneByTenantAndKey(
            $tenant,
            AuthorityTemplate::AUTHORITY_BSI_MELDESTELLE,
        );

        $data = $this->buildIncidentPayload($incident, $template);

        $pdf = $this->pdfExportService->generatePdf(
            'authority/notification/bsi_meldestelle.html.twig',
            $data,
            ['orientation' => 'portrait'],
        );

        $this->auditLogger->logExport(
            'BSI-Meldestelle',
            $incident->getId(),
            sprintf('BSI-Meldestelle PDF generated for Incident #%d', $incident->getId() ?? 0),
        );

        return $pdf;
    }

    // ── BfDI (GDPR Art. 33 DataBreach) ───────────────────────────────────────

    /**
     * Generate BfDI Art. 33 notification PDF for a data breach.
     */
    public function generateBfdiBreachPdf(DataBreach $breach, Tenant $tenant): string
    {
        $template = $this->templateRepository->findOneByTenantAndKey(
            $tenant,
            AuthorityTemplate::AUTHORITY_BFDI,
        );

        $data = $this->buildBreachPayload($breach, $template);

        $pdf = $this->pdfExportService->generatePdf(
            'authority/notification/bfdi_breach.html.twig',
            $data,
            ['orientation' => 'portrait'],
        );

        $this->auditLogger->logExport(
            'BfDI-Breach',
            $breach->getId(),
            sprintf('BfDI Art. 33 PDF generated for DataBreach #%d', $breach->getId() ?? 0),
        );

        return $pdf;
    }

    // ── LfDI (DE state DPAs) ─────────────────────────────────────────────────

    /**
     * Generate LfDI state-DPA notification PDF for a data breach.
     */
    public function generateLfdiBreachPdf(DataBreach $breach, Tenant $tenant, string $authorityKey): string
    {
        $this->assertLfdiKey($authorityKey);

        $template = $this->templateRepository->findOneByTenantAndKey($tenant, $authorityKey);
        $data = $this->buildBreachPayload($breach, $template);
        $data['authority_key'] = $authorityKey;

        $pdf = $this->pdfExportService->generatePdf(
            'authority/notification/lfdi_breach.html.twig',
            $data,
            ['orientation' => 'portrait'],
        );

        $this->auditLogger->logExport(
            sprintf('LfDI-%s-Breach', strtoupper($authorityKey)),
            $breach->getId(),
            sprintf('%s PDF generated for DataBreach #%d', $authorityKey, $breach->getId() ?? 0),
        );

        return $pdf;
    }

    // ── JSON Export ───────────────────────────────────────────────────────────

    /**
     * Generate a machine-readable JSON payload for a data-breach notification.
     *
     * @return array<string, mixed>
     */
    public function generateJson(DataBreach $breach, Tenant $tenant, string $authorityKey): array
    {
        $template = $this->templateRepository->findOneByTenantAndKey($tenant, $authorityKey);
        $payload = $this->buildBreachPayload($breach, $template);

        $this->auditLogger->logExport(
            sprintf('%s-JSON', strtoupper($authorityKey)),
            $breach->getId(),
            sprintf('%s JSON generated for DataBreach #%d', $authorityKey, $breach->getId() ?? 0),
        );

        return $payload;
    }

    // ── Payload Builders ──────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function buildBreachPayload(DataBreach $breach, ?AuthorityTemplate $template): array
    {
        return [
            'breach' => $breach,
            'template' => $template,
            'reference_number' => $breach->getReferenceNumber(),
            'title' => $breach->getTitle(),
            'severity' => $breach->getSeverity(),
            'status' => $breach->getStatus(),
            'detected_at' => $breach->getDetectedAt(),
            'breach_nature' => $breach->getBreachNature(),
            'likely_consequences' => $breach->getLikelyConsequences(),
            'measures_taken' => $breach->getMeasuresTaken(),
            'affected_data_subjects' => $breach->getAffectedDataSubjects(),
            'data_categories' => $breach->getDataCategories(),
            'data_subject_categories' => $breach->getDataSubjectCategories(),
            'requires_authority_notification' => $breach->getRequiresAuthorityNotification(),
            'supervisory_authority_notified_at' => $breach->getSupervisoryAuthorityNotifiedAt(),
            'supervisory_authority_name' => $breach->getSupervisoryAuthorityName(),
            'submission_url' => $template?->getSubmissionUrl(),
            'submission_contact_email' => $template?->getSubmissionContactEmail(),
            'header_template' => $template?->getHeaderTemplate(),
            'generated_at' => new \DateTimeImmutable(),
        ];
    }

    /** @return array<string, mixed> */
    private function buildIncidentPayload(Incident $incident, ?AuthorityTemplate $template): array
    {
        return [
            'incident' => $incident,
            'template' => $template,
            'title' => $incident->getTitle(),
            'severity' => $incident->getSeverity(),
            'status' => $incident->getStatus(),
            'detected_at' => $incident->getDetectedAt(),
            'description' => $incident->getDescription(),
            'submission_url' => $template?->getSubmissionUrl(),
            'submission_contact_email' => $template?->getSubmissionContactEmail(),
            'header_template' => $template?->getHeaderTemplate(),
            'generated_at' => new \DateTimeImmutable(),
        ];
    }

    private function assertLfdiKey(string $authorityKey): void
    {
        if (!str_starts_with($authorityKey, 'lfdi_')) {
            throw new \InvalidArgumentException(sprintf(
                'Authority key "%s" is not a valid LfDI key. Expected prefix: lfdi_',
                $authorityKey,
            ));
        }
        if (!in_array($authorityKey, AuthorityTemplate::VALID_AUTHORITY_KEYS, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown authority key "%s".',
                $authorityKey,
            ));
        }
    }
}
