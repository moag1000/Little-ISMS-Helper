<?php

declare(strict_types=1);

namespace App\Service\ComplianceWizard\Check\PolicyWizard;

use App\Entity\Document;
use App\Entity\Tenant;
use App\Repository\DocumentRepository;
use DateTimeImmutable;

/**
 * For every published Policy-Wizard document the check verifies the policy is
 * not overdue for review. The effective `nextReviewDate` is computed as
 * `uploadedAt + PolicyTemplate.reviewIntervalMonths` because the Document
 * entity does not carry an explicit `nextReviewDate` field (provenance lives
 * on the linked PolicyTemplate, see W3 architecture §10).
 *
 * Reference: ISO 27001 Cl. 7.5.3 documented-information control + ISO 27002
 * §5.1 (every topic-specific policy "shall be regularly reviewed").
 */
final class PolicyReviewCadenceCheck implements PolicyWizardCheckInterface
{
    public const CHECK_ID = 'policy_review_cadence';
    private const STANDARD = 'iso27001';
    private const DEFAULT_INTERVAL_MONTHS = 12;

    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly ?\DateTimeImmutable $now = null,
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

        $now = $this->now ?? new DateTimeImmutable();
        $onCadence = 0;
        $overdue = [];
        foreach ($policies as $policy) {
            $next = $this->computeNextReviewDate($policy);
            if ($next === null) {
                // No template provenance + no upload date = cannot evaluate.
                // Treat as overdue so it surfaces in the gap list.
                $overdue[] = $this->describe($policy, null);
                continue;
            }
            if ($next >= $now) {
                $onCadence++;
            } else {
                $overdue[] = $this->describe($policy, $next);
            }
        }

        $score = round(($onCadence / $total) * 100, 1);
        $passed = count($overdue) === 0;

        $gap = null;
        if (!$passed) {
            $gap = [
                'title' => sprintf('compliance_check.%s.fail_message', self::CHECK_ID),
                'priority' => 'high',
                'route' => 'app_policy_wizard_index',
                'translation_domain' => 'policy_wizard',
                'items' => array_slice($overdue, 0, 5),
            ];
        }

        return new PolicyWizardCheckResult(
            checkId: self::CHECK_ID,
            score: $score,
            passed: $passed,
            details: [
                'policies_total' => $total,
                'policies_on_cadence' => $onCadence,
                'policies_overdue' => count($overdue),
            ],
            gap: $gap,
        );
    }

    private function computeNextReviewDate(Document $policy): ?DateTimeImmutable
    {
        $uploaded = $policy->getUploadedAt();
        if ($uploaded === null) {
            return null;
        }
        $template = $policy->getGeneratedFromTemplate();
        $interval = $template !== null && $template->getReviewIntervalMonths() > 0
            ? $template->getReviewIntervalMonths()
            : self::DEFAULT_INTERVAL_MONTHS;

        $base = $uploaded instanceof DateTimeImmutable
            ? $uploaded
            : DateTimeImmutable::createFromInterface($uploaded);

        return $base->modify(sprintf('+%d months', $interval));
    }

    /**
     * @return array{document_id: int|null, title: string|null, next_review: string|null}
     */
    private function describe(Document $policy, ?DateTimeImmutable $next): array
    {
        return [
            'document_id' => $policy->getId(),
            'title' => $policy->getOriginalFilename() ?? $policy->getFilename(),
            'next_review' => $next?->format('Y-m-d'),
        ];
    }
}
