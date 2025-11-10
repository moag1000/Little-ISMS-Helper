<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Delete;
use App\Repository\ThreatIntelligenceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ThreatIntelligenceRepository::class)]
#[ORM\Index(columns: ['threat_type'], name: 'idx_threat_type')]
#[ORM\Index(columns: ['severity'], name: 'idx_threat_severity')]
#[ORM\Index(columns: ['status'], name: 'idx_threat_status')]
#[ORM\Index(columns: ['detection_date'], name: 'idx_threat_detection_date')]
#[ApiResource(
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('ROLE_USER')"),
        new Post(security: "is_granted('ROLE_ADMIN')"),
        new Put(security: "is_granted('ROLE_ADMIN')"),
        new Delete(security: "is_granted('ROLE_ADMIN')"),
    ],
    normalizationContext: ['groups' => ['threat:read']],
    denormalizationContext: ['groups' => ['threat:write']]
)]
class ThreatIntelligence
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['threat:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Groups(['threat:read', 'threat:write'])]
    #[Assert\NotBlank]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Groups(['threat:read', 'threat:write'])]
    #[Assert\NotBlank]
    private ?string $description = null;

    #[ORM\Column(length: 100)]
    #[Groups(['threat:read', 'threat:write'])]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['malware', 'phishing', 'ransomware', 'ddos', 'zero_day', 'apt', 'insider_threat', 'social_engineering', 'data_breach', 'vulnerability', 'other'])]
    private ?string $threatType = null;

    #[ORM\Column(length: 50)]
    #[Groups(['threat:read', 'threat:write'])]
    #[Assert\Choice(choices: ['critical', 'high', 'medium', 'low', 'informational'])]
    private ?string $severity = 'medium';

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['threat:read', 'threat:write'])]
    private ?string $source = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['threat:read', 'threat:write'])]
    private ?string $cveId = null;

    // Legacy field - kept for backward compatibility
    // @deprecated Use $affectedAssets instead
    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups(['threat:read', 'threat:write'])]
    private ?array $affectedSystems = null;

    // New relationship for data reuse
    #[ORM\ManyToMany(targetEntity: Asset::class)]
    #[ORM\JoinTable(name: 'threat_intelligence_affected_assets')]
    #[Groups(['threat:read', 'threat:write'])]
    private Collection $affectedAssets;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups(['threat:read', 'threat:write'])]
    private ?array $indicators = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['threat:read', 'threat:write'])]
    private ?string $mitigationRecommendations = null;

    #[ORM\Column(length: 50)]
    #[Groups(['threat:read', 'threat:write'])]
    #[Assert\Choice(choices: ['new', 'analyzing', 'mitigated', 'monitoring', 'closed'])]
    private ?string $status = 'new';

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    #[Groups(['threat:read', 'threat:write'])]
    private ?\DateTimeInterface $detectionDate = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    #[Groups(['threat:read', 'threat:write'])]
    private ?\DateTimeInterface $mitigationDate = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['threat:read', 'threat:write'])]
    private ?string $actionsTaken = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['threat:read'])]
    private ?User $assignedTo = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    #[Groups(['threat:read', 'threat:write'])]
    private ?bool $affectsOrganization = false;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[Groups(['threat:read', 'threat:write'])]
    private ?int $cvssScore = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['threat:read', 'threat:write'])]
    private ?string $references = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Groups(['threat:read'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    #[Groups(['threat:read'])]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Tenant $tenant = null;

    /**
     * @var Collection<int, Incident>
     */
    #[ORM\OneToMany(targetEntity: Incident::class, mappedBy: 'originatingThreat')]
    #[Groups(['threat:read'])]
    private Collection $resultingIncidents;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->detectionDate = new \DateTime();
        $this->affectedAssets = new ArrayCollection();
        $this->resultingIncidents = new ArrayCollection();
    }

    // Getters and Setters

    public function getId(): ?int
    {
        return $this->id;
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

    public function getThreatType(): ?string
    {
        return $this->threatType;
    }

    public function setThreatType(string $threatType): static
    {
        $this->threatType = $threatType;
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

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function setSource(?string $source): static
    {
        $this->source = $source;
        return $this;
    }

    public function getCveId(): ?string
    {
        return $this->cveId;
    }

    public function setCveId(?string $cveId): static
    {
        $this->cveId = $cveId;
        return $this;
    }

    public function getAffectedSystems(): ?array
    {
        return $this->affectedSystems;
    }

    public function setAffectedSystems(?array $affectedSystems): static
    {
        $this->affectedSystems = $affectedSystems;
        return $this;
    }

    public function getIndicators(): ?array
    {
        return $this->indicators;
    }

    public function setIndicators(?array $indicators): static
    {
        $this->indicators = $indicators;
        return $this;
    }

    public function getMitigationRecommendations(): ?string
    {
        return $this->mitigationRecommendations;
    }

    public function setMitigationRecommendations(?string $mitigationRecommendations): static
    {
        $this->mitigationRecommendations = $mitigationRecommendations;
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

    public function getDetectionDate(): ?\DateTimeInterface
    {
        return $this->detectionDate;
    }

    public function setDetectionDate(\DateTimeInterface $detectionDate): static
    {
        $this->detectionDate = $detectionDate;
        return $this;
    }

    public function getMitigationDate(): ?\DateTimeInterface
    {
        return $this->mitigationDate;
    }

    public function setMitigationDate(?\DateTimeInterface $mitigationDate): static
    {
        $this->mitigationDate = $mitigationDate;
        return $this;
    }

    public function getActionsTaken(): ?string
    {
        return $this->actionsTaken;
    }

    public function setActionsTaken(?string $actionsTaken): static
    {
        $this->actionsTaken = $actionsTaken;
        return $this;
    }

    public function getAssignedTo(): ?User
    {
        return $this->assignedTo;
    }

    public function setAssignedTo(?User $assignedTo): static
    {
        $this->assignedTo = $assignedTo;
        return $this;
    }

    public function isAffectsOrganization(): ?bool
    {
        return $this->affectsOrganization;
    }

    public function setAffectsOrganization(bool $affectsOrganization): static
    {
        $this->affectsOrganization = $affectsOrganization;
        return $this;
    }

    public function getCvssScore(): ?int
    {
        return $this->cvssScore;
    }

    public function setCvssScore(?int $cvssScore): static
    {
        $this->cvssScore = $cvssScore;
        return $this;
    }

    public function getReferences(): ?string
    {
        return $this->references;
    }

    public function setReferences(?string $references): static
    {
        $this->references = $references;
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

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function setTenant(?Tenant $tenant): static
    {
        $this->tenant = $tenant;
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
     * @return Collection<int, Incident>
     */
    public function getResultingIncidents(): Collection
    {
        return $this->resultingIncidents;
    }

    public function addResultingIncident(Incident $incident): static
    {
        if (!$this->resultingIncidents->contains($incident)) {
            $this->resultingIncidents->add($incident);
            $incident->setOriginatingThreat($this);
        }

        return $this;
    }

    public function removeResultingIncident(Incident $incident): static
    {
        if ($this->resultingIncidents->removeElement($incident)) {
            if ($incident->getOriginatingThreat() === $this) {
                $incident->setOriginatingThreat(null);
            }
        }

        return $this;
    }
}
