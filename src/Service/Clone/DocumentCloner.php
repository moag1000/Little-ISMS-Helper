<?php

declare(strict_types=1);

namespace App\Service\Clone;

use App\Entity\Document;
use App\Entity\Tenant;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Document Cloner (C4-C1 — Klon-Funktionen).
 *
 * Use case: copy a policy template across departments, fork a published
 * document to draft a successor, replicate a control-evidence document
 * for a different audit cycle. The clone keeps the user-authored content
 * (title via originalFilename, description, policy body, review cadence,
 * classification) and resets the lifecycle.
 *
 * Reset on clone:
 *   - status → draft
 *   - isArchived → false
 *   - isImmutable → false (clone starts editable)
 *   - sha256Hash + contentHash cleared (re-derived on next file change)
 *   - version → 1.0
 *   - currentVersion / versions OneToMany NOT cloned (versions are
 *     immutable archive entries — they belong to the source document)
 *   - filename / filePath NOT cloned (file binary not duplicated;
 *     user can re-upload via the edit form)
 *   - supersedes / generatedFromTemplate / generatedFromWizardRun cleared
 *
 * Caller is expected to flush.
 */
final class DocumentCloner implements EntityClonerInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function supportsEntity(): string
    {
        return Document::class;
    }

    /**
     * @param Document $source
     */
    public function clone(object $source, ?Tenant $targetTenant = null, ?string $titleOverride = null): Document
    {
        if (!$source instanceof Document) {
            // @intentional-assertion: programmer error — wrong entity passed to cloner
            throw new \InvalidArgumentException(sprintf(
                'DocumentCloner expects %s, got %s',
                Document::class,
                $source::class,
            ));
        }

        $clone = new Document();

        $tenant = $targetTenant ?? $source->getTenant();
        if ($tenant instanceof Tenant) {
            $clone->setTenant($tenant);
        }

        $baseTitle = (string) $source->getOriginalFilename();
        $clone->setOriginalFilename($titleOverride !== null && $titleOverride !== ''
            ? $titleOverride
            : ($baseTitle !== '' ? $baseTitle . ' (Kopie)' : 'Kopie')
        );

        $clone->setCategory($source->getCategory());
        $clone->setDescription($source->getDescription());
        $clone->setEntityType($source->getEntityType());
        $clone->setEntityId($source->getEntityId());
        $clone->setUploadedBy($source->getUploadedBy());
        $clone->setOwnerPerson($source->getOwnerPerson());
        $clone->setTisaxInformationClassification($source->getTisaxInformationClassification());
        $clone->setInheritable($source->isInheritable());
        $clone->setOverrideAllowed($source->isOverrideAllowed());

        // Policy body + review cadence — most valuable template content.
        $clone->setPolicyBody($source->getPolicyBody());
        $clone->setReviewIntervalMonths($source->getReviewIntervalMonths());
        $clone->setNextReviewDate(null);  // re-plan on first publish
        $clone->setRequiresAcknowledgement($source->isRequiresAcknowledgement());

        // Reset lifecycle to draft; clone starts editable.
        $clone->setStatus('draft'); // @phpstan-ignore lifecycle.directSetStatus (initial state on clone pre-persist — matches entity-specific lifecycle.initial_marking)
        $clone->setIsArchived(false);
        $clone->setIsImmutable(false);
        $clone->setVersion('1.0');

        // File-reference fields — `filename` + `filePath` are NOT NULL on the
        // `document` table, so we initially mirror the source pointers (the
        // clone references the same on-disk file until the user re-uploads
        // via the edit form). Cloning the binary itself is intentionally NOT
        // done — that would duplicate evidence + invalidate the sha audit
        // chain. Hash fields are cleared so the next save recomputes them
        // (or sets them after a fresh upload).
        $clone->setFilename($source->getFilename() ?? 'cloned.bin');
        $clone->setFilePath($source->getFilePath());
        $clone->setMimeType($source->getMimeType());
        $clone->setFileSize($source->getFileSize());
        $clone->setSha256Hash(null);
        $clone->setContentHash(null);

        // Provenance refs cleared — clone is its own document.
        $clone->setSupersedes(null);
        $clone->setGeneratedFromTemplate(null);
        $clone->setGeneratedFromWizardRun(null);
        $clone->setSubstitutionVariables(null);

        $clone->setUploadedAt(new DateTimeImmutable());
        $clone->setUpdatedAt(null);

        $this->entityManager->persist($clone);

        return $clone;
    }
}
