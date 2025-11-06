<?php

namespace App\Entity;

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
use App\Repository\RiskRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: RiskRepository::class)]
#[ApiResource(
    operations: [
        new Get(security: "is_granted('ROLE_USER')"),
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Put(security: "is_granted('ROLE_USER')"),
        new Delete(security: "is_granted('ROLE_ADMIN')"),
    ],
    normalizationContext: ['groups' => ['risk:read']],
    denormalizationContext: ['groups' => ['risk:write']],
    paginationItemsPerPage: 30
)]
#[ApiFilter(SearchFilter::class, properties: ['title' => 'partial', 'status' => 'exact', 'riskOwner' => 'partial'])]
#[ApiFilter(OrderFilter::class, properties: ['title', 'createdAt', 'status'])]
#[ApiFilter(DateFilter::class, properties: ['createdAt', 'reviewDate'])]
class Risk
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['risk:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Groups(['risk:read', 'risk:write'])]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Groups(['risk:read', 'risk:write'])]
    private ?string $description = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['risk:read', 'risk:write'])]
    private ?string $threat = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['risk:read', 'risk:write'])]
    private ?string $vulnerability = null;

    #[ORM\ManyToOne(inversedBy: 'risks')]
    #[Groups(['risk:read', 'risk:write'])]
    private ?Asset $asset = null;

    #[ORM\Column(type: Types::INTEGER)]
    #[Groups(['risk:read', 'risk:write'])]
    private ?int $probability = null;

    #[ORM\Column(type: Types::INTEGER)]
    #[Groups(['risk:read', 'risk:write'])]
    private ?int $impact = null;

    #[ORM\Column(type: Types::INTEGER)]
    #[Groups(['risk:read', 'risk:write'])]
    private ?int $residualProbability = null;

    #[ORM\Column(type: Types::INTEGER)]
    #[Groups(['risk:read', 'risk:write'])]
    private ?int $residualImpact = null;

    #[ORM\Column(length: 50)]
    #[Groups(['risk:read', 'risk:write'])]
    private ?string $treatmentStrategy = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['risk:read', 'risk:write'])]
    private ?string $treatmentDescription = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['risk:read', 'risk:write'])]
    private ?string $riskOwner = null;

    #[ORM\Column(length: 50)]
    #[Groups(['risk:read', 'risk:write'])]
    private ?string $status = 'identified';

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    #[Groups(['risk:read', 'risk:write'])]
    private ?\DateTimeInterface $reviewDate = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Groups(['risk:read'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    #[Groups(['risk:read'])]
    private ?\DateTimeInterface $updatedAt = null;

    /**
     * @var Collection<int, Control>
     */
    #[ORM\ManyToMany(targetEntity: Control::class, mappedBy: 'risks')]
    #[Groups(['risk:read'])]
    private Collection $controls;

    /**
     * @var Collection<int, Incident>
     */
    #[ORM\ManyToMany(targetEntity: Incident::class, mappedBy: 'realizedRisks')]
    #[Groups(['risk:read'])]
    private Collection $incidents;

    public function __construct()
    {
        $this->controls = new ArrayCollection();
        $this->incidents = new ArrayCollection();
        $this->createdAt = new \DateTime();
    }

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

    public function getThreat(): ?string
    {
        return $this->threat;
    }

    public function setThreat(?string $threat): static
    {
        $this->threat = $threat;
        return $this;
    }

    public function getVulnerability(): ?string
    {
        return $this->vulnerability;
    }

    public function setVulnerability(?string $vulnerability): static
    {
        $this->vulnerability = $vulnerability;
        return $this;
    }

    public function getAsset(): ?Asset
    {
        return $this->asset;
    }

    public function setAsset(?Asset $asset): static
    {
        $this->asset = $asset;
        return $this;
    }

    public function getProbability(): ?int
    {
        return $this->probability;
    }

    public function setProbability(int $probability): static
    {
        $this->probability = $probability;
        return $this;
    }

    public function getImpact(): ?int
    {
        return $this->impact;
    }

    public function setImpact(int $impact): static
    {
        $this->impact = $impact;
        return $this;
    }

    public function getResidualProbability(): ?int
    {
        return $this->residualProbability;
    }

    public function setResidualProbability(int $residualProbability): static
    {
        $this->residualProbability = $residualProbability;
        return $this;
    }

    public function getResidualImpact(): ?int
    {
        return $this->residualImpact;
    }

    public function setResidualImpact(int $residualImpact): static
    {
        $this->residualImpact = $residualImpact;
        return $this;
    }

    public function getTreatmentStrategy(): ?string
    {
        return $this->treatmentStrategy;
    }

    public function setTreatmentStrategy(string $treatmentStrategy): static
    {
        $this->treatmentStrategy = $treatmentStrategy;
        return $this;
    }

    public function getTreatmentDescription(): ?string
    {
        return $this->treatmentDescription;
    }

    public function setTreatmentDescription(?string $treatmentDescription): static
    {
        $this->treatmentDescription = $treatmentDescription;
        return $this;
    }

    public function getRiskOwner(): ?string
    {
        return $this->riskOwner;
    }

    public function setRiskOwner(?string $riskOwner): static
    {
        $this->riskOwner = $riskOwner;
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

    public function getReviewDate(): ?\DateTimeInterface
    {
        return $this->reviewDate;
    }

    public function setReviewDate(?\DateTimeInterface $reviewDate): static
    {
        $this->reviewDate = $reviewDate;
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
    public function getControls(): Collection
    {
        return $this->controls;
    }

    public function addControl(Control $control): static
    {
        if (!$this->controls->contains($control)) {
            $this->controls->add($control);
            $control->addRisk($this);
        }
        return $this;
    }

    public function removeControl(Control $control): static
    {
        if ($this->controls->removeElement($control)) {
            $control->removeRisk($this);
        }
        return $this;
    }

    #[Groups(['risk:read'])]
    public function getInherentRiskLevel(): int
    {
        return $this->probability * $this->impact;
    }

    #[Groups(['risk:read'])]
    public function getResidualRiskLevel(): int
    {
        return $this->residualProbability * $this->residualImpact;
    }

    #[Groups(['risk:read'])]
    public function getRiskReduction(): int
    {
        return $this->getInherentRiskLevel() - $this->getResidualRiskLevel();
    }

    /**
     * @return Collection<int, Incident>
     */
    public function getIncidents(): Collection
    {
        return $this->incidents;
    }

    public function addIncident(Incident $incident): static
    {
        if (!$this->incidents->contains($incident)) {
            $this->incidents->add($incident);
            $incident->addRealizedRisk($this);
        }
        return $this;
    }

    public function removeIncident(Incident $incident): static
    {
        if ($this->incidents->removeElement($incident)) {
            $incident->removeRealizedRisk($this);
        }
        return $this;
    }

    /**
     * Check if this risk has been realized (incident occurred)
     * Data Reuse: Validates risk assessment with real-world incidents
     */
    #[Groups(['risk:read'])]
    public function hasBeenRealized(): bool
    {
        return !$this->incidents->isEmpty();
    }

    /**
     * Get realization count
     * Data Reuse: Frequency analysis for risk assessment calibration
     */
    #[Groups(['risk:read'])]
    public function getRealizationCount(): int
    {
        return $this->incidents->count();
    }

    /**
     * Check if risk assessment was accurate based on incidents
     * Data Reuse: Compare predicted impact vs actual incident severity
     */
    #[Groups(['risk:read'])]
    public function wasAssessmentAccurate(): ?bool
    {
        if ($this->incidents->isEmpty()) {
            return null; // Cannot validate without incidents
        }

        $predictedLevel = $this->getInherentRiskLevel();

        // Compare with average incident severity
        $criticalIncidents = 0;
        foreach ($this->incidents as $incident) {
            if (in_array($incident->getSeverity(), ['critical', 'high'])) {
                $criticalIncidents++;
            }
        }

        // High predicted risk should correlate with critical incidents
        if ($predictedLevel >= 16) { // High risk (4x4 or higher)
            return $criticalIncidents > 0;
        } elseif ($predictedLevel >= 9) { // Medium risk
            return $criticalIncidents === 0; // Should NOT have critical incidents
        }

        return true; // Low risk is accurate if few/no critical incidents
    }

    /**
     * Get most recent incident for this risk
     * Data Reuse: Track latest realization
     */
    #[Groups(['risk:read'])]
    public function getMostRecentIncident(): ?Incident
    {
        if ($this->incidents->isEmpty()) {
            return null;
        }

        $latest = null;
        foreach ($this->incidents as $incident) {
            if ($latest === null || $incident->getDetectedAt() > $latest->getDetectedAt()) {
                $latest = $incident;
            }
        }

        return $latest;
    }
}
