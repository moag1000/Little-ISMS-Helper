<?php

namespace App\Entity;

use App\Repository\BusinessProcessRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BusinessProcessRepository::class)]
class BusinessProcess
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 100)]
    private ?string $processOwner = null;

    #[ORM\Column(length: 50)]
    private ?string $criticality = null; // critical, high, medium, low

    // Business Impact Analysis (BIA) Daten
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $rto = null; // Recovery Time Objective in Stunden

    #[ORM\Column(type: Types::INTEGER)]
    private ?int $rpo = null; // Recovery Point Objective in Stunden

    #[ORM\Column(type: Types::INTEGER)]
    private ?int $mtpd = null; // Maximum Tolerable Period of Disruption in Stunden

    // Finanzielle Auswirkungen
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $financialImpactPerHour = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $financialImpactPerDay = null;

    // Reputationsschaden
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $reputationalImpact = null; // 1-5 Skala

    // Rechtliche/Regulatorische Auswirkungen
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $regulatoryImpact = null; // 1-5 Skala

    // Operationale Auswirkungen
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $operationalImpact = null; // 1-5 Skala

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $dependenciesUpstream = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $dependenciesDownstream = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $recoveryStrategy = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    /**
     * @var Collection<int, Asset>
     */
    #[ORM\ManyToMany(targetEntity: Asset::class)]
    #[ORM\JoinTable(name: 'business_process_asset')]
    private Collection $supportingAssets;

    public function __construct()
    {
        $this->supportingAssets = new ArrayCollection();
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

    public function getProcessOwner(): ?string
    {
        return $this->processOwner;
    }

    public function setProcessOwner(string $processOwner): static
    {
        $this->processOwner = $processOwner;
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

    public function getRto(): ?int
    {
        return $this->rto;
    }

    public function setRto(int $rto): static
    {
        $this->rto = $rto;
        return $this;
    }

    public function getRpo(): ?int
    {
        return $this->rpo;
    }

    public function setRpo(int $rpo): static
    {
        $this->rpo = $rpo;
        return $this;
    }

    public function getMtpd(): ?int
    {
        return $this->mtpd;
    }

    public function setMtpd(int $mtpd): static
    {
        $this->mtpd = $mtpd;
        return $this;
    }

    public function getFinancialImpactPerHour(): ?string
    {
        return $this->financialImpactPerHour;
    }

    public function setFinancialImpactPerHour(?string $financialImpactPerHour): static
    {
        $this->financialImpactPerHour = $financialImpactPerHour;
        return $this;
    }

    public function getFinancialImpactPerDay(): ?string
    {
        return $this->financialImpactPerDay;
    }

    public function setFinancialImpactPerDay(?string $financialImpactPerDay): static
    {
        $this->financialImpactPerDay = $financialImpactPerDay;
        return $this;
    }

    public function getReputationalImpact(): ?int
    {
        return $this->reputationalImpact;
    }

    public function setReputationalImpact(int $reputationalImpact): static
    {
        $this->reputationalImpact = $reputationalImpact;
        return $this;
    }

    public function getRegulatoryImpact(): ?int
    {
        return $this->regulatoryImpact;
    }

    public function setRegulatoryImpact(int $regulatoryImpact): static
    {
        $this->regulatoryImpact = $regulatoryImpact;
        return $this;
    }

    public function getOperationalImpact(): ?int
    {
        return $this->operationalImpact;
    }

    public function setOperationalImpact(int $operationalImpact): static
    {
        $this->operationalImpact = $operationalImpact;
        return $this;
    }

    public function getDependenciesUpstream(): ?string
    {
        return $this->dependenciesUpstream;
    }

    public function setDependenciesUpstream(?string $dependenciesUpstream): static
    {
        $this->dependenciesUpstream = $dependenciesUpstream;
        return $this;
    }

    public function getDependenciesDownstream(): ?string
    {
        return $this->dependenciesDownstream;
    }

    public function setDependenciesDownstream(?string $dependenciesDownstream): static
    {
        $this->dependenciesDownstream = $dependenciesDownstream;
        return $this;
    }

    public function getRecoveryStrategy(): ?string
    {
        return $this->recoveryStrategy;
    }

    public function setRecoveryStrategy(?string $recoveryStrategy): static
    {
        $this->recoveryStrategy = $recoveryStrategy;
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
     * @return Collection<int, Asset>
     */
    public function getSupportingAssets(): Collection
    {
        return $this->supportingAssets;
    }

    public function addSupportingAsset(Asset $asset): static
    {
        if (!$this->supportingAssets->contains($asset)) {
            $this->supportingAssets->add($asset);
        }
        return $this;
    }

    public function removeSupportingAsset(Asset $asset): static
    {
        $this->supportingAssets->removeElement($asset);
        return $this;
    }

    /**
     * Berechnet den aggregierten Business Impact Score (1-5)
     */
    public function getBusinessImpactScore(): int
    {
        return (int) round(($this->reputationalImpact + $this->regulatoryImpact + $this->operationalImpact) / 3);
    }

    /**
     * Schlägt Availability-Wert basierend auf RTO/MTPD vor
     * Für die automatische Asset-Bewertung
     */
    public function getSuggestedAvailabilityValue(): int
    {
        if ($this->rto <= 1) {
            return 5; // Sehr hoch
        } elseif ($this->rto <= 4) {
            return 4; // Hoch
        } elseif ($this->rto <= 24) {
            return 3; // Mittel
        } elseif ($this->rto <= 72) {
            return 2; // Niedrig
        } else {
            return 1; // Sehr niedrig
        }
    }
}
