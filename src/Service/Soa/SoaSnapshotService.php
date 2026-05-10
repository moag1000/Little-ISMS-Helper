<?php

declare(strict_types=1);

namespace App\Service\Soa;

use App\Entity\Control;
use App\Entity\Document;
use App\Entity\SoaSnapshot;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\WorkflowInstance;
use App\Repository\ControlRepository;
use App\Repository\DocumentRepository;
use App\Repository\SoaSnapshotRepository;
use App\Repository\WorkflowInstanceRepository;
use App\Service\AuditLogger;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * SoA point-in-time snapshot service.
 *
 * Closes the persona-walkthrough gap (ISB + Auditor-External): the
 * existing certification-bundle exporter only knew the live state of
 * the SoA. Auditors need to see the SoA "frozen" to a chosen
 * `asOfDate` so the export reproduces what was true at the audit
 * cut-off — even when controls, evidence documents or approver
 * identities change after the freeze.
 *
 * Responsibilities:
 *   - Iterate every Control of the tenant + resolve which Document
 *     version was current at `asOfDate` (latest non-superseded
 *     uploaded_at <= asOfDate).
 *   - Resolve the latest approval recorded in the WorkflowInstance
 *     approval-history with `approved_at <= asOfDate`.
 *   - Compute SHA-256 over the canonical JSON payload.
 *   - Persist as immutable {@see SoaSnapshot} (no UPDATE path).
 *   - Emit `soa_snapshot_created` AuditLog event (CLAUDE.md
 *     ISO 27001 Clause 7.5.3 requirement).
 *   - Provide a CSV export of the captured SoA state.
 */
final class SoaSnapshotService
{
    private const string SNAPSHOT_ENGINE_VERSION = '1';

    public function __construct(
        private readonly ControlRepository $controlRepository,
        private readonly DocumentRepository $documentRepository,
        private readonly SoaSnapshotRepository $snapshotRepository,
        private readonly WorkflowInstanceRepository $workflowInstanceRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ?AuditLogger $auditLogger = null,
    ) {
    }

    /**
     * Capture the SoA state for `$tenant` at `$asOfDate` as an
     * immutable snapshot. Returns the persisted entity.
     *
     * The snapshot payload contains, per applicable control, the
     * implementation status, the resolved evidence document chain
     * (with version succession metadata) and the approver identity
     * + timestamp. The whole map is hashed with SHA-256 so the cert
     * bundle exporter can prove the snapshot was not tampered with
     * between freeze and export.
     */
    public function createSnapshot(
        Tenant $tenant,
        DateTimeImmutable $asOfDate,
        User $createdBy,
        ?string $purpose = null,
        ?string $notes = null,
    ): SoaSnapshot {
        $asOfDate = $asOfDate->setTime(23, 59, 59);

        $controls = $this->controlRepository->findByTenant($tenant);
        $controlMap = [];

        foreach ($controls as $control) {
            $controlId = $control->getControlId();
            if ($controlId === null || $controlId === '') {
                continue;
            }
            $controlMap[$controlId] = $this->captureControlState($control, $tenant, $asOfDate);
        }

        $payload = [
            'tenant_id'                => $tenant->getId(),
            'tenant_name'              => $tenant->getName(),
            'as_of_date'               => $asOfDate->format('Y-m-d'),
            'snapshot_engine_version'  => self::SNAPSHOT_ENGINE_VERSION,
            'control_count'            => count($controlMap),
            'controls'                 => $controlMap,
        ];

        $checksum = $this->computeChecksum($payload);

        $snapshot = new SoaSnapshot();
        $snapshot->setTenant($tenant);
        $snapshot->setCreatedBy($createdBy);
        $snapshot->setCreatedAt(new DateTimeImmutable());
        $snapshot->setAsOfDate($asOfDate->setTime(0, 0, 0));
        $snapshot->setPurpose($purpose);
        $snapshot->setNotes($notes);
        $snapshot->setPayload($payload);
        $snapshot->setChecksumSha256($checksum);

        $this->entityManager->persist($snapshot);
        $this->entityManager->flush();

        $this->emitAuditEvent($snapshot, $createdBy);

        return $snapshot;
    }

    /**
     * Lookup helper for the cert-bundle exporter.
     */
    public function findByTenantAndDate(Tenant $tenant, DateTimeImmutable $asOfDate): ?SoaSnapshot
    {
        return $this->snapshotRepository->findByTenantAndDate($tenant, $asOfDate);
    }

