<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard\Export;

use App\Entity\Document;
use App\Entity\Tenant;
use App\Entity\WizardRun;
use App\Repository\AuditLogRepository;
use App\Repository\ControlRepository;
use App\Repository\DocumentRepository;
use App\Repository\PolicyAcknowledgementRepository;
use App\Repository\TenantBrandingRepository;
use App\Repository\WizardRunRepository;
use App\Repository\WorkflowInstanceRepository;
use RuntimeException;
use Twig\Environment as TwigEnvironment;
use ZipArchive;

/**
 * Policy-Wizard W7-A — bulk ZIP export for auditors.
 *
 * Builds a single ZIP archive holding the full tenant policy set as
 * PDF (one file per Document, grouped per standard), plus an evidence
 * folder (audit-trail.csv, soa.csv, acknowledgements.csv,
 * workflow-instances.csv) and a `source/wizard-runs.json` provenance
 * file. Top-level `README.md` (rendered from a Twig template) and
 * `manifest.json` (machine-readable index with SHA-256 hashes) sit at
 * the root for the auditor's overview pass.
 *
 * Spec: `docs/plans/policy-wizard/07-phase4-sprint-reconciliation.md`
 * lines 295-302 (bulk export ZIP for auditors).
 */
final class PolicyZipExporter
{
    /**
     * Map standard code → ZIP folder name. Matches the spec's flat
     * `policies/<standard>/<topic>.pdf` layout.
     */
    private const STANDARD_FOLDER = [
        'iso27001' => 'iso27001',
        'iso_27001' => 'iso27001',
        'bsi' => 'bsi',
        'bsi200_2' => 'bsi',
        'dora' => 'dora',
        'bcm' => 'bcm',
        'iso22301' => 'bcm',
        'gdpr' => 'gdpr',
        'iso27701' => 'gdpr',
    ];

    public function __construct(
        private readonly PolicyPdfExporter $pdfExporter,
        private readonly DocumentRepository $documentRepository,
        private readonly TwigEnvironment $twig,
        private readonly ?TenantBrandingRepository $brandingRepository = null,
        private readonly ?AuditLogRepository $auditLogRepository = null,
        private readonly ?ControlRepository $controlRepository = null,
        private readonly ?PolicyAcknowledgementRepository $acknowledgementRepository = null,
        private readonly ?WorkflowInstanceRepository $workflowInstanceRepository = null,
        private readonly ?WizardRunRepository $wizardRunRepository = null,
    ) {
    }

    /**
     * Render the tenant's full policy set into a ZIP archive. Returns
     * the binary contents; the caller (controller) is responsible for
     * streaming or persisting it.
     */
    public function exportTenantPolicySet(Tenant $tenant, ExportOptions $options): string
    {
        $documents = $this->collectDocuments($tenant, $options);
        $branding = $this->brandingRepository?->findOneByTenant($tenant);

        $zipPath = tempnam(sys_get_temp_dir(), 'policy-zip-');
        if ($zipPath === false) {
            throw new RuntimeException('Could not allocate a temporary file for the ZIP export.');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($zipPath);
            throw new RuntimeException('Could not open the ZIP archive for writing.');
        }

        try {
            $manifestEntries = [];
            $readmeEntries = [];

            foreach ($documents as $doc) {
                $standard = $this->standardOf($doc);
                if (!$options->acceptsStandard($standard)) {
                    continue;
                }
                $folder = self::STANDARD_FOLDER[$standard] ?? $standard;
                $topic = $this->topicSlug($doc);
                $entryPath = sprintf('policies/%s/%s.pdf', $folder, $topic);

                $pdf = $this->pdfExporter->exportDocument($doc, $branding);
                $zip->addFromString($entryPath, $pdf);

                $manifestEntries[] = [
                    'document_id' => $doc->getId(),
                    'standard'    => $standard,
                    'topic'       => $topic,
                    'path'        => $entryPath,
                    'sha256'      => hash('sha256', $pdf),
                    'bytes'       => strlen($pdf),
                    'status'      => $doc->getStatus(),
                    'is_archived' => $doc->isArchived(),
                ];
                $readmeEntries[] = [
                    'standard' => $standard,
                    'topic'    => $topic,
                    'path'     => $entryPath,
                    'title'    => $doc->getOriginalFilename() ?? $topic,
                    'status'   => $doc->getStatus(),
                ];
            }

            // Evidence folder (auditor's audit pack).
            if ($options->includeEvidence) {
                $zip->addFromString('evidence/audit-trail.csv', $this->buildAuditTrailCsv($tenant));
                $zip->addFromString('evidence/soa.csv', $this->buildSoaCsv($tenant));
                $zip->addFromString('evidence/acknowledgements.csv', $this->buildAcknowledgementsCsv($tenant, $documents));
                $zip->addFromString('evidence/workflow-instances.csv', $this->buildWorkflowInstancesCsv($tenant, $documents));
            }

            // Source provenance.
            $zip->addFromString('source/wizard-runs.json', $this->buildWizardRunsJson($tenant, $documents));

            // README (auto-generated index).
            $readme = $this->twig->render('policy_wizard/export/zip_index.html.twig', [
                'tenant'       => $tenant,
                'exported_on'  => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'entries'      => $readmeEntries,
                'options'      => $options,
                'manifest_path' => 'manifest.json',
            ]);
            $zip->addFromString('README.md', $readme);

            // Manifest (machine-readable).
            $manifest = [
                'tenant' => [
                    'id'         => $tenant->getId(),
                    'name'       => $tenant->getName(),
                    'legal_name' => $tenant->getLegalName(),
                    'code'       => $tenant->getCode(),
                ],
                'exported_on' => (new \DateTimeImmutable())->format(DATE_ATOM),
                'options'     => [
                    'include_archived'   => $options->includeArchived,
                    'include_standards'  => $options->includeStandards,
                    'include_evidence'   => $options->includeEvidence,
                ],
                'documents' => $manifestEntries,
            ];
            $zip->addFromString('manifest.json', json_encode(
                $manifest,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ) ?: '{}');
        } finally {
            $zip->close();
        }

        $binary = @file_get_contents($zipPath);
        @unlink($zipPath);
        if (!is_string($binary)) {
            throw new RuntimeException('Could not read back the ZIP archive contents.');
        }
        return $binary;
    }

