<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\Control;
use App\Entity\Document;
use App\Entity\DocumentControlLink;
use App\Entity\PolicyTemplate;
use App\Entity\Tenant;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Repository\ControlRepository;
use App\Repository\DocumentControlLinkRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Multi-framework document↔control/requirement evidence attachment service.
 *
 * On approval of a wizard-generated Document, reads the PolicyTemplate's
 * typed norm-ref fields and creates the appropriate linkage rows:
 *
 *   - ISO 27001 Annex A refs (linkedAnnexAControls) → DocumentControlLink
 *     (source=policy_wizard, evidence_type=policy_document)
 *   - BSI refs (linkedBsiBausteine, linkedBausteine) → ComplianceRequirement
 *     evidenceDocuments (frameworks with code prefix 'BSI')
 *   - DORA refs (linkedDoraArticles) → ComplianceRequirement evidenceDocuments
 *     (framework code 'DORA')
 *   - ISO 27701 refs (iso27701Clauses2025 / 2019) → ComplianceRequirement
 *     evidenceDocuments (frameworks ISO27701, ISO27701_2025)
 *   - NIS2 / GDPR / SOC2 / C5 / TISAX / other frameworks → ComplianceRequirement
 *     evidenceDocuments when tenant has a matching framework seeded.
 *
 * ISO 27001 Cl. 7.5.3 audit trail: every link creation goes through
 * AuditLogger::logBulk(). All tenant lookups are explicitly scoped.
 */
final class DocumentEvidenceAttachmentService implements DocumentEvidenceAttachmentInterface
{
    /**
     * Framework code aliases: normalise user-facing framework names
     * (from PolicyTemplate fields or future norm-ref strings) to DB codes.
     *
     * Ordered longest → shortest where ambiguity could arise.
     *
     * @var array<string, list<string>>
     */
    private const array FRAMEWORK_CODE_CANDIDATES = [
        'ISO27001'        => ['ISO27001', 'ISO 27001', 'iso27001', 'ISO27001:2022'],
        'DORA'            => ['DORA', 'dora', 'EU-DORA', 'eu_dora'],
        'GDPR'            => ['GDPR', 'gdpr', 'EU-GDPR', 'DSGVO', 'dsgvo'],
        'NIS2UMSUCG'      => ['NIS2', 'NIS 2', 'NIS-2', 'nis2', 'NIS2UMSUCG'],
        'SOC2'            => ['SOC2', 'SOC 2', 'soc2'],
        'BSI-C5-2026'     => ['C5', 'BSI-C5', 'BSI C5', 'C5:2020', 'C5:2026'],
        'ISO27701'        => ['ISO27701', 'ISO 27701', 'iso27701'],
        'ISO27701_2025'   => ['ISO27701:2025', 'ISO 27701:2025'],
        'BSI_GRUNDSCHUTZ' => ['BSI', 'BSI-Grundschutz', 'BSI IT-Grundschutz'],
        'TISAX'           => ['TISAX', 'tisax', 'VDA-ISA'],
    ];

    /**
     * Implementation-status values that qualify for upgrade to 'documented'.
     * Only bump when the control has not yet reached documentation evidence.
     *
     * @var list<string>
     */
    private const array UPGRADEABLE_STATUSES = ['not_started', 'planned'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ControlRepository $controlRepository,
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly ComplianceRequirementRepository $requirementRepository,
        private readonly DocumentControlLinkRepository $dclRepository,
        private readonly LoggerInterface $logger,
        private readonly ?AuditLogger $auditLogger = null,
    ) {
    }

