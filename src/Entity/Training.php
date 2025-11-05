<?php

namespace App\Entity;

use App\Repository\TrainingRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TrainingRepository::class)]
class Training
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 100)]
    private ?string $trainingType = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTimeInterface $scheduledDate = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $durationMinutes = null;

    #[ORM\Column(length: 100)]
    private ?string $trainer = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $targetAudience = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $participants = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $attendeeCount = 0;

    #[ORM\Column(length: 50)]
    private ?string $status = 'planned';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $materials = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $feedback = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $completionDate = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    /**
     * @var Collection<int, Control>
     */
    #[ORM\ManyToMany(targetEntity: Control::class)]
    #[ORM\JoinTable(name: 'training_control')]
    private Collection $coveredControls;

    public function __construct()
    {
        $this->coveredControls = new ArrayCollection();
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

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getTrainingType(): ?string
    {
        return $this->trainingType;
    }

    public function setTrainingType(string $trainingType): static
    {
        $this->trainingType = $trainingType;
        return $this;
    }

    public function getScheduledDate(): ?\DateTimeInterface
    {
        return $this->scheduledDate;
    }

    public function setScheduledDate(\DateTimeInterface $scheduledDate): static
    {
        $this->scheduledDate = $scheduledDate;
        return $this;
    }

    public function getDurationMinutes(): ?int
    {
        return $this->durationMinutes;
    }

    public function setDurationMinutes(?int $durationMinutes): static
    {
        $this->durationMinutes = $durationMinutes;
        return $this;
    }

    public function getTrainer(): ?string
    {
        return $this->trainer;
    }

    public function setTrainer(string $trainer): static
    {
        $this->trainer = $trainer;
        return $this;
    }

    public function getTargetAudience(): ?string
    {
        return $this->targetAudience;
    }

    public function setTargetAudience(?string $targetAudience): static
    {
        $this->targetAudience = $targetAudience;
        return $this;
    }

    public function getParticipants(): ?string
    {
        return $this->participants;
    }

    public function setParticipants(?string $participants): static
    {
        $this->participants = $participants;
        return $this;
    }

    public function getAttendeeCount(): ?int
    {
        return $this->attendeeCount;
    }

    public function setAttendeeCount(?int $attendeeCount): static
    {
        $this->attendeeCount = $attendeeCount;
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

    public function getMaterials(): ?string
    {
        return $this->materials;
    }

    public function setMaterials(?string $materials): static
    {
        $this->materials = $materials;
        return $this;
    }

    public function getFeedback(): ?string
    {
        return $this->feedback;
    }

    public function setFeedback(?string $feedback): static
    {
        $this->feedback = $feedback;
        return $this;
    }

    public function getCompletionDate(): ?\DateTimeInterface
    {
        return $this->completionDate;
    }

    public function setCompletionDate(?\DateTimeInterface $completionDate): static
    {
        $this->completionDate = $completionDate;
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
    public function getCoveredControls(): Collection
    {
        return $this->coveredControls;
    }

    public function addCoveredControl(Control $control): static
    {
        if (!$this->coveredControls->contains($control)) {
            $this->coveredControls->add($control);
        }
        return $this;
    }

    public function removeCoveredControl(Control $control): static
    {
        $this->coveredControls->removeElement($control);
        return $this;
    }

    /**
     * Get count of ISO 27001 controls covered
     * Data Reuse: Shows training impact on compliance
     */
    public function getControlCoverageCount(): int
    {
        return $this->coveredControls->count();
    }

    /**
     * Calculate training effectiveness based on control implementation
     * Data Reuse: Training completion should correlate with control implementation
     */
    public function getTrainingEffectiveness(): ?float
    {
        if ($this->status !== 'completed' || $this->coveredControls->isEmpty()) {
            return null; // Cannot measure until training completed
        }

        $totalImplementation = 0;
        foreach ($this->coveredControls as $control) {
            $totalImplementation += $control->getImplementationPercentage() ?? 0;
        }

        return round($totalImplementation / $this->coveredControls->count(), 2);
    }

    /**
     * Get list of control categories covered
     * Data Reuse: Shows training scope
     */
    public function getCoveredCategories(): array
    {
        $categories = [];
        foreach ($this->coveredControls as $control) {
            $category = $control->getCategory();
            if (!in_array($category, $categories)) {
                $categories[] = $category;
            }
        }
        return $categories;
    }

    /**
     * Check if training addresses high-priority controls
     * Data Reuse: Links training to critical security areas
     */
    public function addressesCriticalControls(): bool
    {
        foreach ($this->coveredControls as $control) {
            if (!$control->isApplicable() || $control->getImplementationPercentage() < 50) {
                return true; // Training addresses controls that need attention
            }
        }
        return false;
    }
}
