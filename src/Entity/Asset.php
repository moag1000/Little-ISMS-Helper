<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Delete;
use App\Repository\AssetRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: AssetRepository::class)]
#[ApiResource(
    operations: [
        new Get(security: "is_granted('ROLE_USER')"),
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Put(security: "is_granted('ROLE_USER')"),
        new Delete(security: "is_granted('ROLE_ADMIN')"),
    ],
    normalizationContext: ['groups' => ['asset:read']],
    denormalizationContext: ['groups' => ['asset:write']]
)]
class Asset
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
    private ?string $assetType = null;

    #[ORM\Column(length: 100)]
    private ?string $owner = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $location = null;

    #[ORM\Column(type: Types::INTEGER)]
    private ?int $confidentialityValue = null;

    #[ORM\Column(type: Types::INTEGER)]
    private ?int $integrityValue = null;

    #[ORM\Column(type: Types::INTEGER)]
    private ?int $availabilityValue = null;

    #[ORM\Column(length: 50)]
    private ?string $status = 'active';

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    /**
     * @var Collection<int, Risk>
     */
    #[ORM\OneToMany(targetEntity: Risk::class, mappedBy: 'asset')]
    private Collection $risks;

    /**
     * @var Collection<int, Incident>
     */
    #[ORM\ManyToMany(targetEntity: Incident::class, mappedBy: 'affectedAssets')]
    private Collection $incidents;

    /**
     * @var Collection<int, Control>
     */
    #[ORM\ManyToMany(targetEntity: Control::class, mappedBy: 'protectedAssets')]
    private Collection $protectingControls;

    public function __construct()
    {
        $this->risks = new ArrayCollection();
        $this->incidents = new ArrayCollection();
        $this->protectingControls = new ArrayCollection();
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

    public function getAssetType(): ?string
    {
        return $this->assetType;
    }

    public function setAssetType(string $assetType): static
    {
        $this->assetType = $assetType;
        return $this;
    }

    public function getOwner(): ?string
    {
        return $this->owner;
    }

    public function setOwner(string $owner): static
    {
        $this->owner = $owner;
        return $this;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function setLocation(?string $location): static
    {
        $this->location = $location;
        return $this;
    }

    public function getConfidentialityValue(): ?int
    {
        return $this->confidentialityValue;
    }

    public function setConfidentialityValue(int $confidentialityValue): static
    {
        $this->confidentialityValue = $confidentialityValue;
        return $this;
    }

    public function getIntegrityValue(): ?int
    {
        return $this->integrityValue;
    }

    public function setIntegrityValue(int $integrityValue): static
    {
        $this->integrityValue = $integrityValue;
        return $this;
    }

    public function getAvailabilityValue(): ?int
    {
        return $this->availabilityValue;
    }

    public function setAvailabilityValue(int $availabilityValue): static
    {
        $this->availabilityValue = $availabilityValue;
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
     * @return Collection<int, Risk>
     */
    public function getRisks(): Collection
    {
        return $this->risks;
    }

    public function addRisk(Risk $risk): static
    {
        if (!$this->risks->contains($risk)) {
            $this->risks->add($risk);
            $risk->setAsset($this);
        }
        return $this;
    }

    public function removeRisk(Risk $risk): static
    {
        if ($this->risks->removeElement($risk)) {
            if ($risk->getAsset() === $this) {
                $risk->setAsset(null);
            }
        }
        return $this;
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
            $incident->addAffectedAsset($this);
        }
        return $this;
    }

    public function removeIncident(Incident $incident): static
    {
        if ($this->incidents->removeElement($incident)) {
            $incident->removeAffectedAsset($this);
        }
        return $this;
    }

    /**
     * @return Collection<int, Control>
     */
    public function getProtectingControls(): Collection
    {
        return $this->protectingControls;
    }

    public function addProtectingControl(Control $control): static
    {
        if (!$this->protectingControls->contains($control)) {
            $this->protectingControls->add($control);
            $control->addProtectedAsset($this);
        }
        return $this;
    }

    public function removeProtectingControl(Control $control): static
    {
        if ($this->protectingControls->removeElement($control)) {
            $control->removeProtectedAsset($this);
        }
        return $this;
    }

    public function getTotalValue(): int
    {
        return max($this->confidentialityValue, $this->integrityValue, $this->availabilityValue);
    }

    /**
     * Get Asset Risk Score based on multiple factors
     * Data Reuse: Combines Risks, Incidents, Control Coverage
     */
    public function getRiskScore(): float
    {
        $score = 0;

        // Base score from CIA values
        $score += $this->getTotalValue() * 10;

        // Risks impact
        $activeRisks = $this->risks->filter(fn($r) => $r->getStatus() === 'active')->count();
        $score += $activeRisks * 5;

        // Incidents impact (recent incidents = higher risk)
        $recentIncidents = $this->incidents->filter(function($i) {
            $sixMonthsAgo = new \DateTime('-6 months');
            return $i->getDetectedAt() >= $sixMonthsAgo;
        })->count();
        $score += $recentIncidents * 10;

        // Control coverage (more controls = lower risk)
        $controlCount = $this->protectingControls->count();
        $score -= min($controlCount * 3, 30); // Max 30 points reduction

        return max(0, min(100, $score));
    }

    /**
     * Check if asset is high-risk (many incidents, high value)
     * Data Reuse: Automated risk classification
     */
    public function isHighRisk(): bool
    {
        return $this->getRiskScore() >= 70;
    }

    /**
     * Get protection status
     * Data Reuse: Shows if asset is adequately protected
     */
    public function getProtectionStatus(): string
    {
        $controlCount = $this->protectingControls->count();
        $riskCount = $this->risks->filter(fn($r) => $r->getStatus() === 'active')->count();

        if ($controlCount === 0 && $riskCount > 0) {
            return 'unprotected';
        } elseif ($controlCount < $riskCount) {
            return 'under_protected';
        } elseif ($controlCount >= $riskCount) {
            return 'adequately_protected';
        }

        return 'unknown';
    }
}
