<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RecurringDutyRepository;
use App\Util\LegalPeriod;
use App\Validator\Constraint\SameTenantOwner;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A recurring duty of an organisation that no record carries a date for:
 * review of the risk acceptance criteria, BIA, BC exercise, SoA review, key
 * rotation, management training (NIS-2 Art. 20), TOM review, DORA register …
 *
 * What the duty is (norm reference, default interval, fallback roles) lives in
 * {@see \App\Service\RecurringDuty\RecurringDutyCatalog}; the row holds the
 * organisation's interval, the next due date, who does it and the last
 * completion with its evidence. "Done" moves the due date by the interval.
 */
#[ORM\Entity(repositoryClass: RecurringDutyRepository::class)]
#[ORM\Table(name: 'recurring_duty')]
#[ORM\UniqueConstraint(name: 'uniq_recurring_duty_tenant_key', columns: ['tenant_id', 'duty_key'])]
class RecurringDuty
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\Column(name: 'duty_key', length: 64)]
    private string $dutyKey;

    #[ORM\Column]
    #[Assert\Range(min: 1, max: 60)]
    private int $intervalMonths;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull]
    private DateTimeImmutable $nextDueAt;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $lastDoneAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[SameTenantOwner]
    private ?User $owner = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $lastDoneBy = null;

    /** Evidence of the last completion (protocol, report, signed list). */
    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Document $lastEvidence = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastNote = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    public function __construct(Tenant $tenant, string $dutyKey, int $intervalMonths, DateTimeImmutable $nextDueAt)
    {
        $this->tenant = $tenant;
        $this->dutyKey = $dutyKey;
        $this->intervalMonths = $intervalMonths;
        $this->nextDueAt = $nextDueAt;
        $this->createdAt = new DateTimeImmutable();
    }

    /** Records one completion and schedules the next one interval later. */
    public function markDone(DateTimeImmutable $doneAt, ?User $by, ?Document $evidence = null, ?string $note = null): void
    {
        $this->lastDoneAt = $doneAt->setTime(0, 0);
        $this->lastDoneBy = $by;
        $this->lastEvidence = $evidence;
        $this->lastNote = $note !== null && trim($note) !== '' ? trim($note) : null;
        $this->nextDueAt = LegalPeriod::addMonths($this->lastDoneAt, $this->intervalMonths);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getDutyKey(): string
    {
        return $this->dutyKey;
    }

    public function getIntervalMonths(): int
    {
        return $this->intervalMonths;
    }

    /** A new interval counts from the last completion, when there is one. */
    public function setIntervalMonths(int $intervalMonths): static
    {
        $this->intervalMonths = $intervalMonths;
        if ($this->lastDoneAt !== null) {
            $this->nextDueAt = LegalPeriod::addMonths($this->lastDoneAt, $intervalMonths);
        }

        return $this;
    }

    public function getNextDueAt(): DateTimeImmutable
    {
        return $this->nextDueAt;
    }

    public function setNextDueAt(DateTimeImmutable $nextDueAt): static
    {
        $this->nextDueAt = $nextDueAt;

        return $this;
    }

    public function getLastDoneAt(): ?DateTimeImmutable
    {
        return $this->lastDoneAt;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    public function getLastDoneBy(): ?User
    {
        return $this->lastDoneBy;
    }

    public function getLastEvidence(): ?Document
    {
        return $this->lastEvidence;
    }

    public function getLastNote(): ?string
    {
        return $this->lastNote;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
