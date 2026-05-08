<?php

declare(strict_types=1);

namespace App\Service\ComplianceWizard\Check\PolicyWizard\Iso27701;

use App\Entity\Document;
use App\Entity\PolicyTemplate;
use App\Entity\Tenant;
use App\Repository\DocumentRepository;
use App\Repository\EntityTagRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckInterface;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckResult;
use App\Service\TenantSettingResolver\PolicySettingProvider;

/**
 * W6-D / ISO 27701 §3.3 — confirms generated Documents carry
 * `iso27701:<clause>` tags when the PIMS addon is enabled.
 *
 * Per `docs/plans/policy-wizard/06-dpo-input.md` §3.3, the document
 * generator emits clause-level mapping tags (e.g. `iso27701:5.1`,
 * `iso27701:7.2.8`) for every published policy whose template carries
 * an ISO 27701 clause mapping (`iso27701_clauses_2025` /
 * `iso27701_clauses_2019` columns on PolicyTemplate). The version
 * setting (`iso27701.version`) drives which clause set is the source.
 *
 * Trigger: `iso27701.enabled = true` (else vacuously satisfied).
 *
 * Evidence: every published-or-approved tenant Document whose template
 * has at least one clause in the resolved version-set carries the
 * matching `iso27701:<clause>` {@see \App\Entity\Tag} on its active
 * {@see \App\Entity\EntityTag} rows.
 *
 * Documents whose template has no clause mapping (e.g. the thin A.5.34
 * host) are skipped — their absence of tags is not a gap.
 */
final class Iso27701ClauseTagsAppliedCheck implements PolicyWizardCheckInterface
{
    public const CHECK_ID = 'iso27701_clause_tags_applied';
    public const TAG_PREFIX = 'iso27701:';
    private const STANDARD = 'iso27701';

    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly EntityTagRepository $entityTagRepository,
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

        /** @var list<Document> $documents */
        $documents = $this->documentRepository->createQueryBuilder('d')
            ->innerJoin('d.generatedFromTemplate', 't')
            ->where('d.tenant = :tenant')
            ->andWhere('d.status IN (:statuses)')
            ->andWhere('d.isArchived = false')
            ->setParameter('tenant', $tenant)
            ->setParameter('statuses', ['published', 'approved'])
            ->getQuery()
            ->getResult();

        $expected = 0;
        $tagged = 0;
        $untagged = [];
        foreach ($documents as $doc) {
            $template = $doc->getGeneratedFromTemplate();
            if (!$template instanceof PolicyTemplate) {
                continue;
            }
            $clauses = $version === PolicySettingProvider::ISO27701_VERSION_2019
                ? $template->getIso27701Clauses2019()
                : $template->getIso27701Clauses2025();
            if ($clauses === null || $clauses === []) {
                // Templates without a clause mapping (e.g. thin A.5.34
                // host) carry no tag obligation.
                continue;
            }
            $expected++;
            $docId = $doc->getId();
            if ($docId === null) {
                continue;
            }
            $activeTags = $this->entityTagRepository->findActiveFor(Document::class, $docId);
            $hasIsoTag = false;
            foreach ($activeTags as $entityTag) {
                $tag = $entityTag->getTag();
                if ($tag === null) {
                    continue;
                }
                $name = $tag->getName();
                if (str_starts_with($name, self::TAG_PREFIX)) {
                    $hasIsoTag = true;
                    break;
                }
            }
            if ($hasIsoTag) {
                $tagged++;
            } else {
                $untagged[] = [
                    'document_id' => $docId,
                    'title' => $doc->getOriginalFilename() ?? $doc->getFilename(),
                    'expected_clauses' => $clauses,
                ];
            }
        }

        if ($expected === 0) {
            return new PolicyWizardCheckResult(
                checkId: self::CHECK_ID,
                score: 100.0,
                passed: true,
                details: [
                    'iso27701_enabled' => true,
                    'iso27701_version' => $version,
                    'documents_with_clause_mapping' => 0,
                ],
            );
        }

        if ($untagged === []) {
            return new PolicyWizardCheckResult(
                checkId: self::CHECK_ID,
                score: 100.0,
                passed: true,
                details: [
                    'iso27701_enabled' => true,
                    'iso27701_version' => $version,
                    'documents_with_clause_mapping' => $expected,
                    'tagged' => $tagged,
                ],
            );
        }

        $score = round(($tagged / $expected) * 100, 1);
        return new PolicyWizardCheckResult(
            checkId: self::CHECK_ID,
            score: $score,
            passed: false,
            details: [
                'iso27701_enabled' => true,
                'iso27701_version' => $version,
                'documents_with_clause_mapping' => $expected,
                'tagged' => $tagged,
                'untagged_count' => count($untagged),
            ],
            gap: [
                'title' => sprintf('compliance_check.%s.fail_message', self::CHECK_ID),
                'priority' => 'high',
                'route' => 'app_document_index',
                'translation_domain' => 'policy_wizard',
                'items' => array_slice($untagged, 0, 5),
            ],
        );
    }
}
