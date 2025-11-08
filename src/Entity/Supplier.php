<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Delete;
use App\Repository\SupplierRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Serializer\Annotation\MaxDepth;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Supplier/Vendor Entity for ISO 27001 A.15 Supplier Relationships
 *
 * Manages third-party suppliers and their security assessments
 */
#[ORM\Entity(repositoryClass: SupplierRepository::class)]
#[ORM\Index(columns: ['criticality'], name: 'idx_supplier_criticality')]
#[ORM\Index(columns: ['next_assessment_date'], name: 'idx_supplier_next_assessment')]
#[ORM\Index(columns: ['status'], name: 'idx_supplier_status')]
#[ApiResource(
    operations: [
        new Get(
            security: "is_granted('ROLE_USER')",
            description: 'Retrieve a specific supplier by ID'
        ),
        new GetCollection(
            security: "is_granted('ROLE_USER')",
            description: 'Retrieve the collection of suppliers with filtering'
        ),
        new Post(
            security: "is_granted('ROLE_USER')",
            description: 'Create a new supplier'
        ),
        new Put(
            security: "is_granted('ROLE_USER')",
            description: 'Update an existing supplier'
        ),
        new Delete(
            security: "is_granted('ROLE_ADMIN')",
            description: 'Delete a supplier (Admin only)'
        ),
    ],
    normalizationContext: ['groups' => ['supplier:read']],
    denormalizationContext: ['groups' => ['supplier:write']]
)]
#[ApiFilter(SearchFilter::class, properties: ['name' => 'partial', 'status' => 'exact', 'criticality' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['name', 'criticality', 'nextAssessmentDate'])]
#[ApiFilter(DateFilter::class, properties: ['nextAssessmentDate', 'lastSecurityAssessment'])]
class Supplier
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Supplier name is required')]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $contactPerson = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Email(message: 'Invalid email address')]
    private ?string $email = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $address = null;

    /**
     * Service provided by supplier
     */
    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'Service description is required')]
    private ?string $serviceProvided = null;

    /**
     * Criticality: critical, high, medium, low
     */
    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['critical', 'high', 'medium', 'low'])]
    private ?string $criticality = 'medium';

    /**
     * Status: active, inactive, evaluation, terminated
     */
    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['active', 'inactive', 'evaluation', 'terminated'])]
    private ?string $status = 'evaluation';

    /**
     * Security assessment score (0-100)
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[Assert\Range(min: 0, max: 100)]
    private ?int $securityScore = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $lastSecurityAssessment = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $nextAssessmentDate = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $assessmentFindings = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $nonConformities = null;

    /**
     * Contractual SLAs and security requirements (JSON)
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $contractualSLAs = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $contractStartDate = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $contractEndDate = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $securityRequirements = null;

    /**
     * ISO 27001/ISO 22301 certification
     */
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $hasISO27001 = false;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $hasISO22301 = false;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $certifications = null;

    /**
     * Data processing agreement (GDPR)
     */
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $hasDPA = false;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $dpaSignedDate = null;

    /**
     * @var Collection<int, Asset>
     */
    #[ORM\ManyToMany(targetEntity: Asset::class)]
    #[ORM\JoinTable(name: 'supplier_asset')]
    private Collection $supportedAssets;

    /**
     * @var Collection<int, Risk>
     */
    #[ORM\ManyToMany(targetEntity: Risk::class)]
    #[ORM\JoinTable(name: 'supplier_risk')]
    private Collection $identifiedRisks;

    /**
     * @var Collection<int, Document>
     */
    #[ORM\ManyToMany(targetEntity: Document::class)]
    #[ORM\JoinTable(name: 'supplier_document')]
    private Collection $documents;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->supportedAssets = new ArrayCollection();
        $this->identifiedRisks = new ArrayCollection();
        $this->documents = new ArrayCollection();
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getContactPerson(): ?string
    {
        return $this->contactPerson;
    }

    public function setContactPerson(?string $contactPerson): static
    {
        $this->contactPerson = $contactPerson;
        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = $phone;
        return $this;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): static
    {
        $this->address = $address;
        return $this;
    }

    public function getServiceProvided(): ?string
    {
        return $this->serviceProvided;
    }

    public function setServiceProvided(string $serviceProvided): static
    {
        $this->serviceProvided = $serviceProvided;
        return $this;
    }

    public function getCriticality(): ?string
    {
        return $this->criticality;
    }

    public function setCriticality(string $criticality): static
    {
        $this->criticality = $criticality;
        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getSecurityScore(): ?int
    {
        return $this->securityScore;
    }

    public function setSecurityScore(?int $securityScore): static
    {
        $this->securityScore = $securityScore;
        return $this;
    }

    public function getLastSecurityAssessment(): ?\DateTimeInterface
    {
        return $this->lastSecurityAssessment;
    }

    public function setLastSecurityAssessment(?\DateTimeInterface $lastSecurityAssessment): static
    {
        $this->lastSecurityAssessment = $lastSecurityAssessment;
        return $this;
    }

    public function getNextAssessmentDate(): ?\DateTimeInterface
    {
        return $this->nextAssessmentDate;
    }

    public function setNextAssessmentDate(?\DateTimeInterface $nextAssessmentDate): static
    {
        $this->nextAssessmentDate = $nextAssessmentDate;
        return $this;
    }

    public function getAssessmentFindings(): ?string
    {
        return $this->assessmentFindings;
    }

    public function setAssessmentFindings(?string $assessmentFindings): static
    {
        $this->assessmentFindings = $assessmentFindings;
        return $this;
    }

    public function getNonConformities(): ?string
    {
        return $this->nonConformities;
    }

    public function setNonConformities(?string $nonConformities): static
    {
        $this->nonConformities = $nonConformities;
        return $this;
    }

    public function getContractualSLAs(): ?array
    {
        return $this->contractualSLAs;
    }

    public function setContractualSLAs(?array $contractualSLAs): static
    {
        $this->contractualSLAs = $contractualSLAs;
        return $this;
    }

    public function getContractStartDate(): ?\DateTimeInterface
    {
        return $this->contractStartDate;
    }

    public function setContractStartDate(?\DateTimeInterface $contractStartDate): static
    {
        $this->contractStartDate = $contractStartDate;
        return $this;
    }

    public function getContractEndDate(): ?\DateTimeInterface
    {
        return $this->contractEndDate;
    }

    public function setContractEndDate(?\DateTimeInterface $contractEndDate): static
    {
        $this->contractEndDate = $contractEndDate;
        return $this;
    }

    public function getSecurityRequirements(): ?string
    {
        return $this->securityRequirements;
    }

    public function setSecurityRequirements(?string $securityRequirements): static
    {
        $this->securityRequirements = $securityRequirements;
        return $this;
    }

    public function isHasISO27001(): bool
    {
        return $this->hasISO27001;
    }

    public function setHasISO27001(bool $hasISO27001): static
    {
        $this->hasISO27001 = $hasISO27001;
        return $this;
    }

    public function isHasISO22301(): bool
    {
        return $this->hasISO22301;
    }

    public function setHasISO22301(bool $hasISO22301): static
    {
        $this->hasISO22301 = $hasISO22301;
        return $this;
    }

    public function getCertifications(): ?string
    {
        return $this->certifications;
    }

    public function setCertifications(?string $certifications): static
    {
        $this->certifications = $certifications;
        return $this;
    }

    public function isHasDPA(): bool
    {
        return $this->hasDPA;
    }

    public function setHasDPA(bool $hasDPA): static
    {
        $this->hasDPA = $hasDPA;
        return $this;
    }

    public function getDpaSignedDate(): ?\DateTimeInterface
    {
        return $this->dpaSignedDate;
    }

    public function setDpaSignedDate(?\DateTimeInterface $dpaSignedDate): static
    {
        $this->dpaSignedDate = $dpaSignedDate;
        return $this;
    }

    /**
     * @return Collection<int, Asset>
     */
    public function getSupportedAssets(): Collection
    {
        return $this->supportedAssets;
    }

    public function addSupportedAsset(Asset $asset): static
    {
        if (!$this->supportedAssets->contains($asset)) {
            $this->supportedAssets->add($asset);
        }
        return $this;
    }

    public function removeSupportedAsset(Asset $asset): static
    {
        $this->supportedAssets->removeElement($asset);
        return $this;
    }

    /**
     * @return Collection<int, Risk>
     */
    public function getIdentifiedRisks(): Collection
    {
        return $this->identifiedRisks;
    }

    public function addIdentifiedRisk(Risk $risk): static
    {
        if (!$this->identifiedRisks->contains($risk)) {
            $this->identifiedRisks->add($risk);
        }
        return $this;
    }

    public function removeIdentifiedRisk(Risk $risk): static
    {
        $this->identifiedRisks->removeElement($risk);
        return $this;
    }

    /**
     * @return Collection<int, Document>
     */
    public function getDocuments(): Collection
    {
        return $this->documents;
    }

    public function addDocument(Document $document): static
    {
        if (!$this->documents->contains($document)) {
            $this->documents->add($document);
        }
        return $this;
    }

    public function removeDocument(Document $document): static
    {
        $this->documents->removeElement($document);
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    /**
     * Data Reuse: Calculate supplier risk score based on multiple factors
     */
    public function calculateRiskScore(): int
    {
        $score = 0;

        // Criticality weight (40%)
        $criticalityScore = match($this->criticality) {
            'critical' => 40,
            'high' => 30,
            'medium' => 15,
            'low' => 5,
            default => 0
        };
        $score += $criticalityScore;

        // Security score inverse (30%)
        if ($this->securityScore !== null) {
            $score += (100 - $this->securityScore) * 0.3;
        } else {
            $score += 30; // No assessment = high risk
        }

        // Missing certifications (15%)
        if (!$this->hasISO27001) $score += 10;
        if (!$this->hasISO22301 && $this->criticality === 'critical') $score += 5;

        // Missing DPA (10%)
        if (!$this->hasDPA) $score += 10;

        // Overdue assessment (5%)
        if ($this->isAssessmentOverdue()) $score += 5;

        return min(100, (int)$score);
    }

    /**
     * Check if security assessment is overdue
     */
    public function isAssessmentOverdue(): bool
    {
        if (!$this->nextAssessmentDate) {
            return $this->lastSecurityAssessment === null;
        }

        return $this->nextAssessmentDate < new \DateTime();
    }

    /**
     * Get assessment status badge
     */
    public function getAssessmentStatus(): string
    {
        if ($this->lastSecurityAssessment === null) {
            return 'not_assessed';
        }

        if ($this->isAssessmentOverdue()) {
            return 'overdue';
        }

        $thirtyDaysFromNow = new \DateTime('+30 days');
        if ($this->nextAssessmentDate && $this->nextAssessmentDate < $thirtyDaysFromNow) {
            return 'due_soon';
        }

        return 'current';
    }

    /**
     * Data Reuse: Aggregate risk level from identified risks
     */
    public function getAggregatedRiskLevel(): string
    {
        if ($this->identifiedRisks->isEmpty()) {
            return 'unknown';
        }

        $totalResidualRisk = 0;
        $count = 0;

        foreach ($this->identifiedRisks as $risk) {
            $totalResidualRisk += $risk->getResidualRiskLevel();
            $count++;
        }

        $avgRisk = $count > 0 ? $totalResidualRisk / $count : 0;

        if ($avgRisk >= 16) return 'critical';
        if ($avgRisk >= 9) return 'high';
        if ($avgRisk >= 4) return 'medium';
        return 'low';
    }

    /**
     * Check if supplier supports critical assets
     */
    public function supportsCriticalAssets(): bool
    {
        foreach ($this->supportedAssets as $asset) {
            if ($asset->isHighRisk()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get compliance status
     */
    public function getComplianceStatus(): array
    {
        return [
            'iso27001' => $this->hasISO27001,
            'iso22301' => $this->hasISO22301,
            'dpa' => $this->hasDPA,
            'security_assessment' => !$this->isAssessmentOverdue(),
            'overall_compliant' => $this->hasISO27001 && $this->hasDPA && !$this->isAssessmentOverdue()
        ];
    }
}