    /**
     * Render the snapshot payload as an auditor-friendly CSV.
     * Columns mirror the cert-bundle's INDEX.csv but reflect the
     * frozen state, not the live one.
     */
    public function exportPayloadCsv(SoaSnapshot $snapshot): string
    {
        $rows = [
            [
                'control_id',
                'name',
                'category',
                'applicable',
                'status',
                'evidence_document_id',
                'evidence_document_path',
                'evidence_document_version',
                'evidence_supersedes_chain',
                'evidence_sha256',
                'approved_by_email',
                'approved_by_user_id',
                'approved_at',
            ],
        ];

        $controls = $snapshot->getPayload()['controls'] ?? [];
        if (!is_array($controls)) {
            $controls = [];
        }

        foreach ($controls as $controlState) {
            if (!is_array($controlState)) {
                continue;
            }
            $evidenceDocs = is_array($controlState['evidence_documents'] ?? null)
                ? $controlState['evidence_documents']
                : [];

            if ($evidenceDocs === []) {
                $rows[] = [
                    (string) ($controlState['control_id'] ?? ''),
                    (string) ($controlState['name'] ?? ''),
                    (string) ($controlState['category'] ?? ''),
                    !empty($controlState['applicable']) ? 'true' : 'false',
                    (string) ($controlState['status'] ?? ''),
                    '', '', '', '', '',
                    (string) ($controlState['approved_by_email'] ?? ''),
                    isset($controlState['approved_by_user_id']) ? (string) $controlState['approved_by_user_id'] : '',
                    (string) ($controlState['approved_at'] ?? ''),
                ];
                continue;
            }

            foreach ($evidenceDocs as $doc) {
                $supersedes = is_array($doc['supersedes_chain'] ?? null) ? $doc['supersedes_chain'] : [];
                $rows[] = [
                    (string) ($controlState['control_id'] ?? ''),
                    (string) ($controlState['name'] ?? ''),
                    (string) ($controlState['category'] ?? ''),
                    !empty($controlState['applicable']) ? 'true' : 'false',
                    (string) ($controlState['status'] ?? ''),
                    isset($doc['document_id']) ? (string) $doc['document_id'] : '',
                    (string) ($doc['filename'] ?? ''),
                    isset($doc['version']) ? (string) $doc['version'] : '',
                    implode('|', array_map('strval', $supersedes)),
                    (string) ($doc['sha256'] ?? ''),
                    (string) ($controlState['approved_by_email'] ?? ''),
                    isset($controlState['approved_by_user_id']) ? (string) $controlState['approved_by_user_id'] : '',
                    (string) ($controlState['approved_at'] ?? ''),
                ];
            }
        }

        return $this->rowsToCsv($rows);
    }

    /**
     * Snapshot metadata as a one-row CSV — used by the cert-bundle
     * to drop a `snapshot_metadata.csv` next to the state CSV so the
     * auditor sees who froze what and when at a glance.
     */
    public function exportMetadataCsv(SoaSnapshot $snapshot): string
    {
        $rows = [
            ['snapshot_id', 'tenant_id', 'tenant_name', 'as_of_date', 'created_at', 'created_by_email', 'purpose', 'control_count', 'sha256', 'notes'],
            [
                (string) ($snapshot->getId() ?? ''),
                (string) ($snapshot->getTenant()?->getId() ?? ''),
                (string) ($snapshot->getTenant()?->getName() ?? ''),
                $snapshot->getAsOfDate()->format('Y-m-d'),
                $snapshot->getCreatedAt()->format(DateTimeInterface::ATOM),
                (string) ($snapshot->getCreatedBy()?->getEmail() ?? ''),
                (string) ($snapshot->getPurpose() ?? ''),
                (string) $snapshot->getControlCount(),
                $snapshot->getChecksumSha256(),
                (string) ($snapshot->getNotes() ?? ''),
            ],
        ];

        return $this->rowsToCsv($rows);
    }

    // ─── Internals ──────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function captureControlState(Control $control, Tenant $tenant, DateTimeImmutable $asOfDate): array
    {
        $evidenceDocuments = $this->resolveEvidenceDocumentsAsOf($control, $tenant, $asOfDate);
        $approval          = $this->resolveApprovalAsOf($control, $asOfDate);

        return [
            'control_id'         => $control->getControlId(),
            'name'               => $control->getName(),
            'category'           => $control->getCategory(),
            'status'             => $control->getImplementationStatus() ?? 'not_started',
            'applicable'         => (bool) $control->isApplicable(),
            'evidence_documents' => $evidenceDocuments,
            'approved_by_user_id' => $approval['user_id'],
            'approved_by_email'   => $approval['email'],
            'approved_at'         => $approval['approved_at'],
            'approval_workflow_instance_id' => $approval['workflow_instance_id'],
        ];
    }

