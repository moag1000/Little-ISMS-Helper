<?php

namespace App\Service;

use App\Entity\Document;
use App\Entity\Tenant;
use App\Repository\DocumentRepository;
use App\Repository\CorporateGovernanceRepository;

/**
 * Document Service - Business logic for Document Management with Corporate Structure awareness
 */
class DocumentService
{
    public function __construct(
        private DocumentRepository $documentRepository,
        private ?CorporateStructureService $corporateStructureService = null,
        private ?CorporateGovernanceRepository $governanceRepository = null
    ) {}

    /**
     * Get all documents visible to a tenant (own + inherited based on governance)
     *
     * @param Tenant $tenant The tenant
     * @return Document[] Array of documents
     */
    public function getDocumentsForTenant(Tenant $tenant): array
    {
        $parent = $tenant->getParent();

        // No parent or no corporate structure service - return own documents only
        if (!$parent || !$this->corporateStructureService || !$this->governanceRepository) {
            return $this->documentRepository->findByTenant($tenant);
        }

        // Check governance model for documents
        $governance = $this->governanceRepository->findGovernanceForScope($tenant, 'document');

        if (!$governance) {
            // No specific governance for documents - use default
            $governance = $this->governanceRepository->findDefaultGovernance($tenant);
        }

        $governanceModel = $governance?->getGovernanceModel();

        // If hierarchical governance, include parent documents
        if ($governanceModel && $governanceModel->value === 'hierarchical') {
            return $this->documentRepository->findByTenantIncludingParent($tenant, $parent);
        }

        // For shared or independent, return only own documents
        return $this->documentRepository->findByTenant($tenant);
    }

    /**
     * Get document inheritance information for a tenant
     *
     * @param Tenant $tenant The tenant
     * @return array{hasParent: bool, canInherit: bool, governanceModel: string|null}
     */
    public function getDocumentInheritanceInfo(Tenant $tenant): array
    {
        $parent = $tenant->getParent();

        if (!$parent || !$this->governanceRepository) {
            return [
                'hasParent' => false,
                'canInherit' => false,
                'governanceModel' => null,
            ];
        }

        $governance = $this->governanceRepository->findGovernanceForScope($tenant, 'document');

        if (!$governance) {
            $governance = $this->governanceRepository->findDefaultGovernance($tenant);
        }

        $governanceModel = $governance?->getGovernanceModel();
        $canInherit = $governanceModel && $governanceModel->value === 'hierarchical';

        return [
            'hasParent' => true,
            'canInherit' => $canInherit,
            'governanceModel' => $governanceModel?->value,
        ];
    }

    /**
     * Check if a document is inherited from parent
     *
     * @param Document $document The document to check
     * @param Tenant $currentTenant The current tenant viewing the document
     * @return bool True if document belongs to parent tenant
     */
    public function isInheritedDocument(Document $document, Tenant $currentTenant): bool
    {
        $documentTenant = $document->getTenant();

        if (!$documentTenant) {
            return false;
        }

        return $documentTenant->getId() !== $currentTenant->getId();
    }

    /**
     * Check if user can edit a document (not inherited)
     *
     * @param Document $document The document
     * @param Tenant $currentTenant The current tenant
     * @return bool True if document can be edited
     */
    public function canEditDocument(Document $document, Tenant $currentTenant): bool
    {
        return !$this->isInheritedDocument($document, $currentTenant);
    }

    /**
     * Get document statistics for a tenant including inherited documents
     *
     * @param Tenant $tenant The tenant
     * @return array{total: int, ownDocuments: int, inheritedDocuments: int}
     */
    public function getDocumentStatsWithInheritance(Tenant $tenant): array
    {
        $allDocuments = $this->getDocumentsForTenant($tenant);
        $ownDocuments = $this->documentRepository->findByTenant($tenant);

        return [
            'total' => count($allDocuments),
            'ownDocuments' => count($ownDocuments),
            'inheritedDocuments' => count($allDocuments) - count($ownDocuments),
        ];
    }
}
