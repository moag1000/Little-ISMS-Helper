<?php

namespace App\Entity;

use DateTimeInterface;
use DateTimeImmutable;
use App\Entity\Tenant;
use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Delete;
use App\Repository\InternalAuditRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Serializer\Annotation\MaxDepth;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: InternalAuditRepository::class)]
#[ORM\Index(columns: ['audit_number'], name: 'idx_audit_number')]
#[ORM\Index(columns: ['status'], name: 'idx_audit_status')]
#[ORM\Index(columns: ['scope_type'], name: 'idx_audit_scope_type')]
#[ORM\Index(columns: ['planned_date'], name: 'idx_audit_planned_date')]
#[ApiResource(
    operations: [
        new Get(
            description: 'Retrieve a specific internal audit by ID',
            security: "is_granted('ROLE_USER')"
        ),
        new GetCollection(
            description: 'Retrieve the collection of internal ISMS audits with filtering by status, scope, and date',
            security: "is_granted('ROLE_USER')"
        ),
        new Post(
            description: 'Create a new internal audit plan',
            security: "is_granted('ROLE_USER')"
        ),
        new Put(
            description: 'Update an existing internal audit',
            security: "is_granted('ROLE_USER')"
        ),
        new Delete(
            description: 'Delete an internal audit (Admin only)',
            security: "is_granted('ROLE_ADMIN')"
        ),
    ],
    normalizationContext: ['groups' => ['audit:read']],
    denormalizationContext: ['groups' => ['audit:write']]
)]
#[ApiFilter(SearchFilter::class, properties: ['title' => 'partial', 'auditNumber' => 'exact', 'status' => 'exact', 'scopeType' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['plannedDate', 'actualDate', 'status'])]
#[ApiFilter(DateFilter::class, properties: ['plannedDate', 'actualDate'])]
class InternalAudit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['audit:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    #[Groups(['audit:read', 'audit:write'])]
    #[Assert\NotBlank(message: 'Audit number is required')]
    #[Assert\Length(max: 50, maxMessage: 'Audit number cannot exceed {{ limit }} characters')]
    private ?string $auditNumber = null;

    #[ORM\Column(length: 255)]
    #[Groups(['audit:read', 'audit:write'])]
    #[Assert\NotBlank(message: 'Audit title is required')]
    #[Assert\Length(max: 255, maxMessage: 'Title cannot exceed {{ limit }} characters')]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['audit:read', 'audit:write'])]
    private ?string $scope = null;

    /**
     * Type of audit scope:
     * - full_isms: Complete ISMS audit
     * - compliance_framework: Specific framework (TISAX, DORA, etc.)
     * - asset: Specific assets
     * - asset_type: Type of assets (IT, personal, etc.)
     * - asset_group: Logical grouping of assets
     * - location: Physical location/site
     * - department: Organizational unit
     * - corporate_wide: Audit across entire corporate group (all subsidiaries)
     * - corporate_subsidiaries: Audit specific subsidiaries
     */
    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['audit:read', 'audit:write'])]
    #[Assert\Choice(
        choices: ['full_isms', 'compliance_framework', 'asset', 'asset_type', 'asset_group', 'location', 'department', 'corporate_wide', 'corporate_subsidiaries'],
        message: 'Scope type must be one of: {{ choices }}'
    )]
    private ?string $scopeType = 'full_isms';

    /**
     * JSON data containing scope details
     * For asset_type: {type: "IT", subtype: "server"}
     * For location: {location: "Munich Office", building: "HQ"}
     * For personnel: {group: "management", department: "IT"}
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups(['audit:read', 'audit:write'])]
    private ?array $scopeDetails = null;

    /**
     * @var Collection<int, Asset>
     */
    #[ORM\ManyToMany(targetEntity: Asset::class)]
    #[ORM\JoinTable(name: 'internal_audit_asset')]
    #[Groups(['audit:read'])]
    #[MaxDepth(1)]
    private Collection $scopedAssets;

    /**
     * @var Collection<int, Tenant>
     * Subsidiaries included in corporate audit scope
     */
    #[ORM\ManyToMany(targetEntity: Tenant::class)]
    #[ORM\JoinTable(name: 'internal_audit_subsidiary')]
    #[Groups(['audit:read'])]
    #[MaxDepth(1)]
    private Collection $auditedSubsidiaries;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['audit:read'])]
    #[MaxDepth(1)]
    private ?ComplianceFramework $complianceFramework = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['audit:read', 'audit:write'])]
    private ?string $objectives = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    #[Groups(['audit:read', 'audit:write'])]
    #[Assert\NotNull(message: 'Planned date is required')]
    private ?DateTimeInterface $plannedDate = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    #[Groups(['audit:read', 'audit:write'])]
    private ?DateTimeInterface $actualDate = null;

    #[ORM\Column(length: 100)]
    #[Groups(['audit:read', 'audit:write'])]
    #[Assert\NotBlank(message: 'Lead auditor is required')]
    #[Assert\Length(max: 100, maxMessage: 'Lead auditor name cannot exceed {{ limit }} characters')]
    private ?string $leadAuditor = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['audit:read', 'audit:write'])]
    private ?string $auditTeam = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['audit:read', 'audit:write'])]
    private ?string $auditedDepartments = null;

    #[ORM\Column(length: 50)]
    #[Groups(['audit:read', 'audit:write'])]
    #[Assert\NotBlank(message: 'Status is required')]
    #[Assert\Choice(
        choices: ['planned', 'in_progress', 'completed', 'reported'],
        message: 'Status must be one of: {{ choices }}'
    )]
    private ?string $status = 'planned';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['audit:read', 'audit:write'])]
    private ?string $findings = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['audit:read', 'audit:write'])]
    private ?string $nonConformities = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['audit:read', 'audit:write'])]
    private ?string $observations = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['audit:read', 'audit:write'])]
    private ?string $recommendations = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['audit:read', 'audit:write'])]
    private ?string $conclusion = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    #[Groups(['audit:read', 'audit:write'])]
    private ?DateTimeInterface $reportDate = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['audit:read'])]
    private ?DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['audit:read'])]
    private ?DateTimeInterface $updatedAt = null;

    
    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Tenant $tenant = null;

