<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ComplianceRequirement;
use App\Entity\Control;
use App\Entity\Document;
use App\Entity\RiskTreatmentPlan;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\ComplianceRequirementRepository;
use App\Repository\ControlRepository;
use App\Repository\DocumentRepository;
use App\Repository\RiskTreatmentPlanRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Evidence Collection Service
 *
 * Manages evidence documents for ISO 27001 audit preparation.
 * Links uploaded files to Controls, ComplianceRequirements, and RiskTreatmentPlans.
 */
class EvidenceCollectionService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ControlRepository $controlRepository,
        private readonly ComplianceRequirementRepository $requirementRepository,
        private readonly RiskTreatmentPlanRepository $rtpRepository,
        private readonly DocumentRepository $documentRepository,
        private readonly FileUploadSecurityService $fileUploadSecurityService,
        private readonly string $projectDir,
    ) {}

    /**
     * Upload a document and link it to an entity.
     *
     * @throws \InvalidArgumentException if entity type is unsupported or entity not found
     * @throws \Symfony\Component\HttpFoundation\File\Exception\FileException on upload validation failure
     */
    public function uploadAndLink(
        UploadedFile $file,
        string $entityType,
        int $entityId,
        User $user,
        Tenant $tenant,
    ): Document {
        // Validate upload
        $this->fileUploadSecurityService->validateUploadedFile($file);

        // Generate safe filename
        $safeFilename = $this->fileUploadSecurityService->generateSafeFilename($file);

        // Extract metadata before moving
        $originalFilename = $file->getClientOriginalName();
        $mimeType = $file->getMimeType();
        $fileSize = $file->getSize();

        // Move file
        $uploadDir = $this->projectDir . '/public/uploads/documents';
        $file->move($uploadDir, $safeFilename);

        // Compute hash
        $filePath = $uploadDir . '/' . $safeFilename;
        $sha256 = hash_file('sha256', $filePath);

        // Create Document entity
        $document = new Document();
        $document->setFilename($safeFilename);
        $document->setOriginalFilename($originalFilename);
        $document->setMimeType($mimeType);
        $document->setFileSize($fileSize);
        $document->setFilePath('/uploads/documents/' . $safeFilename);
        $document->setCategory('evidence');
        $document->setEntityType($entityType);
        $document->setEntityId($entityId);
        $document->setUploadedBy($user);
        $document->setTenant($tenant);
        $document->setSha256Hash($sha256);

        $this->entityManager->persist($document);

        // Link to entity
        $this->linkToEntity($document, $entityType, $entityId);

        $this->entityManager->flush();

        return $document;
    }

    /**
     * Link an existing document to an entity.
     *
     * @throws \InvalidArgumentException if entity type is unsupported or entity not found
     */
    public function linkToEntity(Document $document, string $entityType, int $entityId): void
    {
        $entity = $this->resolveEntity($entityType, $entityId);

        match ($entityType) {
            'control' => $entity->addEvidenceDocument($document),
            'requirement' => $entity->addEvidenceDocument($document),
            'risk_treatment_plan' => $entity->addEvidenceDocument($document),
        };

        $this->entityManager->flush();
    }

    /**
     * Unlink a document from an entity.
     *
     * @throws \InvalidArgumentException if entity type is unsupported or entity not found
     */
    public function unlinkFromEntity(Document $document, string $entityType, int $entityId): void
    {
        $entity = $this->resolveEntity($entityType, $entityId);

        match ($entityType) {
            'control' => $entity->removeEvidenceDocument($document),
            'requirement' => $entity->removeEvidenceDocument($document),
            'risk_treatment_plan' => $entity->removeEvidenceDocument($document),
        };

        $this->entityManager->flush();
    }

    /**
     * Get evidence coverage report for a tenant.
     *
     * @return array{
     *     total_controls: int,
     *     controls_with_evidence: int,
     *     coverage_percent: float,
     *     total_requirements: int,
     *     requirements_with_evidence: int,
     *     missing: array<array{type: string, id: int, name: string}>
     * }
     */
    public function getEvidenceCoverage(Tenant $tenant): array
    {
        $controls = $this->controlRepository->findByTenant($tenant);
        $requirements = $this->requirementRepository->findAll();

        $totalControls = count($controls);
        $controlsWithEvidence = 0;
        $missing = [];

        foreach ($controls as $control) {
            if ($control->getEvidenceDocuments()->count() > 0) {
                $controlsWithEvidence++;
            } else {
                $missing[] = [
                    'type' => 'control',
                    'id' => $control->getId(),
                    'name' => $control->getControlId() . ': ' . $control->getName(),
                ];
            }
        }

        $totalRequirements = count($requirements);
        $requirementsWithEvidence = 0;

        foreach ($requirements as $requirement) {
            if ($requirement->getEvidenceDocuments()->count() > 0) {
                $requirementsWithEvidence++;
            }
        }

        $coveragePercent = $totalControls > 0
            ? round(($controlsWithEvidence / $totalControls) * 100, 1)
            : 0.0;

        return [
            'total_controls' => $totalControls,
            'controls_with_evidence' => $controlsWithEvidence,
            'coverage_percent' => $coveragePercent,
            'total_requirements' => $totalRequirements,
            'requirements_with_evidence' => $requirementsWithEvidence,
            'missing' => $missing,
        ];
    }

    /**
     * Get evidence documents for an entity.
     *
     * @return Document[]
     * @throws \InvalidArgumentException if entity type is unsupported or entity not found
     */
    public function getEvidenceForEntity(string $entityType, int $entityId): array
    {
        $entity = $this->resolveEntity($entityType, $entityId);

        return match ($entityType) {
            'control', 'requirement', 'risk_treatment_plan' => $entity->getEvidenceDocuments()->toArray(),
        };
    }

    /**
     * Get recent evidence uploads for a tenant.
     *
     * @return Document[]
     */
    public function getRecentEvidence(Tenant $tenant, int $limit = 10): array
    {
        return $this->documentRepository->createQueryBuilder('d')
            ->where('d.tenant = :tenant')
            ->andWhere('d.category = :category')
            ->andWhere('d.isArchived = false')
            ->setParameter('tenant', $tenant)
            ->setParameter('category', 'evidence')
            ->orderBy('d.uploadedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Get total evidence document count for a tenant.
     */
    public function getTotalEvidenceCount(Tenant $tenant): int
    {
        return (int) $this->documentRepository->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->where('d.tenant = :tenant')
            ->andWhere('d.category = :category')
            ->andWhere('d.isArchived = false')
            ->setParameter('tenant', $tenant)
            ->setParameter('category', 'evidence')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Get controls with their evidence coverage for a tenant.
     *
     * @return array<array{control: Control, evidenceCount: int, hasEvidence: bool}>
     */
    public function getControlEvidenceCoverage(Tenant $tenant): array
    {
        $controls = $this->controlRepository->findAllInIsoOrder($tenant);
        $result = [];

        foreach ($controls as $control) {
            $evidenceCount = $control->getEvidenceDocuments()->count();
            $result[] = [
                'control' => $control,
                'evidenceCount' => $evidenceCount,
                'hasEvidence' => $evidenceCount > 0,
            ];
        }

        return $result;
    }

    /**
     * Resolve an entity from type and ID.
     *
     * @throws \InvalidArgumentException if entity type is unsupported or entity not found
     */
    private function resolveEntity(string $entityType, int $entityId): Control|ComplianceRequirement|RiskTreatmentPlan
    {
        $entity = match ($entityType) {
            'control' => $this->controlRepository->find($entityId),
            'requirement' => $this->requirementRepository->find($entityId),
            'risk_treatment_plan' => $this->rtpRepository->find($entityId),
            default => throw new \InvalidArgumentException(
                sprintf('Unsupported entity type: %s', $entityType)
            ),
        };

        if ($entity === null) {
            throw new \InvalidArgumentException(
                sprintf('Entity of type "%s" with ID %d not found', $entityType, $entityId)
            );
        }

        return $entity;
    }
}
