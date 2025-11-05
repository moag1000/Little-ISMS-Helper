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

    /**
     * @var Collection<int, Risk>
     */
    #[ORM\ManyToMany(targetEntity: Risk::class)]
    #[ORM\JoinTable(name: 'business_process_risk')]
    private Collection $identifiedRisks;

    public function __construct()
    {
        $this->supportingAssets = new ArrayCollection();
        $this->identifiedRisks = new ArrayCollection();
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
     * Calculate aggregated risk level for this process
     * Data Reuse: Combines BIA criticality with actual identified risks
     */
    public function getProcessRiskLevel(): string
    {
        if ($this->identifiedRisks->isEmpty()) {
            return 'unknown';
        }

        $totalInherentRisk = 0;
        $totalResidualRisk = 0;
        $count = 0;

        foreach ($this->identifiedRisks as $risk) {
            if ($risk->getStatus() === 'active') {
                $totalInherentRisk += $risk->getInherentRiskLevel();
                $totalResidualRisk += $risk->getResidualRiskLevel();
                $count++;
            }
        }

        if ($count === 0) {
            return 'low';
        }

        $avgResidualRisk = $totalResidualRisk / $count;

        if ($avgResidualRisk >= 16) {
            return 'critical';
        } elseif ($avgResidualRisk >= 9) {
            return 'high';
        } elseif ($avgResidualRisk >= 4) {
            return 'medium';
        } else {
            return 'low';
        }
    }

    /**
     * Validate if BIA criticality matches actual risk assessment
     * Data Reuse: Cross-validate BIA with risk data
     */
    public function isCriticalityAligned(): bool
    {
        if ($this->identifiedRisks->isEmpty()) {
            return true; // Cannot validate without risk data
        }

        $processRiskLevel = $this->getProcessRiskLevel();

        // Check alignment between BIA criticality and risk assessment
        if ($this->criticality === 'critical' && !in_array($processRiskLevel, ['critical', 'high'])) {
            return false; // Critical process should have high risks
        }

        if ($this->criticality === 'low' && in_array($processRiskLevel, ['critical', 'high'])) {
            return false; // Low criticality process shouldn't have high risks
        }

        return true;
    }

    /**
     * Get suggested RTO based on risk assessment
     * Data Reuse: Risk data can suggest better RTO values
     */
    public function getSuggestedRTO(): int
    {
        $riskLevel = $this->getProcessRiskLevel();

        switch ($riskLevel) {
            case 'critical':
                return 1; // 1 hour
            case 'high':
                return 4; // 4 hours
            case 'medium':
                return 24; // 1 day
            case 'low':
                return 72; // 3 days
            default:
                return $this->rto; // Keep current if unknown
        }
    }

    /**
     * Get count of active risks affecting this process
     * Data Reuse: Quick risk overview
     */
    public function getActiveRiskCount(): int
    {
        return $this->identifiedRisks->filter(fn($r) => $r->getStatus() === 'active')->count();
    }

    /**
     * Check if process has unmitigated high risks
     * Data Reuse: Automatic alert for critical situations
     */
    public function hasUnmitigatedHighRisks(): bool
    {
        foreach ($this->identifiedRisks as $risk) {
            if ($risk->getStatus() === 'active' && $risk->getResidualRiskLevel() >= 16) {
                return true;
            }
        }
        return false;
    }
}