    /**
     * @return list<Document>
     */
    private function collectDocuments(Tenant $tenant, ExportOptions $options): array
    {
        $criteria = ['tenant' => $tenant];
        if (!$options->includeArchived) {
            $criteria['isArchived'] = false;
        }
        $rows = $this->documentRepository->findBy($criteria, ['uploadedAt' => 'DESC']);
        $out = [];
        foreach ($rows as $row) {
            if ($row instanceof Document) {
                $out[] = $row;
            }
        }
        return $out;
    }

    private function standardOf(Document $doc): string
    {
        $template = $doc->getGeneratedFromTemplate();
        $standard = $template?->getStandard();
        if (is_string($standard) && $standard !== '') {
            return $standard;
        }
        // Fallback: pivot on substitutionVariables.standard.
        $vars = $doc->getSubstitutionVariables() ?? [];
        if (isset($vars['standard']) && is_string($vars['standard']) && $vars['standard'] !== '') {
            return $vars['standard'];
        }
        return 'other';
    }

    private function topicSlug(Document $doc): string
    {
        $template = $doc->getGeneratedFromTemplate();
        $topic = $template?->getTopic();
        if (!is_string($topic) || $topic === '') {
            $topic = (string) ($doc->getOriginalFilename() ?? sprintf('document-%d', $doc->getId() ?? 0));
        }
        $slug = preg_replace('/[^A-Za-z0-9_.-]+/u', '-', $topic) ?? $topic;
        $slug = trim($slug, '-_.');
        return $slug === '' ? sprintf('document-%d', $doc->getId() ?? 0) : $slug;
    }

    private function buildAuditTrailCsv(Tenant $tenant): string
    {
        $header = ['id', 'created_at', 'action', 'entity_type', 'entity_id', 'user_name', 'actor_role', 'description'];
        $rows = [$header];
        if ($this->auditLogRepository !== null) {
            $logs = $this->auditLogRepository->findBy(
                ['entityType' => 'Document'],
                ['createdAt' => 'DESC'],
                500,
            );
            foreach ($logs as $log) {
                $rows[] = [
                    (string) $log->getId(),
                    $log->getCreatedAt()?->format(DATE_ATOM) ?? '',
                    (string) ($log->getAction() ?? ''),
                    (string) ($log->getEntityType() ?? ''),
                    (string) ($log->getEntityId() ?? ''),
                    (string) ($log->getUserName() ?? ''),
                    (string) ($log->getActorRole() ?? ''),
                    (string) ($log->getDescription() ?? ''),
                ];
            }
        }
        return $this->csvify($rows);
    }