    /**
     * Resolve the evidence Documents that linked to `$control` as of
     * `$asOfDate`. A Document counts as "current" if:
     *   - uploadedAt <= asOfDate, AND
     *   - it has no successor (no other Document with `supersedes` ==
     *     this) whose uploadedAt is also <= asOfDate.
     *
     * The supersedes chain (older versions) is preserved in the
     * payload so auditors can trace back the version history.
     *
     * @return list<array<string, mixed>>
     */
    private function resolveEvidenceDocumentsAsOf(Control $control, Tenant $tenant, DateTimeImmutable $asOfDate): array
    {
        // The Control entity does not directly own evidence Documents
        // — the link goes via DocumentControlLink + the
        // `documentControlLinks` collection. To stay forward-compatible
        // and to mirror the certification-bundle's evidence source we
        // walk the tenant's documents and filter to those linked to
        // this control via the DocumentControlLink association.
        $documents = $this->documentRepository->findByTenant($tenant);
        $controlId = $control->getControlId();

        $candidates = [];
        foreach ($documents as $doc) {
            if (!$this->documentLinksToControl($doc, $controlId)) {
                continue;
            }
            $uploadedAt = $doc->getUploadedAt();
            if (!$uploadedAt instanceof DateTimeInterface) {
                continue;
            }
            if ($uploadedAt > $asOfDate) {
                continue;
            }
            $candidates[] = $doc;
        }

        // Filter out documents superseded by another candidate that
        // is itself <= asOfDate. The remaining set is the "current"
        // snapshot of evidence at the cut-off.
        $supersededIds = [];
        foreach ($candidates as $doc) {
            $predecessor = $doc->getSupersedes();
            if ($predecessor !== null && $predecessor->getId() !== null) {
                $supersededIds[$predecessor->getId()] = true;
            }
        }

        $current = [];
        foreach ($candidates as $doc) {
            if (isset($supersededIds[$doc->getId()])) {
                continue;
            }
            $current[] = $this->describeDocument($doc, $candidates);
        }

        return $current;
    }

    /**
     * @param list<Document> $allCandidates
     * @return array<string, mixed>
     */
    private function describeDocument(Document $doc, array $allCandidates): array
    {
        $chain = [];
        $cursor = $doc->getSupersedes();
        $guard = 0;
        while ($cursor !== null && $guard < 50) {
            $cursorId = $cursor->getId();
            if ($cursorId === null) {
                break;
            }
            $chain[] = $cursorId;
            $cursor = $cursor->getSupersedes();
            $guard++;
        }

        $sha256 = (string) ($doc->getSha256Hash() ?? '');
        if ($sha256 === '') {
            $body = $doc->getPolicyBody();
            if ($body !== null && $body !== '') {
                $sha256 = hash('sha256', $body);
            }
        }

        return [
            'document_id'       => $doc->getId(),
            'filename'          => $doc->getOriginalFilename() ?? $doc->getFilename(),
            'uploaded_at'       => $doc->getUploadedAt()?->format(DateTimeInterface::ATOM),
            'status'            => $doc->getStatus(),
            'version'           => count($chain) + 1,
            'supersedes_chain'  => $chain,
            'sha256'            => $sha256,
        ];
    }

    private function documentLinksToControl(Document $doc, ?string $controlId): bool
    {
        if ($controlId === null) {
            return false;
        }
        $template = $doc->getGeneratedFromTemplate();
        if ($template === null) {
            return false;
        }
        $linked = array_merge(
            $template->getLinkedAnnexAControls() ?? [],
            $template->getLinkedBausteine() ?? [],
            $template->getLinkedDoraArticles() ?? [],
        );
        foreach ($linked as $ref) {
            if (!is_string($ref)) {
                continue;
            }
            if ($ref === $controlId) {
                return true;
            }
            if (str_starts_with($ref, 'A.') && substr($ref, 2) === $controlId) {
                return true;
            }
        }
        return false;
    }

