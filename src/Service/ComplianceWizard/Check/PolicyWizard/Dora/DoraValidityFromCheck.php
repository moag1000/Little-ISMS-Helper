<?php

declare(strict_types=1);

namespace App\Service\ComplianceWizard\Check\PolicyWizard\Dora;

use App\Entity\Document;
use App\Entity\Tenant;
use App\Repository\DocumentRepository;
use App\Repository\EntityTagRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckInterface;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckResult;

/**
 * W4-D / DORA Art. 1 — confirms every DORA-tagged Document carries the
 * `dora-validity:2025-01-17` tag introduced by W3-A's
 * {@see \App\Service\PolicyWizard\DocumentGenerator::applyTags}.
 *
 * The validity-from anchor is the date Regulation (EU) 2022/2554
 * becomes applicable. Auditors expect every DORA artefact to carry
 * the marker so generated documents pre-dating that date are clearly
 * out-of-scope and post-dating ones inherit the regulatory clock.
 *
 * The check inspects the active {@see \App\Entity\EntityTag} rows for
 * every published Document whose `generatedFromTemplate.standard = 'dora'`.
 */
final class DoraValidityFromCheck implements PolicyWizardCheckInterface
{
    public const CHECK_ID = 'dora_validity_from';
    public const VALIDITY_TAG_NAME = 'dora-validity:2025-01-17';
    private const STANDARD = 'dora';

    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly EntityTagRepository $entityTagRepository,
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

        /** @var list<Document> $doraDocuments */
        $doraDocuments = $this->documentRepository->createQueryBuilder('d')
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

        $total = count($doraDocuments);
        if ($total === 0) {
            // No DORA documents yet — vacuously satisfied.
            return new PolicyWizardCheckResult(
                checkId: self::CHECK_ID,
                score: 100.0,
                passed: true,
                details: ['dora_documents' => 0],
            );
        }

        $untagged = [];
        foreach ($doraDocuments as $doc) {
            $docId = $doc->getId();
            if ($docId === null) {
                continue;
            }
            $activeTags = $this->entityTagRepository->findActiveFor(Document::class, $docId);
            $hasValidity = false;
            foreach ($activeTags as $entityTag) {
                $tag = $entityTag->getTag();
                if ($tag !== null && $tag->getName() === self::VALIDITY_TAG_NAME) {
                    $hasValidity = true;
                    break;
                }
            }
            if (!$hasValidity) {
                $untagged[] = [
                    'document_id' => $docId,
                    'title' => $doc->getOriginalFilename() ?? $doc->getFilename(),
                ];
            }
        }

        if ($untagged === []) {
            return new PolicyWizardCheckResult(
                checkId: self::CHECK_ID,
                score: 100.0,
                passed: true,
                details: ['dora_documents' => $total, 'tagged' => $total],
            );
        }

        $tagged = $total - count($untagged);
        $score = round(($tagged / $total) * 100, 1);

        return new PolicyWizardCheckResult(
            checkId: self::CHECK_ID,
            score: $score,
            passed: false,
            details: [
                'dora_documents' => $total,
                'tagged' => $tagged,
                'untagged_count' => count($untagged),
                'expected_tag' => self::VALIDITY_TAG_NAME,
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