    private function buildSoaCsv(Tenant $tenant): string
    {
        $header = ['control_id', 'name', 'applicable', 'implementation_status', 'justification', 'evidence_count'];
        $rows = [$header];
        if ($this->controlRepository !== null) {
            $controls = $this->controlRepository->findBy(['tenant' => $tenant]);
            foreach ($controls as $control) {
                $evidence = method_exists($control, 'getEvidenceDocuments')
                    ? $control->getEvidenceDocuments()
                    : null;
                $count = $evidence !== null ? $evidence->count() : 0;
                $rows[] = [
                    (string) ($control->getControlId() ?? ''),
                    (string) ($control->getName() ?? ''),
                    $control->isApplicable() === true ? 'true' : 'false',
                    (string) ($control->getImplementationStatus() ?? ''),
                    (string) ($control->getJustification() ?? ''),
                    (string) $count,
                ];
            }
        }
        return $this->csvify($rows);
    }

    /**
     * @param list<Document> $documents
     */
    private function buildAcknowledgementsCsv(Tenant $tenant, array $documents): string
    {
        $header = ['document_id', 'document_filename', 'user_email', 'acknowledged_at', 'document_version'];
        $rows = [$header];
        if ($this->acknowledgementRepository !== null) {
            foreach ($documents as $doc) {
                $acks = $this->acknowledgementRepository->findBy(['document' => $doc]);
                foreach ($acks as $ack) {
                    $rows[] = [
                        (string) ($doc->getId() ?? ''),
                        (string) ($doc->getOriginalFilename() ?? ''),
                        (string) ($ack->getUser()?->getEmail() ?? ''),
                        $ack->getAcknowledgedAt()?->format(DATE_ATOM) ?? '',
                        (string) ($ack->getDocumentVersion() ?? ''),
                    ];
                }
            }
        }
        return $this->csvify($rows);
    }

    /**
     * @param list<Document> $documents
     */
    private function buildWorkflowInstancesCsv(Tenant $tenant, array $documents): string
    {
        $header = ['instance_id', 'entity_type', 'entity_id', 'status', 'started_at', 'completed_at', 'approval_history'];
        $rows = [$header];
        if ($this->workflowInstanceRepository !== null) {
            foreach ($documents as $doc) {
                $instances = $this->workflowInstanceRepository->findBy([
                    'entityType' => Document::class,
                    'entityId' => $doc->getId(),
                ]);
                foreach ($instances as $instance) {
                    $history = $instance->getApprovalHistory() ?? [];
                    $rows[] = [
                        (string) ($instance->getId() ?? ''),
                        (string) ($instance->getEntityType() ?? ''),
                        (string) ($instance->getEntityId() ?? ''),
                        (string) ($instance->getStatus() ?? ''),
                        method_exists($instance, 'getStartedAt') ? ($instance->getStartedAt()?->format(DATE_ATOM) ?? '') : '',
                        method_exists($instance, 'getCompletedAt') ? ($instance->getCompletedAt()?->format(DATE_ATOM) ?? '') : '',
                        json_encode($history, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
                    ];
                }
            }
        }
        return $this->csvify($rows);
    }

    /**
     * @param list<Document> $documents
     */
    private function buildWizardRunsJson(Tenant $tenant, array $documents): string
    {
        $runs = [];
        foreach ($documents as $doc) {
            $run = $doc->getGeneratedFromWizardRun();
            if (!$run instanceof WizardRun) {
                continue;
            }
            $runId = (int) ($run->getId() ?? 0);
            if (!isset($runs[$runId])) {
                $runs[$runId] = [
                    'wizard_run_id'    => $runId,
                    'mode'             => $run->getMode(),
                    'standards'        => $run->getStandardsAdopted() ?? [],
                    'completed_at'     => $run->getCompletedAt()?->format(DATE_ATOM),
                    'started_by_user'  => $run->getStartedByUser()?->getEmail(),
                    'document_ids'     => [],
                ];
            }
            $runs[$runId]['document_ids'][] = $doc->getId();
        }
        return json_encode(
            ['tenant_id' => $tenant->getId(), 'runs' => array_values($runs)],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ) ?: '{}';
    }

    /**
     * @param list<list<string>> $rows
     */
    private function csvify(array $rows): string
    {
        $handle = fopen('php://temp', 'r+b');
        if ($handle === false) {
            return '';
        }
        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '\\');
        }
        rewind($handle);
        $out = stream_get_contents($handle);
        fclose($handle);
        return is_string($out) ? $out : '';
    }
}
