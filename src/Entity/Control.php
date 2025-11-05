<?php

namespace App\Entity;

use App\Repository\ControlRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ControlRepository::class)]
class Control
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private ?string $controlId = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $description = null;

    #[ORM\Column(length: 100)]
    private ?string $category = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private ?bool $applicable = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $justification = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $implementationNotes = null;

    #[ORM\Column(length: 50)]
    private ?string $implementationStatus = 'not_started';

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $implementationPercentage = 0;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $responsiblePerson = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $targetDate = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $lastReviewDate = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $nextReviewDate = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    /**
     * @var Collection<int, Risk>
     */
    #[ORM\ManyToMany(targetEntity: Risk::class, inversedBy: 'controls')]
    private Collection $risks;

    /**
     * @var Collection<int, Incident>
     */
    #[ORM\ManyToMany(targetEntity: Incident::class, mappedBy: 'relatedControls')]
    private Collection $incidents;

    public function __construct()
    {
        $this->risks = new ArrayCollection();
        $this->incidents = new ArrayCollection();
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getControlId(): ?string
    {
        return $this->controlId;
    }

    public function setControlId(string $controlId): static
    {
        $this->controlId = $controlId;
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

    public function isApplicable(): ?bool
    {
        return $this->applicable;
    }

    public function setApplicable(bool $applicable): static
    {
        $this->applicable = $applicable;
        return $this;
    }

    public function getJustification(): ?string
    {
        return $this->justification;
    }

    public function setJustification(?string $justification): static
    {
        $this->justification = $justification;
        return $this;
    }

    public function getImplementationNotes(): ?string
    {
        return $this->implementationNotes;
    }

    public function setImplementationNotes(?string $implementationNotes): static
    {
        $this->implementationNotes = $implementationNotes;
        return $this;
    }

    public function getImplementationStatus(): ?string
    {
        return $this->implementationStatus;
    }

    public function setImplementationStatus(string $implementationStatus): static
    {
        $this->implementationStatus = $implementationStatus;
        return $this;
    }

    public function getImplementationPercentage(): ?int
    {
        return $this->implementationPercentage;
    }

    public function setImplementationPercentage(?int $implementationPercentage): static
    {
        $this->implementationPercentage = $implementationPercentage;
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

    public function getTargetDate(): ?\DateTimeInterface
    {
        return $this->targetDate;
    }

    public function setTargetDate(?\DateTimeInterface $targetDate): static
    {
        $this->targetDate = $targetDate;
        return $this;
    }

    public function getLastReviewDate(): ?\DateTimeInterface
    {
        return $this->lastReviewDate;
    }

    public function setLastReviewDate(?\DateTimeInterface $lastReviewDate): static
    {
        $this->lastReviewDate = $lastReviewDate;
        return $this;
    }

    public function getNextReviewDate(): ?\DateTimeInterface
    {
        return $this->nextReviewDate;
    }

    public function setNextReviewDate(?\DateTimeInterface $nextReviewDate): static
    {
        $this->nextReviewDate = $nextReviewDate;
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
        }
        return $this;
    }

    public function removeRisk(Risk $risk): static
    {
        $this->risks->removeElement($risk);
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
            $incident->addRelatedControl($this);
        }
        return $this;
    }

    public function removeIncident(Incident $incident): static
    {
        if ($this->incidents->removeElement($incident)) {
            $incident->removeRelatedControl($this);
        }
        return $this;
    }
}
