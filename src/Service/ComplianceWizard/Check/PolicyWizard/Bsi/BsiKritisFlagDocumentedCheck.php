<?php

declare(strict_types=1);

namespace App\Service\ComplianceWizard\Check\PolicyWizard\Bsi;

use App\Entity\Document;
use App\Entity\Tenant;
use App\Repository\DocumentRepository;
use App\Repository\TenantPolicySettingRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckInterface;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckResult;

/**
 * W5-C / BSIG §8a + KRITIS-DachG — confirms a KRITIS-Operator tenant
 * has the KRITIS-applicability section documented in its
 * IT-Sicherheitsleitlinie.
 *
 * Trigger: tenant-policy-setting `org.is_kritis_operator` set to true.
 * For non-KRITIS tenants the check is vacuously satisfied.
 *
 * Evidence: the tenant's published `it_security_policy` Document MUST
 * carry KRITIS markers — either in its substitution variables
 * (`legal_basis` referencing KRITIS-DachG / BSIG §8a, or a dedicated
 * `kritis_section_present` flag) or in the body file (filename suffix
 * convention used by the wizard renderer). The scan is intentionally
 * substring-tolerant — both `KRITIS-DachG` and `BSIG § 8a` (with non-
 * breaking space variants) qualify.
 *
 * Reference: `docs/plans/policy-wizard/02-bsi-input.md` §1 + §5
 * (`is_kritis` field), §7.4 (KRITIS-spezifische Risiken).
 */
final class BsiKritisFlagDocumentedCheck implements PolicyWizardCheckInterface
{
    public const CHECK_ID = 'bsi_kritis_flag_documented';
    public const SETTING_KEY_KRITIS = 'org.is_kritis_operator';
    private const STANDARD = 'bsi';
    private const TOPIC = 'it_security_policy';

    /** Markers we accept as evidence the KRITIS section is present. */
    public const KRITIS_MARKERS = [
        'KRITIS-DachG',
        'BSIG §8a',
        'BSIG § 8a',
        'BSIG §8b',
        'BSIG § 8b',
        'KRITIS',
    ];

    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly TenantPolicySettingRepository $tenantPolicySettingRepository,
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

        if (!$this->isTenantKritisOperator($tenant)) {
            // Non-KRITIS tenant — vacuously satisfied.
            return new PolicyWizardCheckResult(
                checkId: self::CHECK_ID,
                score: 100.0,
                passed: true,
                details: [
                    'is_kritis_operator' => false,
                    'reason' => 'kritis_section_not_required',
                ],
            );
        }

        /** @var list<Document> $policyDocs */
        $policyDocs = $this->documentRepository->createQueryBuilder('d')
            ->innerJoin('d.generatedFromTemplate', 't')
            ->where('d.tenant = :tenant')
            ->andWhere('d.status IN (:statuses)')
            ->andWhere('d.isArchived = false')
            ->andWhere('t.standard = :standard')
            ->andWhere('t.topic = :topic')
            ->setParameter('tenant', $tenant)
            ->setParameter('statuses', ['published', 'approved'])
            ->setParameter('standard', self::STANDARD)
            ->setParameter('topic', self::TOPIC)
            ->getQuery()
            ->getResult();

        if ($policyDocs === []) {
            return new PolicyWizardCheckResult(
                checkId: self::CHECK_ID,
                score: 0.0,
                passed: false,
                details: [
                    'is_kritis_operator' => true,
                    'reason' => 'no_it_security_policy_document',
                ],
                gap: [
                    'title' => sprintf('compliance_check.%s.fail_message', self::CHECK_ID),
                    'priority' => 'critical',
                    'route' => 'app_policy_wizard_index',
                    'translation_domain' => 'policy_wizard',
                ],
            );
        }

        foreach ($policyDocs as $doc) {
            if ($this->documentHasKritisMarker($doc)) {
                return new PolicyWizardCheckResult(
                    checkId: self::CHECK_ID,
                    score: 100.0,
                    passed: true,
                    details: [
                        'is_kritis_operator' => true,
                        'matched_document_id' => $doc->getId(),
                    ],
                );
            }
        }

        return new PolicyWizardCheckResult(
            checkId: self::CHECK_ID,
            score: 0.0,
            passed: false,
            details: [
                'is_kritis_operator' => true,
                'documents_checked' => count($policyDocs),
                'reason' => 'no_kritis_marker_found',
            ],
            gap: [
                'title' => sprintf('compliance_check.%s.fail_message', self::CHECK_ID),
                'priority' => 'critical',
                'route' => 'app_policy_wizard_index',
                'translation_domain' => 'policy_wizard',
            ],
        );
    }

    private function isTenantKritisOperator(Tenant $tenant): bool
    {
        $setting = $this->tenantPolicySettingRepository->findOneByTenantAndKey(
            $tenant,
            self::SETTING_KEY_KRITIS,
        );
        if ($setting === null) {
            return false;
        }
        $value = $setting->getValue();
        return $value === true || $value === 'true' || $value === 1 || $value === '1';
    }

    /**
     * Scan substitution-variables, description and known body markers
     * for KRITIS evidence. Body-file content scanning is intentionally
     * avoided to keep the check IO-cheap.
     */
    private function documentHasKritisMarker(Document $doc): bool
    {
        $haystacks = [];
        $vars = $doc->getSubstitutionVariables();
        if (is_array($vars)) {
            foreach ($vars as $key => $value) {
                if (is_string($value)) {
                    $haystacks[] = $value;
                }
                if (is_string($key) && in_array(strtolower($key), [
                    'legal_basis',
                    'kritis_section',
                    'kritis_section_present',
                ], true)) {
                    if (is_bool($value) && $value === true) {
                        return true;
                    }
                }
            }
        }
        $desc = $doc->getDescription();
        if ($desc !== null) {
            $haystacks[] = $desc;
        }

        foreach ($haystacks as $hay) {
            foreach (self::KRITIS_MARKERS as $needle) {
                if (str_contains($hay, $needle)) {
                    return true;
                }
            }
        }
        return false;
    }
}