    /**
     * Resolve the latest approval that was recorded for the Control
     * via a WorkflowInstance with entityType='Control' and an
     * `approved` event in `approvalHistory` (or `completedAt`) before
     * the cut-off.
     *
     * @return array{user_id: ?int, email: string, approved_at: string, workflow_instance_id: ?int}
     */
    private function resolveApprovalAsOf(Control $control, DateTimeImmutable $asOfDate): array
    {
        $controlId = $control->getId();
        if ($controlId === null) {
            return $this->emptyApproval();
        }

        $instances = $this->workflowInstanceRepository->findByEntity('Control', $controlId);
        $best = null;
        $bestAt = null;
        foreach ($instances as $instance) {
            if (!$instance instanceof WorkflowInstance) {
                continue;
            }
            if ($instance->getStatus() !== 'approved') {
                continue;
            }
            $completedAt = $instance->getCompletedAt();
            if (!$completedAt instanceof DateTimeInterface) {
                continue;
            }
            if ($completedAt > $asOfDate) {
                continue;
            }
            if ($bestAt === null || $completedAt > $bestAt) {
                $best   = $instance;
                $bestAt = $completedAt;
            }
        }

        if ($best === null || $bestAt === null) {
            return $this->emptyApproval();
        }

        $approver = $best->getInitiatedBy();
        return [
            'user_id'              => $approver?->getId(),
            'email'                => (string) ($approver?->getEmail() ?? ''),
            'approved_at'          => $bestAt->format(DateTimeInterface::ATOM),
            'workflow_instance_id' => $best->getId(),
        ];
    }

    /**
     * @return array{user_id: null, email: string, approved_at: string, workflow_instance_id: null}
     */
    private function emptyApproval(): array
    {
        return [
            'user_id'              => null,
            'email'                => '',
            'approved_at'          => '',
            'workflow_instance_id' => null,
        ];
    }

    /**
     * Deterministic checksum: canonical JSON encoding (sorted keys
     * via JSON_THROW_ON_ERROR + SORT_FLAG) -> SHA-256. PHP's
     * `json_encode` preserves insertion order, so we recursively
     * ksort first to stabilise output across PHP runs.
     *
     * @param array<string, mixed> $payload
     */
    private function computeChecksum(array $payload): string
    {
        $this->ksortRecursive($payload);
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return hash('sha256', $json);
    }

    /**
     * @param array<int|string, mixed> $array
     */
    private function ksortRecursive(array &$array): void
    {
        foreach ($array as &$value) {
            if (is_array($value)) {
                $this->ksortRecursive($value);
            }
        }
        unset($value);
        ksort($array);
    }

    private function emitAuditEvent(SoaSnapshot $snapshot, User $createdBy): void
    {
        if ($this->auditLogger === null) {
            return;
        }
        try {
            $this->auditLogger->logCustom(
                action: 'soa_snapshot_created',
                entityType: 'SoaSnapshot',
                entityId: $snapshot->getId(),
                oldValues: null,
                newValues: [
                    'tenant_id'      => $snapshot->getTenant()?->getId(),
                    'as_of_date'     => $snapshot->getAsOfDate()->format('Y-m-d'),
                    'control_count'  => $snapshot->getControlCount(),
                    'sha256'         => $snapshot->getChecksumSha256(),
                    'purpose'        => $snapshot->getPurpose(),
                    'created_by_id'  => $createdBy->getId(),
                ],
                description: sprintf(
                    'SoA snapshot frozen for tenant=%s, as-of=%s, controls=%d, sha256=%s',
                    $snapshot->getTenant()?->getName() ?? '?',
                    $snapshot->getAsOfDate()->format('Y-m-d'),
                    $snapshot->getControlCount(),
                    substr($snapshot->getChecksumSha256(), 0, 16) . '...',
                ),
            );
        } catch (\Throwable) {
            // Audit log failure must not break the freeze.
        }
    }

    /**
     * @param list<list<string>> $rows
     */
    private function rowsToCsv(array $rows): string
    {
        $handle = fopen('php://temp', 'w+');
        if ($handle === false) {
            return '';
        }
        foreach ($rows as $row) {
            fputcsv($handle, array_map([$this, 'sanitizeCsvValue'], $row), ',', '"', '\\');
        }
        rewind($handle);
        $out = stream_get_contents($handle);
        fclose($handle);
        return "\xEF\xBB\xBF" . ($out === false ? '' : $out);
    }

    private function sanitizeCsvValue(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }
        return $value;
    }
}
