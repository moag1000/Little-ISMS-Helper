<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UnavailabilityPeriodRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A holiday (single day) or shutdown (date span) within a tenant's
 * {@see UnavailabilityCalendar}.
 */
#[ORM\Entity(repositoryClass: UnavailabilityPeriodRepository::class)]
#[ORM\Table(name: 'unavailability_periods')]
#[ORM\Index(name: 'idx_unavail_period_cal', columns: ['calendar_id'])]
#[ORM\Index(name: 'idx_unavail_period_range', columns: ['start_date', 'end_date'])]
class UnavailabilityPeriod
{
    public const string KIND_HOLIDAY = 'holiday';
    public const string KIND_SHUTDOWN = 'shutdown';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: UnavailabilityCalendar::class, inversedBy: 'periods')]
    #[ORM\JoinColumn(name: 'calendar_id', nullable: false, onDelete: 'CASCADE')]
    private ?UnavailabilityCalendar $calendar = null;

    #[ORM\Column(length: 10)]
    #[Assert\Choice(choices: [self::KIND_HOLIDAY, self::KIND_SHUTDOWN])]
    private string $kind = self::KIND_HOLIDAY;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull]
    private ?DateTimeImmutable $startDate = null;

    /** null for a single-day holiday; the inclusive end for a shutdown span. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $endDate = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $label = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCalendar(): ?UnavailabilityCalendar
    {
        return $this->calendar;
    }

    public function setCalendar(?UnavailabilityCalendar $calendar): static
    {
        $this->calendar = $calendar;
        return $this;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function setKind(string $kind): static
    {
        $this->kind = $kind;
        return $this;
    }

    public function getStartDate(): ?DateTimeImmutable
    {
        return $this->startDate;
    }

    public function setStartDate(?DateTimeImmutable $startDate): static
    {
        $this->startDate = $startDate;
        return $this;
    }

    public function getEndDate(): ?DateTimeImmutable
    {
        return $this->endDate;
    }

    public function setEndDate(?DateTimeImmutable $endDate): static
    {
        $this->endDate = $endDate;
        return $this;
    }

    /** Inclusive effective end (= startDate for a single-day holiday). */
    public function getEffectiveEndDate(): ?DateTimeImmutable
    {
        return $this->endDate ?? $this->startDate;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): static
    {
        $this->label = $label;
        return $this;
    }
}
