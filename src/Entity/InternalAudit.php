<?php

declare(strict_types=1);

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
use App\State\TenantAwareStateProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Serializer\Annotation\MaxDepth;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: InternalAuditRepository::class)]
#[ORM\Index(name: 'idx_audit_number', columns: ['audit_number'])]
#[ORM\Index(name: 'idx_audit_status', columns: ['status'])]
#[ORM\Index(name: 'idx_audit_scope_type', columns: ['scope_type'])]
#[ORM\Index(name: 'idx_audit_planned_date', columns: ['planned_date'])]
#[ApiResource(
    operations: [
        new Get(
            description: 'Retrieve a specific internal audit by ID',
            security: "is_granted('API_VIEW', object)"
        ),
        new GetCollection(
            description: 'Retrieve the collection of internal ISMS audits with filtering by status, scope, and date',
            security: "is_granted('ROLE_USER')"
        ),
        new Post(
            description: 'Create a new internal audit plan',
            securityPostDenormalize: "is_granted('API_CREATE', object)"
        ),
        new Put(
            description: 'Update an existing internal audit',
            security: "is_granted('API_EDIT', object)"
        ),
        new Delete(
            description: 'Delete an internal audit (Admin only)',
            security: "is_granted('API_DELETE', object)"
        ),
    ],
    normalizationContext: ['groups' => ['audit:read']],
    denormalizationContext: ['groups' => ['audit:write']],
    processor: TenantAwareStateProcessor::class
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
    #[ORM\JoinColumn(name: 'scoped_framework_id', nullable: true)]
    #[Groups(['audit:read'])]
    #[MaxDepth(1)]
    private ?ComplianceFramework $complianceFramework = null;

    /**
     * Sprint 3 / B4 — Additional frameworks covered by the same audit.
     * Real-world internal audits often verify 27001 + NIS2 + DORA in one
     * pass; a single `scoped_framework_id` cannot express that. This M:M
     * is additive: the primary framework stays in `complianceFramework`
     * for backward compatibility, further frameworks land here.
     */
    #[ORM\ManyToMany(targetEntity: ComplianceFramework::class)]
    #[ORM\JoinTable(name: 'internal_audit_additional_framework')]
    #[Groups(['audit:read'])]
    #[MaxDepth(1)]
    private Collection $additionalScopedFrameworks;

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

    /**
     * Phase 9.P2.4 — Konzern-Audit-Programm.
     *
     * parentAudit: when the Holding-CISO runs "Derive audits for
     * subsidiaries" on a program audit, the generated Tochter-audits
     * point back to the original. Lets the group roll up findings,
     * compare subsidiary vs. subsidiary execution, and chase laggards
     * without keeping a separate program table.
     *
     * An audit becomes a "program" implicitly when other audits
     * reference it as parent (isProgram() below). No separate flag.
     */
    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'derivedAudits')]
    #[ORM\JoinColumn(name: 'parent_audit_id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['audit:read'])]
    private ?InternalAudit $parentAudit = null;

    /**
     * @var Collection<int, InternalAudit>
     */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parentAudit')]
    private Collection $derivedAudits;

    /**
     * H-01: Structured findings (ISO 27001 Clause 10.1). Replaces free-text `findings`.
     *
     * @var Collection<int, AuditFinding>
     */
    #[ORM\OneToMany(targetEntity: AuditFinding::class, mappedBy: 'audit', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $structuredFindings;

public function __construct()
    {
        $this->scopedAssets = new ArrayCollection();
        $this->auditedSubsidiaries = new ArrayCollection();
        $this->structuredFindings = new ArrayCollection();
        $this->derivedAudits = new ArrayCollection();
        $this->additionalScopedFrameworks = new ArrayCollection();
        $this->createdAt = new DateTimeImmutable();
    }

    /** @return Collection<int, ComplianceFramework> */
    public function getAdditionalScopedFrameworks(): Collection
    {
        return $this->additionalScopedFrameworks;
    }

    public function addAdditionalScopedFramework(ComplianceFramework $framework): static
    {
        if (!$this->additionalScopedFrameworks->contains($framework)) {
            $this->additionalScopedFrameworks->add($framework);
        }
        return $this;
    }

    public function removeAdditionalScopedFramework(ComplianceFramework $framework): static
    {
        $this->additionalScopedFrameworks->removeElement($framework);
        return $this;
    }

    /**
     * Returns all frameworks covered by this audit — primary (if set) +
     * every additional framework. Useful for the audit report template.
     *
     * @return list<ComplianceFramework>
     */
    public function getAllScopedFrameworks(): array
    {
        $all = [];
        if ($this->complianceFramework instanceof ComplianceFramework) {
            $all[(int) $this->complianceFramework->id] = $this->complianceFramework;
        }
        foreach ($this->additionalScopedFrameworks as $fw) {
            if (!$fw instanceof ComplianceFramework) {
                continue;
            }
            $all[(int) $fw->id] = $fw;
        }
        return array_values($all);
    }

    public function getParentAudit(): ?self
    {
        return $this->parentAudit;
    }

    public function setParentAudit(?self $parent): static
    {
        $this->parentAudit = $parent;
        return $this;
    }

    /** @return Collection<int, InternalAudit> */
    public function getDerivedAudits(): Collection
    {
        return $this->derivedAudits;
    }

    /**
     * True when at least one other audit has been derived from this one
     * (i.e. this audit acts as a Konzern-program template).
     */
    public function isProgram(): bool
    {
        return $this->derivedAudits->count() > 0;
    }

    /** @return Collection<int, AuditFinding> */
    public function getStructuredFindings(): Collection
    {
        return $this->structuredFindings;
    }

    public function addStructuredFinding(AuditFinding $finding): static
    {
        if (!$this->structuredFindings->contains($finding)) {
            $this->structuredFindings->add($finding);
            $finding->setAudit($this);
        }
        return $this;
    }

    public function removeStructuredFinding(AuditFinding $finding): static
    {
        if ($this->structuredFindings->removeElement($finding)) {
            if ($finding->getAudit() === $this) {
                $finding->setAudit(null);
            }
        }
        return $this;
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
            'compliance_framework' => $this->complianceFramework instanceof ComplianceFramework
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
        return $this->scopeType === 'compliance_framework' && $this->complianceFramework instanceof ComplianceFramework;
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
