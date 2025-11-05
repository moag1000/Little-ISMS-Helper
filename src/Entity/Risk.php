<?php

namespace App\Entity;

use App\Repository\RiskRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RiskRepository::class)]
class Risk
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $description = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $threat = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $vulnerability = null;

    #[ORM\ManyToOne(inversedBy: 'risks')]
    private ?Asset $asset = null;

    #[ORM\Column(type: Types::INTEGER)]
    private ?int $probability = null;

    #[ORM\Column(type: Types::INTEGER)]
    private ?int $impact = null;

    #[ORM\Column(type: Types::INTEGER)]
    private ?int $residualProbability = null;

    #[ORM\Column(type: Types::INTEGER)]
    private ?int $residualImpact = null;

    #[ORM\Column(length: 50)]
    private ?string $treatmentStrategy = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $treatmentDescription = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $riskOwner = null;

    #[ORM\Column(length: 50)]
    private ?string $status = 'identified';

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $reviewDate = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    /**
     * @var Collection<int, Control>
     */
    #[ORM\ManyToMany(targetEntity: Control::class, mappedBy: 'risks')]
    private Collection $controls;

    public function __construct()
    {
        $this->controls = new ArrayCollection();
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

    public function getInherentRiskLevel(): int
    {
        return $this->probability * $this->impact;
    }

    public function getResidualRiskLevel(): int
    {
        return $this->residualProbability * $this->residualImpact;
    }

    public function getRiskReduction(): int
    {
        return $this->getInherentRiskLevel() - $this->getResidualRiskLevel();
    }
}
