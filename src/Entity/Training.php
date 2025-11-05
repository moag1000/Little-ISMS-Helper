<?php

namespace App\Entity;

use App\Repository\TrainingRepository;
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
}
