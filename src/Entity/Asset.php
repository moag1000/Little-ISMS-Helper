<?php

namespace App\Entity;

use App\Repository\AssetRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AssetRepository::class)]
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

    public function __construct()
    {
        $this->risks = new ArrayCollection();
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

    public function getTotalValue(): int
    {
        return max($this->confidentialityValue, $this->integrityValue, $this->availabilityValue);
    }
}
