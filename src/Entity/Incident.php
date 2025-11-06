<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Delete;
use App\Repository\IncidentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: IncidentRepository::class)]
#[ApiResource(
    operations: [
        new Get(security: "is_granted('ROLE_USER')"),
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Put(security: "is_granted('ROLE_USER')"),
        new Delete(security: "is_granted('ROLE_ADMIN')"),
    ],
    normalizationContext: ['groups' => ['incident:read']],
    denormalizationContext: ['groups' => ['incident:write']],
    paginationItemsPerPage: 30
)]
#[ApiFilter(SearchFilter::class, properties: ['title' => 'partial', 'incidentNumber' => 'exact', 'severity' => 'exact', 'status' => 'exact', 'category' => 'exact'])]
#[ApiFilter(BooleanFilter::class, properties: ['dataBreachOccurred', 'notificationRequired'])]
#[ApiFilter(OrderFilter::class, properties: ['detectedAt', 'severity', 'status'])]
#[ApiFilter(DateFilter::class, properties: ['detectedAt', 'resolvedAt', 'closedAt'])]
class Incident
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['incident:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    #[Groups(['incident:read', 'incident:write'])]
    private ?string $incidentNumber = null;

    #[ORM\Column(length: 255)]
    #[Groups(['incident:read', 'incident:write'])]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Groups(['incident:read', 'incident:write'])]
    private ?string $description = null;

    #[ORM\Column(length: 100)]
    #[Groups(['incident:read', 'incident:write'])]
    private ?string $category = null;

    #[ORM\Column(length: 50)]
    #[Groups(['incident:read', 'incident:write'])]
    private ?string $severity = null;

    #[ORM\Column(length: 50)]
    #[Groups(['incident:read', 'incident:write'])]
    private ?string $status = 'open';

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Groups(['incident:read', 'incident:write'])]
    private ?\DateTimeInterface $detectedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    #[Groups(['incident:read', 'incident:write'])]
    private ?\DateTimeInterface $occurredAt = null;

    #[ORM\Column(length: 100)]
    #[Groups(['incident:read', 'incident:write'])]
    private ?string $reportedBy = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['incident:read', 'incident:write'])]
    private ?string $assignedTo = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['incident:read', 'incident:write'])]
    private ?string $immediateActions = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['incident:read', 'incident:write'])]
    private ?string $rootCause = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['incident:read', 'incident:write'])]
    private ?string $correctiveActions = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['incident:read', 'incident:write'])]
    private ?string $preventiveActions = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['incident:read', 'incident:write'])]
    private ?string $lessonsLearned = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    #[Groups(['incident:read', 'incident:write'])]
    private ?\DateTimeInterface $resolvedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    #[Groups(['incident:read', 'incident:write'])]
    private ?\DateTimeInterface $closedAt = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    #[Groups(['incident:read', 'incident:write'])]
    private ?bool $dataBreachOccurred = false;

    #[ORM\Column(type: Types::BOOLEAN)]
    #[Groups(['incident:read', 'incident:write'])]
    private ?bool $notificationRequired = false;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Groups(['incident:read'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    #[Groups(['incident:read'])]
    private ?\DateTimeInterface $updatedAt = null;

    /**
     * @var Collection<int, Control>
     */
    #[ORM\ManyToMany(targetEntity: Control::class, inversedBy: 'incidents')]
    #[Groups(['incident:read'])]
    private Collection $relatedControls;

    /**
     * @var Collection<int, Asset>
     */
    #[ORM\ManyToMany(targetEntity: Asset::class, inversedBy: 'incidents')]
    #[ORM\JoinTable(name: 'incident_asset')]
    #[Groups(['incident:read'])]
    private Collection $affectedAssets;

    /**
     * @var Collection<int, Risk>
     */
    #[ORM\ManyToMany(targetEntity: Risk::class, inversedBy: 'incidents')]
    #[ORM\JoinTable(name: 'incident_risk')]
    #[Groups(['incident:read'])]
    private Collection $realizedRisks;

    public function __construct()
    {
        $this->relatedControls = new ArrayCollection();
        $this->affectedAssets = new ArrayCollection();
        $this->realizedRisks = new ArrayCollection();
        $this->createdAt = new \DateTime();
        $this->detectedAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIncidentNumber(): ?string
    {
        return $this->incidentNumber;
    }

    public function setIncidentNumber(string $incidentNumber): static
    {
        $this->incidentNumber = $incidentNumber;
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

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(string $category): static
    {
        $this->category = $category;
        return $this;
    }

    public function getSeverity(): ?string
    {
        return $this->severity;
    }

    public function setSeverity(string $severity): static
    {
        $this->severity = $severity;
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

    public function getDetectedAt(): ?\DateTimeInterface
    {
        return $this->detectedAt;
    }

    public function setDetectedAt(\DateTimeInterface $detectedAt): static
    {
        $this->detectedAt = $detectedAt;
        return $this;
    }

    public function getOccurredAt(): ?\DateTimeInterface
    {
        return $this->occurredAt;
    }

    public function setOccurredAt(?\DateTimeInterface $occurredAt): static
    {
        $this->occurredAt = $occurredAt;
        return $this;
    }

    public function getReportedBy(): ?string
    {
        return $this->reportedBy;
    }

    public function setReportedBy(string $reportedBy): static
    {
        $this->reportedBy = $reportedBy;
        return $this;
    }

    public function getAssignedTo(): ?string
    {
        return $this->assignedTo;
    }

    public function setAssignedTo(?string $assignedTo): static
    {
        $this->assignedTo = $assignedTo;
        return $this;
    }

    public function getImmediateActions(): ?string
    {
        return $this->immediateActions;
    }

    public function setImmediateActions(?string $immediateActions): static
    {
        $this->immediateActions = $immediateActions;
        return $this;
    }

    public function getRootCause(): ?string
    {
        return $this->rootCause;
    }

    public function setRootCause(?string $rootCause): static
    {
        $this->rootCause = $rootCause;
        return $this;
    }

    public function getCorrectiveActions(): ?string
    {
        return $this->correctiveActions;
    }

    public function setCorrectiveActions(?string $correctiveActions): static
    {
        $this->correctiveActions = $correctiveActions;
        return $this;
    }

    public function getPreventiveActions(): ?string
    {
        return $this->preventiveActions;
    }

    public function setPreventiveActions(?string $preventiveActions): static
    {
        $this->preventiveActions = $preventiveActions;
        return $this;
    }

    public function getLessonsLearned(): ?string
    {
        return $this->lessonsLearned;
    }

    public function setLessonsLearned(?string $lessonsLearned): static
    {
        $this->lessonsLearned = $lessonsLearned;
        return $this;
    }

    public function getResolvedAt(): ?\DateTimeInterface
    {
        return $this->resolvedAt;
    }

    public function setResolvedAt(?\DateTimeInterface $resolvedAt): static
    {
        $this->resolvedAt = $resolvedAt;
        return $this;
    }

    public function getClosedAt(): ?\DateTimeInterface
    {
        return $this->closedAt;
    }

    public function setClosedAt(?\DateTimeInterface $closedAt): static
    {
        $this->closedAt = $closedAt;
        return $this;
    }

    public function isDataBreachOccurred(): ?bool
    {
        return $this->dataBreachOccurred;
    }

    public function setDataBreachOccurred(bool $dataBreachOccurred): static
    {
        $this->dataBreachOccurred = $dataBreachOccurred;
        return $this;
    }

    public function isNotificationRequired(): ?bool
    {
        return $this->notificationRequired;
    }

    public function setNotificationRequired(bool $notificationRequired): static
    {
        $this->notificationRequired = $notificationRequired;
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
     * @return Collection<int, Control>
     */
    public function getRelatedControls(): Collection
    {
        return $this->relatedControls;
    }

    public function addRelatedControl(Control $relatedControl): static
    {
        if (!$this->relatedControls->contains($relatedControl)) {
            $this->relatedControls->add($relatedControl);
        }
        return $this;
    }

    public function removeRelatedControl(Control $relatedControl): static
    {
        $this->relatedControls->removeElement($relatedControl);
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

    /**
     * @return Collection<int, Risk>
     */
    public function getRealizedRisks(): Collection
    {
        return $this->realizedRisks;
    }

    public function addRealizedRisk(Risk $risk): static
    {
        if (!$this->realizedRisks->contains($risk)) {
            $this->realizedRisks->add($risk);
        }
        return $this;
    }

    public function removeRealizedRisk(Risk $risk): static
    {
        $this->realizedRisks->removeElement($risk);
        return $this;
    }

    /**
     * Check if any critical/high-risk assets were affected
     * Data Reuse: Uses Asset risk scoring
     */
    #[Groups(['incident:read'])]
    public function hasCriticalAssetsAffected(): bool
    {
        return $this->affectedAssets->exists(fn($k, $asset) => $asset->isHighRisk());
    }

    /**
     * Get count of realized risks
     * Data Reuse: Links incidents to pre-defined risks
     */
    #[Groups(['incident:read'])]
    public function getRealizedRiskCount(): int
    {
        return $this->realizedRisks->count();
    }

    /**
     * Get total impact value from affected assets
     * Data Reuse: Aggregates CIA values from affected assets
     */
    #[Groups(['incident:read'])]
    public function getTotalAssetImpact(): int
    {
        $total = 0;
        foreach ($this->affectedAssets as $asset) {
            $total += $asset->getTotalValue();
        }
        return $total;
    }

    /**
     * Check if this incident validated a previously identified risk
     * Data Reuse: Validates risk assessment accuracy
     */
    #[Groups(['incident:read'])]
    public function isRiskValidated(): bool
    {
        return !$this->realizedRisks->isEmpty();
    }
}
