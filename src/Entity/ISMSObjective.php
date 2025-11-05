<?php

namespace App\Entity;

use App\Repository\ISMSObjectiveRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ISMSObjectiveRepository::class)]
class ISMSObjective
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $description = null;

    #[ORM\Column(length: 100)]
    private ?string $category = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $measurableIndicators = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $targetValue = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $currentValue = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $unit = null;

    #[ORM\Column(length: 100)]
    private ?string $responsiblePerson = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTimeInterface $targetDate = null;

    #[ORM\Column(length: 50)]
    private ?string $status = 'in_progress';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $progressNotes = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $achievedDate = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
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

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(string $category): static
    {
        $this->category = $category;
        return $this;
    }

    public function getMeasurableIndicators(): ?string
    {
        return $this->measurableIndicators;
    }

    public function setMeasurableIndicators(?string $measurableIndicators): static
    {
        $this->measurableIndicators = $measurableIndicators;
        return $this;
    }

    public function getTargetValue(): ?string
    {
        return $this->targetValue;
    }

    public function setTargetValue(?string $targetValue): static
    {
        $this->targetValue = $targetValue;
        return $this;
    }

    public function getCurrentValue(): ?string
    {
        return $this->currentValue;
    }

    public function setCurrentValue(?string $currentValue): static
    {
        $this->currentValue = $currentValue;
        return $this;
    }

    public function getUnit(): ?string
    {
        return $this->unit;
    }

    public function setUnit(?string $unit): static
    {
        $this->unit = $unit;
        return $this;
    }

    public function getResponsiblePerson(): ?string
    {
        return $this->responsiblePerson;
    }

    public function setResponsiblePerson(string $responsiblePerson): static
    {
        $this->responsiblePerson = $responsiblePerson;
        return $this;
    }

    public function getTargetDate(): ?\DateTimeInterface
    {
        return $this->targetDate;
    }

    public function setTargetDate(\DateTimeInterface $targetDate): static
    {
        $this->targetDate = $targetDate;
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

    public function getProgressNotes(): ?string
    {
        return $this->progressNotes;
    }

    public function setProgressNotes(?string $progressNotes): static
    {
        $this->progressNotes = $progressNotes;
        return $this;
    }

    public function getAchievedDate(): ?\DateTimeInterface
    {
        return $this->achievedDate;
    }

    public function setAchievedDate(?\DateTimeInterface $achievedDate): static
    {
        $this->achievedDate = $achievedDate;
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

    public function getProgressPercentage(): int
    {
        if ($this->targetValue && $this->currentValue && (float)$this->targetValue > 0) {
            return (int)(((float)$this->currentValue / (float)$this->targetValue) * 100);
        }
        return 0;
    }
}
