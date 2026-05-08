<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard;

use App\Entity\Document;
use App\Entity\Tag;
use App\Entity\Tenant;
use App\Repository\DocumentRepository;
use App\Repository\EntityTagRepository;
use App\Repository\TagRepository;
use DateTimeImmutable;

/**
 * Policy-Wizard W4-C — existing-document inventory for brownfield tenants.
 *
 * Walks the tenant's `Document` register, surfaces every governance-grade
 * document (`category` ∈ {policy, programme, plan, methodology}) that is
 * NOT itself a Policy-Wizard output, and emits a per-row metadata bundle
 * the Step-0 Bestandsaufnahme template can render.
 *
 * "documentType" in the W4-C task spec maps to {@see Document::getCategory}
 * — this codebase stores governance-grade tags in the `category` column
 * (see `DocumentRepository::findByCategory`).
 *
 * Heuristic for `suggested_action`:
 *   - tagged `policy-wizard-generated`           → 'keep' (already managed)
 *   - older than {@see self::REPLACE_AGE_MONTHS} → 'replace'
 *   - everything else                             → 'review'
 */
class ExistingDocumentInventoryService
{
    public const ACTION_REPLACE = 'replace';
    public const ACTION_KEEP = 'keep';
    public const ACTION_MERGE_INTO_TOPIC = 'merge_into_topic';
    public const ACTION_SPLIT_TO_TOPICS = 'split_to_topics';

    /** Categories that count as governance-grade for the wizard inventory. */
    public const GOVERNANCE_CATEGORIES = ['policy', 'programme', 'plan', 'methodology'];

    /** Tag-name marker for Policy-Wizard outputs (mirrors DocumentGenerator §8.5). */
    public const POLICY_WIZARD_TAG = 'policy-wizard-generated';

    /** Documents older than this default to suggested_action='replace'. */
    public const REPLACE_AGE_MONTHS = 24;

    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly TagRepository $tagRepository,
        private readonly EntityTagRepository $entityTagRepository,
    ) {
    }

    /**
     * Returns one row per inventoried document, sorted most-recent first.
     *
     * @return list<array{
     *     id: int,
     *     title: string,
     *     documentType: string,
     *     lastApprovedAt: ?\DateTimeImmutable,
     *     ownerName: ?string,
     *     hasPolicyWizardTag: bool,
     *     suggestedAction: string,
     * }>
     */
    public function inventoryFor(Tenant $tenant): array
    {
        $tagged = $this->entityIdsTaggedAsPolicyWizard($tenant);
        $now = new DateTimeImmutable();
        $rows = [];

        foreach (self::GOVERNANCE_CATEGORIES as $category) {
            foreach ($this->documentRepository->findByCategoryAndTenant($tenant, $category) as $document) {
                if (!$document instanceof Document) {
                    continue;
                }
                $documentId = $document->getId();
                if ($documentId === null) {
                    continue;
                }

                $hasWizardTag = in_array($documentId, $tagged, true);
                $lastApprovedAt = $this->extractLastApprovedAt($document);
                $owner = $document->getUploadedBy();

                $rows[] = [
                    'id' => $documentId,
                    'title' => $this->extractTitle($document),
                    'documentType' => $category,
                    'lastApprovedAt' => $lastApprovedAt,
                    'ownerName' => $owner !== null ? $owner->getFullName() : null,
                    'hasPolicyWizardTag' => $hasWizardTag,
                    'suggestedAction' => $this->suggestAction(
                        $hasWizardTag,
                        $lastApprovedAt,
                        $now,
                    ),
                ];
            }
        }

        // Sort newest first; documents without a lastApprovedAt sink to the
        // bottom (epoch fallback).
        usort($rows, static function (array $a, array $b): int {
            $aTs = $a['lastApprovedAt']?->getTimestamp() ?? 0;
            $bTs = $b['lastApprovedAt']?->getTimestamp() ?? 0;
            return $bTs <=> $aTs;
        });

        return $rows;
    }

    /**
     * @return list<int>
     */
    private function entityIdsTaggedAsPolicyWizard(Tenant $tenant): array
    {
        $tag = $this->tagRepository->findOneByName($tenant, self::POLICY_WIZARD_TAG);
        if (!$tag instanceof Tag) {
            // Fall back to the global tag (tenant=null) — DocumentGenerator
            // creates per-tenant tags but defensive code looks both ways.
            $tag = $this->tagRepository->findOneByName(null, self::POLICY_WIZARD_TAG);
        }
        if (!$tag instanceof Tag) {
            return [];
        }
        return $this->entityTagRepository->findEntityIdsWithTag($tag, Document::class);
    }

    private function extractTitle(Document $document): string
    {
        // Prefer the tenant-friendly originalFilename; fall back to filename.
        $title = $document->getOriginalFilename();
        if ($title === null || $title === '') {
            $title = $document->getFilename();
        }
        return (string) ($title ?? '');
    }

    /**
     * Last-approved-at proxy: documents in this codebase do not carry a
     * dedicated approvedAt column, so we use updatedAt (last touch) and
     * fall back to uploadedAt.
     */
    private function extractLastApprovedAt(Document $document): ?DateTimeImmutable
    {
        $candidate = $document->getUpdatedAt() ?? $document->getUploadedAt();
        if ($candidate === null) {
            return null;
        }
        if ($candidate instanceof DateTimeImmutable) {
            return $candidate;
        }
        return DateTimeImmutable::createFromInterface($candidate);
    }

    private function suggestAction(
        bool $hasWizardTag,
        ?DateTimeImmutable $lastApprovedAt,
        DateTimeImmutable $now,
    ): string {
        if ($hasWizardTag) {
            return self::ACTION_KEEP;
        }
        if ($lastApprovedAt === null) {
            // No timestamp → nothing to compare; let the user decide.
            return 'review';
        }
        $cutoff = $now->modify('-' . self::REPLACE_AGE_MONTHS . ' months');
        if ($lastApprovedAt < $cutoff) {
            return self::ACTION_REPLACE;
        }
        return 'review';
    }
}
