<?php

declare(strict_types=1);

namespace App\Service\ComplianceWizard\Check\PolicyWizard\Iso27701;

use App\Entity\Document;
use App\Entity\Tenant;
use App\Repository\DocumentRepository;
use App\Repository\DocumentSectionRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckInterface;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckResult;
use App\Service\TenantSettingResolver\PolicySettingProvider;

/**
 * W6-D / ISO 27701:2025 Cl. 7.5 + GDPR Art. 44-49 — confirms the
 * International Transfers Policy contains "Schrems II" and
 * "supplementary measures" wording.
 *
 * Per `docs/plans/policy-wizard/06-dpo-input.md` §3.1, ISO 27701:2025
 * tightens the international-transfers clauses to require an explicit
 * Schrems II (CJEU C-311/18) impact note plus a supplementary-measures
 * (TIA / TOMs) statement. Tenants on the 2019 edition are exempt — the
 * older clause set predates the 2020 ruling.
 *
 * Trigger: BOTH conditions —
 *  • `iso27701.enabled = true`
 *  • `iso27701.version = 2025`
 *
 * Evidence: the GDPR `gdpr_international_transfers` section
 * (per {@see \App\Service\PolicyWizard\GdprSectionCatalogue}) on the
 * `information_transfer` ISO host carries both phrases in its
 * description / variables / body text. The scan is intentionally
 * substring-tolerant (case-insensitive) — both "Schrems II",
 * "Schrems-II", and "supplementary measures" / "zusätzliche Maßnahmen"
 * qualify.
 *
 * Falls back to scanning the host Document description /
 * substitution-variables when no DocumentSection row exists, so the
 * check works with both the W6-A split-state machine and pre-W6-A
 * monolithic privacy documents.
 */
final class Iso27701SchremsIIClauseInTransfersCheck implements PolicyWizardCheckInterface
{
    public const CHECK_ID = 'iso27701_schrems_ii_clause_in_transfers';
    private const STANDARD = 'iso27701';
    private const ISO_TOPIC_HOST = 'information_transfer';
    private const SECTION_KEY = 'gdpr_international_transfers';

    /** @var list<string> markers we accept as Schrems II evidence. */
    public const SCHREMS_MARKERS = [
        'schrems ii',
        'schrems-ii',
        'c-311/18',
    ];