public function __construct()
    {
        $this->scopedAssets = new ArrayCollection();
        $this->auditedSubsidiaries = new ArrayCollection();
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAuditNumber(): ?string
    {
        return $this->auditNumber;
    }

    public function setAuditNumber(string $auditNumber): static
    {
        $this->auditNumber = $auditNumber;
        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;
        return $this;
    }

    public function getScope(): ?string
    {
        return $this->scope;
    }

    public function setScope(?string $scope): static
    {
        $this->scope = $scope;
        return $this;
    }

    public function getObjectives(): ?string
    {
        return $this->objectives;
    }

    public function setObjectives(?string $objectives): static
    {
        $this->objectives = $objectives;
        return $this;
    }

    public function getPlannedDate(): ?DateTimeInterface
    {
        return $this->plannedDate;
    }

    public function setPlannedDate(DateTimeInterface $plannedDate): static
    {
        $this->plannedDate = $plannedDate;
        return $this;
    }

    public function getActualDate(): ?DateTimeInterface
    {
        return $this->actualDate;
    }

    public function setActualDate(?DateTimeInterface $actualDate): static
    {
        $this->actualDate = $actualDate;
        return $this;
    }

    public function getLeadAuditor(): ?string
    {
        return $this->leadAuditor;
    }

    public function setLeadAuditor(string $leadAuditor): static
    {
        $this->leadAuditor = $leadAuditor;
        return $this;
    }

    public function getAuditTeam(): ?string
    {
        return $this->auditTeam;
    }

    public function setAuditTeam(?string $auditTeam): static
    {
        $this->auditTeam = $auditTeam;
        return $this;
    }

    public function getAuditedDepartments(): ?string
    {
        return $this->auditedDepartments;
    }

    public function setAuditedDepartments(?string $auditedDepartments): static
    {
        $this->auditedDepartments = $auditedDepartments;
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

    public function getFindings(): ?string
    {
        return $this->findings;
    }

    public function setFindings(?string $findings): static
    {
        $this->findings = $findings;
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

    public function getObservations(): ?string
    {
        return $this->observations;
    }

    public function setObservations(?string $observations): static
    {
        $this->observations = $observations;
        return $this;
    }

    public function getRecommendations(): ?string
    {
        return $this->recommendations;
    }

    public function setRecommendations(?string $recommendations): static
    {
        $this->recommendations = $recommendations;
        return $this;
    }

    public function getConclusion(): ?string
    {
        return $this->conclusion;
    }

    public function setConclusion(?string $conclusion): static
    {
        $this->conclusion = $conclusion;
        return $this;
    }

    public function getReportDate(): ?DateTimeInterface
    {
        return $this->reportDate;
    }

    public function setReportDate(?DateTimeInterface $reportDate): static
    {
        $this->reportDate = $reportDate;
        return $this;
    }

    public function getCreatedAt(): ?DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getScopeType(): ?string
    {
        return $this->scopeType;
    }

    public function setScopeType(?string $scopeType): static
    {
        $this->scopeType = $scopeType;
        return $this;
    }

    public function getScopeDetails(): ?array
    {
        return $this->scopeDetails;
    }

    public function setScopeDetails(?array $scopeDetails): static
    {
        $this->scopeDetails = $scopeDetails;
        return $this;
    }

    /**
     * @return Collection<int, Asset>
     */
    public function getScopedAssets(): Collection
    {
        return $this->scopedAssets;
    }

    public function addScopedAsset(Asset $asset): static
    {
        if (!$this->scopedAssets->contains($asset)) {
            $this->scopedAssets->add($asset);
        }

        return $this;
    }

    public function removeScopedAsset(Asset $asset): static
    {
        $this->scopedAssets->removeElement($asset);
        return $this;
    }

    /**
     * @return Collection<int, Tenant>
     */
    public function getAuditedSubsidiaries(): Collection
    {
        return $this->auditedSubsidiaries;
    }

    public function addAuditedSubsidiary(Tenant $tenant): static
    {
        if (!$this->auditedSubsidiaries->contains($tenant)) {
            $this->auditedSubsidiaries->add($tenant);
        }

        return $this;
    }

    public function removeAuditedSubsidiary(Tenant $tenant): static
    {
        $this->auditedSubsidiaries->removeElement($tenant);
        return $this;
    }

    public function getScopedFramework(): ?ComplianceFramework
    {
        return $this->complianceFramework;
    }

    public function setScopedFramework(?ComplianceFramework $complianceFramework): static
    {
        $this->complianceFramework = $complianceFramework;
        return $this;
    }

    /**
     * Get human-readable scope description
     */
    public function getScopeDescription(): string
    {
        return match($this->scopeType) {
            'full_isms' => 'Vollständiges ISMS Audit',
            'compliance_framework' => $this->complianceFramework
                ? 'Compliance Audit: ' . $this->complianceFramework->getName()
                : 'Compliance Framework Audit',
            'asset' => sprintf('Asset Audit (%d Assets)', $this->scopedAssets->count()),
            'asset_type' => 'Asset-Typ Audit: ' . ($this->scopeDetails['type'] ?? 'N/A'),
            'asset_group' => 'Asset-Gruppe Audit: ' . ($this->scopeDetails['group'] ?? 'N/A'),
            'location' => 'Standort Audit: ' . ($this->scopeDetails['location'] ?? 'N/A'),
            'department' => 'Abteilungs Audit: ' . ($this->scopeDetails['department'] ?? 'N/A'),
            'corporate_wide' => sprintf('Konzernweites Audit (%d Tochtergesellschaften)', $this->auditedSubsidiaries->count()),
            'corporate_subsidiaries' => sprintf('Tochtergesellschafts-Audit (%d Gesellschaften)', $this->auditedSubsidiaries->count()),
            default => 'Unbekannter Scope',
        };
    }

    /**
     * Check if audit is scoped to specific assets
     */
    public function hasAssetScope(): bool
    {
        return in_array($this->scopeType, ['asset', 'asset_type', 'asset_group']);
    }

    /**
     * Check if audit is compliance framework specific
     */
    public function isComplianceAudit(): bool
    {
        return $this->scopeType === 'compliance_framework' && $this->complianceFramework !== null;
    }

    /**
     * Check if audit is corporate-scoped (covers multiple tenants)
     */
    public function isCorporateAudit(): bool
    {
        return in_array($this->scopeType, ['corporate_wide', 'corporate_subsidiaries']);
    }

    /**
     * Check if audit is corporate-wide (all subsidiaries)
     */
    public function isCorporateWideAudit(): bool
    {
        return $this->scopeType === 'corporate_wide';
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function setTenant(?Tenant $tenant): static
    {
        $this->tenant = $tenant;
        return $this;
    }
}
