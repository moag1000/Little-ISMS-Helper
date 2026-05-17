<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LifecycleConfigRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LifecycleConfigRepository::class)]
#[ORM\Table(name: 'lifecycle_config')]
#[ORM\UniqueConstraint(name: 'uniq_lifecycle_override', columns: ['tenant_id', 'workflow_name', 'transition_name', 'config_key'])]
class LifecycleConfig
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\Column(length: 64)]
    private string $workflowName;

    #[ORM\Column(length: 64)]
    private string $transitionName;

    #[ORM\Column(name: 'config_key', length: 64)]
    private string $configKey;

    #[ORM\Column(name: 'config_value', type: 'json')]
    private mixed $configValue;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'updated_by_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $updatedByUser = null;

    public function getId(): ?int { return $this->id; }
    public function getTenant(): Tenant { return $this->tenant; }
    public function setTenant(Tenant $t): self { $this->tenant = $t; return $this; }
    public function getWorkflowName(): string { return $this->workflowName; }
    public function setWorkflowName(string $n): self { $this->workflowName = $n; return $this; }
    public function getTransitionName(): string { return $this->transitionName; }
    public function setTransitionName(string $n): self { $this->transitionName = $n; return $this; }
    public function getConfigKey(): string { return $this->configKey; }
    public function setConfigKey(string $k): self { $this->configKey = $k; return $this; }
    public function getConfigValue(): mixed { return $this->configValue; }
    public function setConfigValue(mixed $v): self { $this->configValue = $v; return $this; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeImmutable $d): self { $this->updatedAt = $d; return $this; }
    public function getUpdatedByUser(): ?User { return $this->updatedByUser; }
    public function setUpdatedByUser(?User $u): self { $this->updatedByUser = $u; return $this; }
}
