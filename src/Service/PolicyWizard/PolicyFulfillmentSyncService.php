<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard;

use App\Entity\Document;
use App\Entity\Tenant;
use App\Entity\WizardRun;
use App\Repository\ComplianceRequirementRepository;
use App\Service\ComplianceRequirementFulfillmentService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Policy-Wizard — ComplianceRequirementFulfillment sync (2026-06-20).
 *
 * Closes the gap between policy generation and the per-requirement
 * compliance view: when the Policy-Wizard emits a Document,
 * {@see SoaAutoUpdateService} already bumps Control.implementationStatus
 * in the SoA, but ComplianceRequirementFulfillment rows were not touched.
 * This service mirrors the same STATUS_RANK + bump-only (no-downgrade)
 * logic for every requirement linked to the template's catalogues
 * (Annex A, BSI Bausteine, BSI Anforderungen, DORA Articles, ISO 27701).
 *
 * Status-transition matrix (NEVER downgrades):
 *   not_started / not_implemented  →  in_progress
 *   in_progress                    →  unchanged (manual progress preserved)
 *   implemented                    →  unchanged
 *   verified                       →  unchanged
 *
 * Additionally:
 *   - sets fulfillmentPercentage to 25 when it was still 0 (conservative signal)
 *   - attaches the generated Document as evidence via addEvidenceDocument()
 */
final class PolicyFulfillmentSyncService
{
    private const string TARGET_STATUS = 'in_progress';

    /**
     * Status-rank mirror of SoaAutoUpdateService::STATUS_RANK.
     * Only statuses valid for ComplianceRequirementFulfillment are included
     * (allowed: not_started, in_progress, implemented, verified).
     */
    private const array STATUS_RANK = [
        'not_started'      => 0,
        'not_implemented'  => 0,
        'in_progress'      => 3,
        'implemented'      => 4,
        'verified'         => 5,
    ];

    public function __construct(
        private readonly ComplianceRequirementFulfillmentService $fulfillmentService,
        private readonly ComplianceRequirementRepository $requirementRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Walk the Document's source-template linked IDs and bump every
     * ComplianceRequirementFulfillment from a lower status to TARGET_STATUS.
     *
     * Returns a map of requirement_id → new_status for every row that was
     * actually changed. An empty map means "no changes" (template had no links,
     * or every linked row was already at or above target).
     *
     * @return array<string, string> requirement_id => new_status
     */
    public function syncForDocument(Document $document, WizardRun $run): array
    {
        $template = $document->getGeneratedFromTemplate();
        if ($template === null) {
            return [];
        }

        $tenant = $document->getTenant();
        if (!$tenant instanceof Tenant) {
            return [];
        }

        // Collect all requirement IDs from all linked fields, deduped and non-empty.
        $allIds = array_values(array_unique(array_filter(
            array_merge(
                $template->getLinkedAnnexAControls() ?? [],
                $template->getLinkedBausteine() ?? [],
                $template->getLinkedBsiBausteine() ?? [],
                $template->getLinkedDoraArticles() ?? [],
                $template->getIso27701Clauses2025() ?? [],
            ),
            static fn (string $r): bool => $r !== '',
        )));

        if ($allIds === []) {
            return [];
        }

        $targetRank = self::STATUS_RANK[self::TARGET_STATUS];
        $result = [];

        foreach ($allIds as $reqId) {
            $requirements = $this->requirementRepository->findBy(['requirementId' => $reqId]);
            if ($requirements === []) {
                $this->logger->debug(
                    'PolicyFulfillmentSync: no ComplianceRequirement found for id {id}',
                    ['id' => $reqId],
                );
                continue;
            }

            foreach ($requirements as $requirement) {
                $fulfillment = $this->fulfillmentService->getOrCreateFulfillment($tenant, $requirement);

                $currentStatus = $fulfillment->getStatus();
                $currentRank   = self::STATUS_RANK[$currentStatus] ?? 0;

                if ($currentRank >= $targetRank) {
                    continue; // do not downgrade — manual progress preserved
                }

                $fulfillment->setStatus(self::TARGET_STATUS);

                // Conservative partial-completion signal when still at zero.
                if ($fulfillment->getFulfillmentPercentage() === 0) {
                    $fulfillment->setFulfillmentPercentage(25);
                }

                // Attach the generated policy as evidence.
                $fulfillment->addEvidenceDocument($document);

                $this->entityManager->persist($fulfillment);
                $result[$reqId] = self::TARGET_STATUS;
            }
        }

        if ($result !== []) {
            $this->entityManager->flush();
        }

        return $result;
    }
}