    /**
     * Attach evidence links for a newly-approved document.
     *
     * Returns a summary array:
     *   ['iso27001_links' => int, 'requirement_links' => int, 'skipped' => int]
     *
     * @return array{iso27001_links: int, requirement_links: int, skipped: int}
     */
    public function attachOnApproval(Document $document): array
    {
        $template = $document->getGeneratedFromTemplate();
        if ($template === null) {
            $this->logger->debug('DocumentEvidenceAttachment: skipped — no template provenance', [
                'document_id' => $document->getId(),
            ]);
            return ['iso27001_links' => 0, 'requirement_links' => 0, 'skipped' => 0];
        }

        $tenant = $document->getTenant();
        if ($tenant === null) {
            $this->logger->warning('DocumentEvidenceAttachment: skipped — document has no tenant', [
                'document_id' => $document->getId(),
            ]);
            return ['iso27001_links' => 0, 'requirement_links' => 0, 'skipped' => 0];
        }

        $iso27001Links = 0;
        $requirementLinks = 0;
        $skipped = 0;
        $auditPerEntityRows = [];

        // ── ISO 27001 Annex A ────────────────────────────────────────────────
        $annexARefs = $template->getLinkedAnnexAControls() ?? [];
        foreach ($annexARefs as $controlId) {
            $result = $this->attachIso27001Control($document, $tenant, (string) $controlId);
            if ($result !== null) {
                $iso27001Links++;
                $auditPerEntityRows[] = [
                    'entity_id' => $result->getId(),
                    'action' => 'create',
                    'old_values' => null,
                    'new_values' => [
                        'document_id' => $document->getId(),
                        'control_id' => $result->getControl()?->getId(),
                        'framework' => 'ISO 27001',
                        'ref_id' => $controlId,
                        'source' => DocumentControlLink::SOURCE_POLICY_WIZARD,
                    ],
                ];
            } else {
                $skipped++;
            }
        }

        // ── BSI IT-Grundschutz (Anforderungsebene Bausteine) ────────────────
        $bsiRefs = $template->getLinkedBsiBausteine() ?? $template->getLinkedBausteine() ?? [];
        if (!empty($bsiRefs)) {
            $bsiLinks = $this->attachRequirementEvidence(
                $document,
                $tenant,
                'BSI_GRUNDSCHUTZ',
                $bsiRefs,
                'BSI',
                $auditPerEntityRows,
            );
            $requirementLinks += $bsiLinks['linked'];
            $skipped += $bsiLinks['skipped'];
        }

        // ── DORA ─────────────────────────────────────────────────────────────
        $doraRefs = $template->getLinkedDoraArticles() ?? [];
        if (!empty($doraRefs)) {
            $doraLinks = $this->attachRequirementEvidence(
                $document,
                $tenant,
                'DORA',
                $doraRefs,
                'DORA',
                $auditPerEntityRows,
            );
            $requirementLinks += $doraLinks['linked'];
            $skipped += $doraLinks['skipped'];
        }

        // ── ISO 27701 ────────────────────────────────────────────────────────
        $iso27701Refs = $template->getIso27701Clauses2025() ?? $template->getIso27701Clauses2019() ?? [];
        if (!empty($iso27701Refs)) {
            // Try 2025 framework first, fall back to base ISO27701 code.
            $iso27701Links = $this->attachRequirementEvidence(
                $document,
                $tenant,
                'ISO27701_2025',
                $iso27701Refs,
                'ISO 27701',
                $auditPerEntityRows,
                fallbackFrameworkCode: 'ISO27701',
            );
            $requirementLinks += $iso27701Links['linked'];
            $skipped += $iso27701Links['skipped'];
        }

        // ── Audit log ────────────────────────────────────────────────────────
        if (!empty($auditPerEntityRows) && $this->auditLogger !== null) {
            $this->auditLogger->logBulk(
                eventType: 'document.evidence_attached',
                entityType: 'Document',
                batchData: [
                    'document_id' => $document->getId(),
                    'template_key' => $template->getKey(),
                    'iso27001_links' => $iso27001Links,
                    'requirement_links' => $requirementLinks,
                    'skipped' => $skipped,
                ],
                perEntityData: $auditPerEntityRows,
                description: sprintf(
                    'Evidence attached on approval: Document #%d — %d ISO 27001 links, %d requirement links',
                    (int) $document->getId(),
                    $iso27001Links,
                    $requirementLinks,
                ),
            );
        }

        $this->logger->info('DocumentEvidenceAttachment: completed', [
            'document_id' => $document->getId(),
            'template_key' => $template->getKey(),
            'iso27001_links' => $iso27001Links,
            'requirement_links' => $requirementLinks,
            'skipped' => $skipped,
        ]);

        return [
            'iso27001_links' => $iso27001Links,
            'requirement_links' => $requirementLinks,
            'skipped' => $skipped,
        ];
    }

    /**
     * Create or skip a DocumentControlLink for an ISO 27001 Annex A control.
     * Optionally upgrades the control's implementationStatus to 'in_progress'
     * when it was previously 'not_started' or 'planned'.
     */
    private function attachIso27001Control(Document $document, Tenant $tenant, string $controlId): ?DocumentControlLink
    {
        $control = $this->controlRepository->findByControlIdAndTenant($controlId, $tenant);
        if ($control === null) {
            $this->logger->debug('DocumentEvidenceAttachment: ISO 27001 control not found', [
                'control_id' => $controlId,
                'tenant_id' => $tenant->getId(),
            ]);
            return null;
        }

        // Idempotency: skip if link already exists.
        $existing = $this->dclRepository->findOneByDocumentAndControl($document, $control);
        if ($existing !== null) {
            $this->logger->debug('DocumentEvidenceAttachment: DCL already exists', [
                'document_id' => $document->getId(),
                'control_id' => $controlId,
            ]);
            return null;
        }

        $link = new DocumentControlLink(
            document: $document,
            control: $control,
            source: DocumentControlLink::SOURCE_POLICY_WIZARD,
            evidenceType: DocumentControlLink::EVIDENCE_POLICY,
        );
        $this->em->persist($link);

        // Optionally bump implementation status.
        $this->maybeUpgradeControlStatus($control);

        $this->logger->info('DocumentEvidenceAttachment: ISO 27001 DCL created', [
            'document_id' => $document->getId(),
            'control_id' => $controlId,
            'tenant_id' => $tenant->getId(),
        ]);

        return $link;
    }

