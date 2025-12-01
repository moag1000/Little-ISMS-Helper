<?php

namespace App\Entity;

use App\Repository\ScheduledTaskRepository;
use DateTime;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Scheduled Task Entity
 *
 * Stores user-defined scheduled tasks that can be managed via UI
 */
#[ORM\Entity(repositoryClass: ScheduledTaskRepository::class)]
#[ORM\Table(name: 'scheduled_task')]
#[ORM\HasLifecycleCallbacks]
class ScheduledTask
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /**
     * Cron expression (e.g., "0 3 * * *" for 3:00 AM daily)
     */
    #[ORM\Column(length: 100)]
    private ?string $cronExpression = null;

    /**
     * Symfony Console command to execute (e.g., "app:cleanup-temp-files")
     */
    #[ORM\Column(length: 255)]
    private ?string $command = null;

    /**
     * Command arguments as JSON array
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $arguments = null;

    #[ORM\Column]
    private bool $enabled = true;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?DateTimeInterface $lastRunAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?DateTimeInterface $nextRunAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastOutput = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $lastStatus = null; // success, failed, running

    #[ORM\Column]
    private ?int $tenantId = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?DateTimeInterface $updatedAt = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new DateTime();
        $this->updatedAt = new DateTime();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new DateTime();
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

    public function getCronExpression(): ?string
    {
        return $this->cronExpression;
    }

    public function setCronExpression(string $cronExpression): static
    {
        $this->cronExpression = $cronExpression;

        return $this;
    }

    public function getCommand(): ?string
    {
        return $this->command;
    }

    public function setCommand(string $command): static
    {
        $this->command = $command;

        return $this;
    }

    public function getArguments(): ?array
    {
        return $this->arguments;
    }

    public function setArguments(?array $arguments): static
    {
        $this->arguments = $arguments;

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function getLastRunAt(): ?DateTimeInterface
    {
        return $this->lastRunAt;
    }

    public function setLastRunAt(?DateTimeInterface $lastRunAt): static
    {
        $this->lastRunAt = $lastRunAt;

        return $this;
    }

    public function getNextRunAt(): ?DateTimeInterface
    {
        return $this->nextRunAt;
    }

    public function setNextRunAt(?DateTimeInterface $nextRunAt): static
    {
        $this->nextRunAt = $nextRunAt;

        return $this;
    }

    public function getLastOutput(): ?string
    {
        return $this->lastOutput;
    }

    public function setLastOutput(?string $lastOutput): static
    {
        $this->lastOutput = $lastOutput;

        return $this;
    }

    public function getLastStatus(): ?string
    {
        return $this->lastStatus;
    }

    public function setLastStatus(?string $lastStatus): static
    {
        $this->lastStatus = $lastStatus;

        return $this;
    }

    public function getTenantId(): ?int
    {
        return $this->tenantId;
    }

    public function setTenantId(int $tenantId): static
    {
        $this->tenantId = $tenantId;

        return $this;
    }

    public function getCreatedAt(): ?DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}
