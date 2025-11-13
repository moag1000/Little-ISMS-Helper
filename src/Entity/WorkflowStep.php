<?php

namespace App\Entity;

use App\Entity\Tenant;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'workflow_steps')]
class WorkflowStep
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Workflow::class, inversedBy: 'steps')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Workflow $workflow = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $stepOrder = 0;

    #[ORM\Column(length: 50)]
    private string $stepType = 'approval'; // approval, notification, auto_action

    #[ORM\Column(length: 100)]
    private ?string $approverRole = null; // e.g., 'ROLE_MANAGER', 'ROLE_ADMIN'

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $approverUsers = null; // Array of user IDs

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isRequired = true;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $daysToComplete = null; // SLA in days

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Tenant $tenant = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWorkflow(): ?Workflow
    {
        return $this->workflow;
    }

    public function setWorkflow(?Workflow $workflow): static
    {
        $this->workflow = $workflow;
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

    public function getStepOrder(): int
    {
        return $this->stepOrder;
    }

    public function setStepOrder(int $stepOrder): static
    {
        $this->stepOrder = $stepOrder;
        return $this;
    }

    public function getStepType(): string
    {
        return $this->stepType;
    }

    public function setStepType(string $stepType): static
    {
        $this->stepType = $stepType;
        return $this;
    }

    public function getApproverRole(): ?string
    {
        return $this->approverRole;
    }

    public function setApproverRole(?string $approverRole): static
    {
        $this->approverRole = $approverRole;
        return $this;
    }

    public function getApproverUsers(): ?array
    {
        return $this->approverUsers;
    }

    public function setApproverUsers(?array $approverUsers): static
    {
        $this->approverUsers = $approverUsers;
        return $this;
    }

    public function isRequired(): bool
    {
        return $this->isRequired;
    }

    public function setIsRequired(bool $isRequired): static
    {
        $this->isRequired = $isRequired;
        return $this;
    }

    public function getDaysToComplete(): ?int
    {
        return $this->daysToComplete;
    }

    public function setDaysToComplete(?int $daysToComplete): static
    {
        $this->daysToComplete = $daysToComplete;
        return $this;
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
