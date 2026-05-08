<?php

declare(strict_types=1);

namespace App\Service\ComplianceWizard\Check\PolicyWizard\Dora;

use App\Entity\Document;
use App\Entity\Tenant;
use App\Repository\DocumentRepository;
use App\Repository\EntityTagRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckInterface;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckResult;
use App\Service\PolicyWizard\DoraExtensionCatalogue;

/**
 * W4-D / DORA §10 — confirms every ISO 27001 topic listed in the
 * {@see DoraExtensionCatalogue} has its DORA extension applied to the
 * corresponding tenant Document.
 *
 * The DocumentGenerator (W4-A/B) appends a `dora-extension:applied`
 * EntityTag to an ISO topic Document the moment its DORA extension
 * section is rendered into the body. This check verifies coverage:
 * for every catalogue topic where the tenant has a published ISO
 * Document, that Document MUST carry the marker.
 *
 * Topics where the tenant has no published ISO Document yet do NOT
 * count as a gap here — they're surfaced by
 * {@see \App\Service\ComplianceWizard\Check\PolicyWizard\PolicyTopicPresentCheck}.
 * Keeping the gates orthogonal preserves debugging clarity.
 */
final class DoraExtensionCoverageCheck implements PolicyWizardCheckInterface
{
    public const CHECK_ID = 'dora_extension_coverage';
    public const EXTENSION_TAG_NAME = 'dora-extension:applied';
    private const STANDARD = 'dora';
    private const ISO_STANDARD = 'iso27001';

    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly EntityTagRepository $entityTagRepository,
        private readonly DoraExtensionCatalogue $catalogue,
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

        $expectedTopics = array_keys($this->catalogue->all());
        $coveredTopics = [];
        $missingTopics = [];
        $applicableTopics = 0;

        foreach ($expectedTopics as $topic) {
            /** @var list<Document> $isoDocs */
            $isoDocs = $this->documentRepository->createQueryBuilder('d')
                ->innerJoin('d.generatedFromTemplate', 't')
                ->where('d.tenant = :tenant')
                ->andWhere('d.status IN (:statuses)')
                ->andWhere('d.isArchived = false')
                ->andWhere('t.standard = :standard')
                ->andWhere('t.topic = :topic')
                ->setParameter('tenant', $tenant)
                ->setParameter('statuses', ['published', 'approved'])
                ->setParameter('standard', self::ISO_STANDARD)
                ->setParameter('topic', $topic)
                ->getQuery()
                ->getResult();

            if ($isoDocs === []) {
                // No ISO doc to extend — orthogonal failure tracked by
                // PolicyTopicPresentCheck. Skip from this check's score.
                continue;
            }
            $applicableTopics++;

            $allHaveExtension = true;
            foreach ($isoDocs as $doc) {
                $docId = $doc->getId();
                if ($docId === null) {
                    continue;
                }
                $activeTags = $this->entityTagRepository->findActiveFor(Document::class, $docId);
                $hasExtension = false;
                foreach ($activeTags as $entityTag) {
                    $tag = $entityTag->getTag();
                    if ($tag !== null && $tag->getName() === self::EXTENSION_TAG_NAME) {
                        $hasExtension = true;
                        break;
                    }
                }
                if (!$hasExtension) {
                    $allHaveExtension = false;
                    break;
                }
            }

            if ($allHaveExtension) {
                $coveredTopics[] = $topic;
            } else {
                $missingTopics[] = $topic;
            }
        }

        if ($applicableTopics === 0) {
            // Tenant has no ISO documents that need extension yet.
            return new PolicyWizardCheckResult(
                checkId: self::CHECK_ID,
                score: 100.0,
                passed: true,
                details: [
                    'expected_topics' => count($expectedTopics),
                    'applicable_topics' => 0,
                ],
            );
        }

        if ($missingTopics === []) {
            return new PolicyWizardCheckResult(
                checkId: self::CHECK_ID,
                score: 100.0,
                passed: true,
                details: [
                    'expected_topics' => count($expectedTopics),
                    'applicable_topics' => $applicableTopics,
                    'covered_topics' => count($coveredTopics),
                ],
            );
        }

        $score = round((count($coveredTopics) / $applicableTopics) * 100, 1);

        return new PolicyWizardCheckResult(
            checkId: self::CHECK_ID,
            score: $score,
            passed: false,
            details: [
                'expected_topics' => count($expectedTopics),
                'applicable_topics' => $applicableTopics,
                'covered_topics' => count($coveredTopics),
                'missing_topics' => $missingTopics,
                'expected_tag' => self::EXTENSION_TAG_NAME,
            ],
            gap: [
                'title' => sprintf('compliance_check.%s.fail_message', self::CHECK_ID),
                'priority' => 'high',
                'route' => 'app_policy_wizard_index',
                'translation_domain' => 'policy_wizard',
                'items' => array_slice($missingTopics, 0, 5),
            ],
        );
    }
}