    /** @var list<string> markers we accept as supplementary-measures evidence. */
    public const SUPPLEMENTARY_MEASURES_MARKERS = [
        'supplementary measures',
        'zusätzliche maßnahmen',
        'zusaetzliche massnahmen',
        'transfer impact assessment',
        'tia',
    ];

    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly DocumentSectionRepository $documentSectionRepository,
        private readonly PolicySettingProvider $policySettingProvider,
    ) {
    }

    public function getCheckId(): string
    {
        return self::CHECK_ID;
    }

    public function getStandard(): string
    {
        return self::STANDARD;
    }

    public function run(?Tenant $tenant): PolicyWizardCheckResult
    {
        if ($tenant === null) {
            return new PolicyWizardCheckResult(
                checkId: self::CHECK_ID,
                score: 0.0,
                passed: false,
                details: ['reason' => 'no_tenant'],
            );
        }

        if (!$this->policySettingProvider->isIso27701Enabled($tenant)) {
            return new PolicyWizardCheckResult(
                checkId: self::CHECK_ID,
                score: 100.0,
                passed: true,
                details: [
                    'iso27701_enabled' => false,
                    'reason' => 'pims_not_enabled',
                ],
            );
        }

        $version = $this->policySettingProvider->resolveIso27701Version($tenant);
        if ($version !== PolicySettingProvider::ISO27701_VERSION_2025) {
            return new PolicyWizardCheckResult(
                checkId: self::CHECK_ID,
                score: 100.0,
                passed: true,
                details: [
                    'iso27701_enabled' => true,
                    'iso27701_version' => $version,
                    'reason' => 'schrems_ii_required_only_in_2025_edition',
                ],
            );
        }

        /** @var list<Document> $hosts */
        $hosts = $this->documentRepository->createQueryBuilder('d')
            ->innerJoin('d.generatedFromTemplate', 't')
            ->where('d.tenant = :tenant')
            ->andWhere('d.status IN (:statuses)')
            ->andWhere('d.isArchived = false')
            ->andWhere('t.topic = :topic')
            ->setParameter('tenant', $tenant)
            ->setParameter('statuses', ['published', 'approved'])
            ->setParameter('topic', self::ISO_TOPIC_HOST)
            ->getQuery()
            ->getResult();

        if ($hosts === []) {
            return new PolicyWizardCheckResult(
                checkId: self::CHECK_ID,
                score: 0.0,
                passed: false,
                details: [
                    'iso27701_enabled' => true,
                    'iso27701_version' => $version,
                    'reason' => 'no_information_transfer_host_document',
                ],
                gap: [
                    'title' => sprintf('compliance_check.%s.fail_message', self::CHECK_ID),
                    'priority' => 'high',
                    'route' => 'app_policy_wizard_index',
                    'translation_domain' => 'policy_wizard',
                ],
            );
        }

        foreach ($hosts as $host) {
            if ($this->hostHasSchremsAndSupplementaryWording($host)) {
                return new PolicyWizardCheckResult(
                    checkId: self::CHECK_ID,
                    score: 100.0,
                    passed: true,
                    details: [
                        'iso27701_enabled' => true,
                        'iso27701_version' => $version,
                        'matched_document_id' => $host->getId(),
                    ],
                );
            }
        }

        return new PolicyWizardCheckResult(
            checkId: self::CHECK_ID,
            score: 0.0,
            passed: false,
            details: [
                'iso27701_enabled' => true,
                'iso27701_version' => $version,
                'documents_checked' => count($hosts),
                'reason' => 'schrems_ii_or_supplementary_measures_wording_missing',
            ],
            gap: [
                'title' => sprintf('compliance_check.%s.fail_message', self::CHECK_ID),
                'priority' => 'high',
                'route' => 'app_policy_wizard_index',
                'translation_domain' => 'policy_wizard',
            ],
        );
    }

    /**
     * Scan the host Document + its `gdpr_international_transfers` section
     * description / substitution-variables for Schrems II + supplementary-
     * measures wording. Body-file content is intentionally not inspected
     * (IO-cheap: rendering is the GeneratedDocument step, not the check).
     */
    private function hostHasSchremsAndSupplementaryWording(Document $host): bool
    {
        $haystacks = [];
        $description = $host->getDescription();
        if ($description !== null) {
            $haystacks[] = $description;
        }
        $vars = $host->getSubstitutionVariables();
        if (is_array($vars)) {
            foreach ($vars as $value) {
                if (is_string($value)) {
                    $haystacks[] = $value;
                }
            }
        }
        $section = $this->documentSectionRepository->findOneByDocumentAndKey(
            $host,
            self::SECTION_KEY,
        );
        if ($section !== null) {
            $snapshot = $section->getContentSnapshot();
            if ($snapshot !== null) {
                $haystacks[] = $snapshot;
            }
        }

        $hasSchrems = false;
        $hasSupplementary = false;
        foreach ($haystacks as $hay) {
            $lower = mb_strtolower($hay);
            if (!$hasSchrems) {
                foreach (self::SCHREMS_MARKERS as $needle) {
                    if (str_contains($lower, $needle)) {
                        $hasSchrems = true;
                        break;
                    }
                }
            }
            if (!$hasSupplementary) {
                foreach (self::SUPPLEMENTARY_MEASURES_MARKERS as $needle) {
                    if (str_contains($lower, $needle)) {
                        $hasSupplementary = true;
                        break;
                    }
                }
            }
            if ($hasSchrems && $hasSupplementary) {
                return true;
            }
        }
        return false;
    }
}
