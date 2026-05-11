<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\Entity\Document;

/**
 * Contract for the document evidence attachment service.
 * Extracted to allow test-double injection in DocumentApprovalListener tests.
 */
interface DocumentEvidenceAttachmentInterface
{
    /**
     * @return array{iso27001_links: int, requirement_links: int, skipped: int}
     */
    public function attachOnApproval(Document $document): array;
}
