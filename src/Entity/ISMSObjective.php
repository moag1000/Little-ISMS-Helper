<?php

namespace App\Entity;

use App\Entity\Tenant;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Repository\ISMSObjectiveRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ISMSObjectiveRepository::class)]
#[ApiResource(
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('ROLE_USER')"),
        new Post(security: "is_granted('ROLE_ADMIN')"),
        new Put(security: "is_granted('ROLE_ADMIN')"),
        new Patch(security: "is_granted('ROLE_ADMIN')"),
        new Delete(security: "is_granted('ROLE_ADMIN')"),
    ],
    normalizationContext: ['groups' => ['isms_objective:read']],
    denormalizationContext: ['groups' => ['isms_objective:write']]
)]
class ISMSObjective
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['isms_objective:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Groups(['isms_objective:read', 'isms_objective:write'])]
    #[Assert\NotBlank]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Groups(['isms_objective:read', 'isms_objective:write'])]
    #[Assert\NotBlank]
    private ?string $description = null;

    #[ORM\Column(length: 100)]
    #[Groups(['isms_objective:read', 'isms_objective:write'])]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['availability', 'confidentiality', 'integrity', 'compliance', 'risk_management', 'incident_response', 'awareness', 'continual_improvement'])]
    private ?string $category = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['isms_objective:read', 'isms_objective:write'])]
    private ?string $measurableIndicators = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    #[Groups(['isms_objective:read', 'isms_objective:write'])]
    private ?string $targetValue = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    #[Groups(['isms_objective:read', 'isms_objective:write'])]
    private ?string $currentValue = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['isms_objective:read', 'isms_objective:write'])]
    private ?string $unit = null;

    #[ORM\Column(length: 100)]
    #[Groups(['isms_objective:read', 'isms_objective:write'])]
    #[Assert\NotBlank]
    private ?string $responsiblePerson = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    #[Groups(['isms_objective:read', 'isms_objective:write'])]
    #[Assert\NotBlank]
    private ?\DateTimeInterface $targetDate = null;

    #[ORM\Column(length: 50)]
    #[Groups(['isms_objective:read', 'isms_objective:write'])]
    #[Assert\Choice(choices: ['not_started', 'in_progress', 'achieved', 'delayed', 'cancelled'])]
    private ?string $status = 'in_progress';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['isms_objective:read', 'isms_objective:write'])]
    private ?string $progressNotes = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    #[Groups(['isms_objective:read'])]
    private ?\DateTimeInterface $achievedDate = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['isms_objective:read'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['isms_objective:read'])]
    private ?\DateTimeInterface $updatedAt = null;

    
    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Tenant $tenant = null;

public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
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

    #[Groups(['isms_objective:read'])]
    public function getProgressPercentage(): int
    {
        if ($this->targetValue && $this->currentValue && (float)$this->targetValue > 0) {
            return (int)(((float)$this->currentValue / (float)$this->targetValue) * 100);
        }
        return 0;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function setTenant(?Tenant $tenant): static
    {
        $this->tenant = $tenant;
        return $this;
    }
}
