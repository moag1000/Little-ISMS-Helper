<?php

namespace App\Entity;

use App\Repository\ComplianceFrameworkRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ComplianceFrameworkRepository::class)]
class ComplianceFramework
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100, unique: true)]
    private ?string $code = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 50)]
    private ?string $version = null;

    #[ORM\Column(length: 100)]
    private ?string $applicableIndustry = null;

    #[ORM\Column(length: 100)]
    private ?string $regulatoryBody = null;

    #[ORM\Column]
    private ?bool $mandatory = false;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $scopeDescription = null;

    #[ORM\Column]
    private ?bool $active = true;

    /**
     * @var Collection<int, ComplianceRequirement>
     */
    #[ORM\OneToMany(targetEntity: ComplianceRequirement::class, mappedBy: 'framework', cascade: ['persist', 'remove'])]
    private Collection $requirements;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->requirements = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;
        return $this;
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

    public function getVersion(): ?string
    {
        return $this->version;
    }

    public function setVersion(string $version): static
    {
        $this->version = $version;
        return $this;
    }

    public function getApplicableIndustry(): ?string
    {
        return $this->applicableIndustry;
    }

    public function setApplicableIndustry(string $applicableIndustry): static
    {
        $this->applicableIndustry = $applicableIndustry;
        return $this;
    }

    public function getRegulatoryBody(): ?string
    {
        return $this->regulatoryBody;
    }

    public function setRegulatoryBody(string $regulatoryBody): static
    {
        $this->regulatoryBody = $regulatoryBody;
        return $this;
    }

    public function isMandatory(): ?bool
    {
        return $this->mandatory;
    }

    public function setMandatory(bool $mandatory): static
    {
        $this->mandatory = $mandatory;
        return $this;
    }

    public function getScopeDescription(): ?string
    {
        return $this->scopeDescription;
    }

    public function setScopeDescription(?string $scopeDescription): static
    {
        $this->scopeDescription = $scopeDescription;
        return $this;
    }

    public function isActive(): ?bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;
        return $this;
    }

    /**
     * @return Collection<int, ComplianceRequirement>
     */
    public function getRequirements(): Collection
    {
        return $this->requirements;
    }

    public function addRequirement(ComplianceRequirement $requirement): static
    {
        if (!$this->requirements->contains($requirement)) {
            $this->requirements->add($requirement);
            $requirement->setFramework($this);
        }

        return $this;
    }

    public function removeRequirement(ComplianceRequirement $requirement): static
    {
        if ($this->requirements->removeElement($requirement)) {
            if ($requirement->getFramework() === $this) {
                $requirement->setFramework(null);
            }
        }

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
     * Get count of applicable requirements
     */
    public function getApplicableRequirementsCount(): int
    {
        return $this->requirements->filter(fn($req) => $req->isApplicable())->count();
    }

    /**
     * Get count of fulfilled requirements
     */
    public function getFulfilledRequirementsCount(): int
    {
        return $this->requirements->filter(fn($req) => $req->isApplicable() && $req->getFulfillmentPercentage() >= 100)->count();
    }

    /**
     * Get overall compliance percentage
     */
    public function getCompliancePercentage(): float
    {
        $applicable = $this->getApplicableRequirementsCount();
        if ($applicable === 0) {
            return 0.0;
        }

        $totalFulfillment = 0;
        foreach ($this->requirements as $requirement) {
            if ($requirement->isApplicable()) {
                $totalFulfillment += $requirement->getFulfillmentPercentage();
            }
        }

        return round($totalFulfillment / $applicable, 2);
    }
}