    /**
     * Attach evidence to ComplianceRequirement rows for a given framework.
     *
     * @param list<string>                 $refs           Requirement IDs from template field.
     * @param array<int, array<string, mixed>> $auditRows  Accumulator for audit-log rows (passed by ref).
     * @return array{linked: int, skipped: int}
     */
    private function attachRequirementEvidence(
        Document $document,
        Tenant $tenant,
        string $frameworkCode,
        array $refs,
        string $frameworkLabel,
        array &$auditRows,
        ?string $fallbackFrameworkCode = null,
    ): array {
        $framework = $this->resolveFramework($frameworkCode, $fallbackFrameworkCode);
        if ($framework === null) {
            $this->logger->debug('DocumentEvidenceAttachment: framework not found', [
                'framework_code' => $frameworkCode,
                'fallback' => $fallbackFrameworkCode,
            ]);
            return ['linked' => 0, 'skipped' => count($refs)];
        }

        $linked = 0;
        $skipped = 0;

        foreach ($refs as $refId) {
            $req = $this->requirementRepository->findOneBy([
                'framework' => $framework,
                'requirementId' => (string) $refId,
            ]);
            if ($req === null) {
                $this->logger->debug('DocumentEvidenceAttachment: requirement not found', [
                    'framework' => $frameworkCode,
                    'ref_id' => $refId,
                ]);
                $skipped++;
                continue;
            }

            if ($req->getEvidenceDocuments()->contains($document)) {
                $skipped++;
                continue;
            }

            $req->addEvidenceDocument($document);
            $this->em->persist($req);

            $auditRows[] = [
                'entity_id' => $req->getId(),
                'action' => 'update',
                'old_values' => null,
                'new_values' => [
                    'document_id' => $document->getId(),
                    'framework' => $frameworkLabel,
                    'ref_id' => $refId,
                    'action' => 'evidence_document_added',
                ],
            ];

            $this->logger->info('DocumentEvidenceAttachment: requirement evidence linked', [
                'document_id' => $document->getId(),
                'framework' => $frameworkCode,
                'ref_id' => $refId,
                'requirement_id' => $req->getId(),
            ]);

            $linked++;
        }

        return ['linked' => $linked, 'skipped' => $skipped];
    }

    /**
     * Resolve a ComplianceFramework by primary code, optionally falling back.
     */
    private function resolveFramework(string $code, ?string $fallbackCode): ?ComplianceFramework
    {
        $fw = $this->frameworkRepository->findOneBy(['code' => $code]);
        if ($fw !== null) {
            return $fw;
        }
        if ($fallbackCode !== null) {
            return $this->frameworkRepository->findOneBy(['code' => $fallbackCode]);
        }
        return null;
    }

    /**
     * Upgrade a control's implementationStatus from an upgradeable tier
     * to 'in_progress' (documents the control → next step for ISB).
     */
    private function maybeUpgradeControlStatus(Control $control): void
    {
        if (!in_array($control->getImplementationStatus(), self::UPGRADEABLE_STATUSES, true)) {
            return;
        }
        $control->setImplementationStatus('in_progress');
        $this->em->persist($control);

        $this->logger->info('DocumentEvidenceAttachment: control status upgraded to in_progress', [
            'control_id' => $control->getId(),
            'control_ref' => $control->getControlId(),
        ]);
    }

    /**
     * Attempt to resolve an arbitrary norm-ref string to a framework code.
     *
     * Used by the Phase 3 bulk-link endpoint where users may paste mixed
     * references from norm-ref label strings. Returns null when no match.
     *
     * @internal exposed as public for the Phase 3 controller.
     */
    public function resolveFrameworkCodeFromLabel(string $label): ?string
    {
        $normalised = trim($label);
        foreach (self::FRAMEWORK_CODE_CANDIDATES as $code => $aliases) {
            foreach ($aliases as $alias) {
                if (stripos($normalised, $alias) === 0 || strtolower($normalised) === strtolower($alias)) {
                    return $code;
                }
            }
        }
        return null;
    }
}
