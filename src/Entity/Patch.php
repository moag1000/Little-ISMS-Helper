<?php

namespace App\Entity;

use App\Entity\Tenant;
use App\Repository\PatchRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Patch Entity for NIS2 Compliance (Art. 21.2.d)
 * Patch Management and Remediation Tracking
 */
#[ORM\Entity(repositoryClass: PatchRepository::class)]
#[ORM\Table(name: 'patches')]
#[ORM\Index(columns: ['patch_id'], name: 'idx_patch_id')]
#[ORM\Index(columns: ['status'], name: 'idx_patch_status')]
#[ORM\Index(columns: ['release_date'], name: 'idx_patch_release')]
#[ORM\Index(columns: ['deployment_deadline'], name: 'idx_patch_deadline')]
class Patch
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Patch identifier (e.g., KB5012345, MS24-001)
     */
    #[ORM\Column(length: 100)]
    private ?string $patchId = null;

    /**
     * Patch title/name
     */
    #[ORM\Column(length: 255)]
    private ?string $title = null;

    /**
     * Detailed description
     */
    #[ORM\Column(type: Types::TEXT)]
    private ?string $description = null;

    /**
     * Related vulnerability (if applicable)
     */
    #[ORM\ManyToOne(targetEntity: Vulnerability::class, inversedBy: 'patches')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Vulnerability $vulnerability = null;

    /**
     * Vendor/manufacturer
     */
    #[ORM\Column(length: 100)]
    private ?string $vendor = null;

    /**
     * Affected product/software
     */
    #[ORM\Column(length: 255)]
    private ?string $product = null;

    /**
     * Patch version
     */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $version = null;

    /**
     * Patch type
     * - security: Security patch
     * - critical: Critical update
     * - feature: Feature update
     * - bugfix: Bug fix
     * - hotfix: Emergency hotfix
     */
    #[ORM\Column(length: 20)]
    private ?string $patchType = 'security';

    /**
     * Patch priority
     * - critical: Must be deployed immediately
     * - high: Deploy within 7 days
     * - medium: Deploy within 30 days
     * - low: Deploy within 90 days
     */
    #[ORM\Column(length: 20)]
    private ?string $priority = 'medium';

    /**
     * Affected assets
     *
     * @var Collection<int, Asset>
     */
    #[ORM\ManyToMany(targetEntity: Asset::class)]
    #[ORM\JoinTable(name: 'patch_asset')]
    private Collection $affectedAssets;

    /**
     * Patch status
     * - pending: Awaiting deployment
     * - testing: Currently being tested
     * - approved: Approved for deployment
     * - deployed: Successfully deployed
     * - failed: Deployment failed
     * - rolled_back: Deployment rolled back
     * - not_applicable: Not applicable to this environment
     */
    #[ORM\Column(length: 30)]
    private string $status = 'pending';

    /**
     * Date patch was released by vendor
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private ?\DateTimeImmutable $releaseDate = null;

    /**
     * Deployment deadline (NIS2 compliance)
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deploymentDeadline = null;

    /**
     * Date patch was deployed
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deployedDate = null;

    /**
     * Responsible person for deployment
     */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $responsiblePerson = null;

    /**
     * Testing notes
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $testingNotes = null;

    /**
     * Deployment notes
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $deploymentNotes = null;

    /**
     * Rollback plan
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $rollbackPlan = null;

    /**
     * Does this patch require downtime?
     */
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $requiresDowntime = false;

    /**
     * Estimated downtime in minutes
     */
    #[ORM\Column(nullable: true)]
    private ?int $estimatedDowntimeMinutes = null;

    /**
     * Does this patch require reboot?
     */
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $requiresReboot = false;

    /**
     * Known issues with this patch
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $knownIssues = null;

    /**
     * Dependencies (other patches required)
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $dependencies = [];

    /**
     * Download URL
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $downloadUrl = null;

    /**
     * Documentation URL
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $documentationUrl = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    
    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Tenant $tenant = null;

public function __construct()
    {
        $this->affectedAssets = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->releaseDate = new \DateTimeImmutable();
        $this->dependencies = [];
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPatchId(): ?string
    {
        return $this->patchId;
    }

    public function setPatchId(string $patchId): static
    {
        $this->patchId = $patchId;
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getVulnerability(): ?Vulnerability
    {
        return $this->vulnerability;
    }

    public function setVulnerability(?Vulnerability $vulnerability): static
    {
        $this->vulnerability = $vulnerability;
        return $this;
    }

    public function getVendor(): ?string
    {
        return $this->vendor;
    }

    public function setVendor(string $vendor): static
    {
        $this->vendor = $vendor;
        return $this;
    }

    public function getProduct(): ?string
    {
        return $this->product;
    }

    public function setProduct(string $product): static
    {
        $this->product = $product;
        return $this;
    }

    public function getVersion(): ?string
    {
        return $this->version;
    }

    public function setVersion(?string $version): static
    {
        $this->version = $version;
        return $this;
    }

    public function getPatchType(): ?string
    {
        return $this->patchType;
    }

    public function setPatchType(string $patchType): static
    {
        $this->patchType = $patchType;
        return $this;
    }

    public function getPriority(): ?string
    {
        return $this->priority;
    }

    public function setPriority(string $priority): static
    {
        $this->priority = $priority;
        return $this;
    }

    /**
     * @return Collection<int, Asset>
     */
    public function getAffectedAssets(): Collection
    {
        return $this->affectedAssets;
    }

    public function addAffectedAsset(Asset $asset): static
    {
        if (!$this->affectedAssets->contains($asset)) {
            $this->affectedAssets->add($asset);
        }

        return $this;
    }

    public function removeAffectedAsset(Asset $asset): static
    {
        $this->affectedAssets->removeElement($asset);
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getReleaseDate(): ?\DateTimeImmutable
    {
        return $this->releaseDate;
    }

    public function setReleaseDate(\DateTimeImmutable $releaseDate): static
    {
        $this->releaseDate = $releaseDate;
        return $this;
    }

    public function getDeploymentDeadline(): ?\DateTimeImmutable
    {
        return $this->deploymentDeadline;
    }

    public function setDeploymentDeadline(?\DateTimeImmutable $deploymentDeadline): static
    {
        $this->deploymentDeadline = $deploymentDeadline;
        return $this;
    }

    public function getDeployedDate(): ?\DateTimeImmutable
    {
        return $this->deployedDate;
    }

    public function setDeployedDate(?\DateTimeImmutable $deployedDate): static
    {
        $this->deployedDate = $deployedDate;
        return $this;
    }

    public function getResponsiblePerson(): ?string
    {
        return $this->responsiblePerson;
    }

    public function setResponsiblePerson(?string $responsiblePerson): static
    {
        $this->responsiblePerson = $responsiblePerson;
        return $this;
    }

    public function getTestingNotes(): ?string
    {
        return $this->testingNotes;
    }

    public function setTestingNotes(?string $testingNotes): static
    {
        $this->testingNotes = $testingNotes;
        return $this;
    }

    public function getDeploymentNotes(): ?string
    {
        return $this->deploymentNotes;
    }

    public function setDeploymentNotes(?string $deploymentNotes): static
    {
        $this->deploymentNotes = $deploymentNotes;
        return $this;
    }

    public function getRollbackPlan(): ?string
    {
        return $this->rollbackPlan;
    }

    public function setRollbackPlan(?string $rollbackPlan): static
    {
        $this->rollbackPlan = $rollbackPlan;
        return $this;
    }

    public function isRequiresDowntime(): bool
    {
        return $this->requiresDowntime;
    }

    public function setRequiresDowntime(bool $requiresDowntime): static
    {
        $this->requiresDowntime = $requiresDowntime;
        return $this;
    }

    public function getEstimatedDowntimeMinutes(): ?int
    {
        return $this->estimatedDowntimeMinutes;
    }

    public function setEstimatedDowntimeMinutes(?int $estimatedDowntimeMinutes): static
    {
        $this->estimatedDowntimeMinutes = $estimatedDowntimeMinutes;
        return $this;
    }

    public function isRequiresReboot(): bool
    {
        return $this->requiresReboot;
    }

    public function setRequiresReboot(bool $requiresReboot): static
    {
        $this->requiresReboot = $requiresReboot;
        return $this;
    }

    public function getKnownIssues(): ?string
    {
        return $this->knownIssues;
    }

    public function setKnownIssues(?string $knownIssues): static
    {
        $this->knownIssues = $knownIssues;
        return $this;
    }

    public function getDependencies(): ?array
    {
        return $this->dependencies;
    }

    public function setDependencies(?array $dependencies): static
    {
        $this->dependencies = $dependencies;
        return $this;
    }

    public function getDownloadUrl(): ?string
    {
        return $this->downloadUrl;
    }

    public function setDownloadUrl(?string $downloadUrl): static
    {
        $this->downloadUrl = $downloadUrl;
        return $this;
    }

    public function getDocumentationUrl(): ?string
    {
        return $this->documentationUrl;
    }

    public function setDocumentationUrl(?string $documentationUrl): static
    {
        $this->documentationUrl = $documentationUrl;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    /**
     * Check if patch deployment is overdue
     */
    public function isOverdue(): bool
    {
        if ($this->deploymentDeadline === null || $this->status === 'deployed') {
            return false;
        }

        return $this->deploymentDeadline < new \DateTimeImmutable();
    }

    /**
     * Get priority badge class
     */
    public function getPriorityBadgeClass(): string
    {
        return match($this->priority) {
            'critical' => 'danger',
            'high' => 'warning',
            'medium' => 'info',
            'low' => 'secondary',
            default => 'light',
        };
    }

    /**
     * Calculate recommended deployment deadline based on priority
     */
    public function calculateDeploymentDeadline(): \DateTimeImmutable
    {
        $days = match($this->priority) {
            'critical' => 3,
            'high' => 7,
            'medium' => 30,
            'low' => 90,
            default => 90,
        };

        return $this->releaseDate->modify("+{$days} days");
    }

    /**
     * Get days until deadline
     */
    public function getDaysUntilDeadline(): ?int
    {
        if ($this->deploymentDeadline === null) {
            return null;
        }

        $now = new \DateTimeImmutable();
        $diff = $now->diff($this->deploymentDeadline);

        return $diff->invert ? -$diff->days : $diff->days;
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
