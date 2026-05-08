<?php

declare(strict_types=1);

namespace App\Service\ComplianceWizard\Check\PolicyWizard;

use App\Entity\Document;
use App\Entity\PolicyTemplate;
use App\Entity\Tenant;
use App\Repository\DocumentRepository;

/**
 * For every published Policy-Wizard document the check verifies that EVERY
 * variable marked as required in the source `PolicyTemplate.requiredVariables`
 * has a non-empty value inside `Document.substitutionVariables`.
 *
 * The W3-D scope (per task brief) calls out "the 3 mandatory tailoring fields"
 * — the actual mandatory-count is template-dependent. This check therefore
 * pivots on the template's required-flag rather than hard-coding a count, so
 * BSI- and DORA-templates (which carry more required vars) still benefit.
 *
 * Reference: `01-iso27001-input.md` §4 Tenant-Settings Inputs (the 6-step
 * collection that drives substitution).
 */
final class PolicyTailoringFieldsCheck implements PolicyWizardCheckInterface
{
    public const CHECK_ID = 'policy_tailoring_fields';
    private const STANDARD = 'iso27001';

    public function __construct(
        private readonly DocumentRepository $documentRepository,
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

        /** @var list<Document> $policies */
        $policies = $this->documentRepository->createQueryBuilder('d')
            ->innerJoin('d.generatedFromTemplate', 't')
            ->where('d.tenant = :tenant')
            ->andWhere('d.status IN (:statuses)')
            ->andWhere('d.isArchived = false')
            ->andWhere('t.standard = :standard')
            ->setParameter('tenant', $tenant)
            ->setParameter('statuses', ['published', 'approved'])
            ->setParameter('standard', self::STANDARD)
            ->getQuery()
            ->getResult();

        $total = count($policies);
        if ($total === 0) {
            return new PolicyWizardCheckResult(
                checkId: self::CHECK_ID,
                score: 100.0,
                passed: true,
                details: ['policies_total' => 0],
            );
        }

        $complete = 0;
        $incomplete = [];
        foreach ($policies as $policy) {
            $missing = $this->missingRequiredFields($policy);
            if ($missing === []) {
                $complete++;
            } else {
                $incomplete[] = [
                    'document_id' => $policy->getId(),
                    'title' => $policy->getOriginalFilename() ?? $policy->getFilename(),
                    'missing_fields' => $missing,
                ];
            }
        }

        $score = round(($complete / $total) * 100, 1);
        $passed = count($incomplete) === 0;

        $gap = null;
        if (!$passed) {
            $gap = [
                'title' => sprintf('compliance_check.%s.fail_message', self::CHECK_ID),
                'priority' => 'medium',
                'route' => 'app_policy_wizard_index',
                'translation_domain' => 'policy_wizard',
                'items' => array_slice($incomplete, 0, 5),
            ];
        }

        return new PolicyWizardCheckResult(
            checkId: self::CHECK_ID,
            score: $score,
            passed: $passed,
            details: [
                'policies_total' => $total,
                'policies_complete' => $complete,
                'policies_incomplete' => count($incomplete),
            ],
            gap: $gap,
        );
    }

    /**
     * @return list<string>
     */
    private function missingRequiredFields(Document $policy): array
    {
        $template = $policy->getGeneratedFromTemplate();
        if (!$template instanceof PolicyTemplate) {
            return [];
        }
        $required = $template->getRequiredVariables() ?? [];
        if ($required === []) {
            return [];
        }
        $variables = $policy->getSubstitutionVariables() ?? [];

        $missing = [];
        foreach ($required as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $isRequired = (bool) ($entry['required'] ?? false);
            if (!$isRequired) {
                continue;
            }
            $key = $entry['key'] ?? null;
            if (!is_string($key) || $key === '') {
                continue;
            }
            $value = $variables[$key] ?? null;
            if ($this->isEmptyValue($value)) {
                $missing[] = $key;
            }
        }
        return $missing;
    }

    private function isEmptyValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_string($value)) {
            return trim($value) === '';
        }
        if (is_array($value)) {
            return $value === [];
        }
        return false;
    }
}
