<?php

namespace App\Entity;

use DateTimeInterface;
use DateTimeImmutable;
use App\Repository\ScheduledReportRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Scheduled Report Entity
 *
 * Phase 7A: Stores configuration for automated report generation and delivery.
 * Reports can be scheduled daily, weekly, or monthly and sent via email.
 * Must be manually activated by an administrator.
 */
#[ORM\Entity(repositoryClass: ScheduledReportRepository::class)]
#[ORM\Table(name: 'scheduled_report')]
#[ORM\Index(name: 'idx_scheduled_report_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_scheduled_report_active', columns: ['is_active'])]
#[ORM\Index(name: 'idx_scheduled_report_next_run', columns: ['next_run_at'])]
#[ORM\HasLifecycleCallbacks]
class ScheduledReport
{
    public const TYPE_EXECUTIVE = 'executive';
    public const TYPE_RISK = 'risk';
    public const TYPE_BCM = 'bcm';
    public const TYPE_COMPLIANCE = 'compliance';
    public const TYPE_AUDIT = 'audit';
    public const TYPE_ASSETS = 'assets';
    public const TYPE_GDPR = 'gdpr';

    public const SCHEDULE_DAILY = 'daily';
    public const SCHEDULE_WEEKLY = 'weekly';
    public const SCHEDULE_MONTHLY = 'monthly';

    public const FORMAT_PDF = 'pdf';
    public const FORMAT_EXCEL = 'excel';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private ?string $name = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: [
        self::TYPE_EXECUTIVE,
        self::TYPE_RISK,
        self::TYPE_BCM,
        self::TYPE_COMPLIANCE,
        self::TYPE_AUDIT,
        self::TYPE_ASSETS,
        self::TYPE_GDPR,
    ])]
    private ?string $reportType = null;

    #[ORM\Column(length: 20)]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: [
        self::SCHEDULE_DAILY,
        self::SCHEDULE_WEEKLY,
        self::SCHEDULE_MONTHLY,
    ])]
    private ?string $schedule = null;

    #[ORM\Column(length: 10)]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: [self::FORMAT_PDF, self::FORMAT_EXCEL])]
    private ?string $format = self::FORMAT_PDF;

    #[ORM\Column(type: Types::JSON)]
    #[Assert\NotBlank]
    private array $recipients = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column]
    private bool $isActive = false;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?DateTimeInterface $lastRunAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?DateTimeInterface $nextRunAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $lastRunStatus = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastRunMessage = null;

    #[ORM\Column(type: Types::TIME_MUTABLE, nullable: true)]
    private ?DateTimeInterface $preferredTime = null;

    #[ORM\Column(nullable: true)]
    private ?int $dayOfWeek = null;

    #[ORM\Column(nullable: true)]
    private ?int $dayOfMonth = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $locale = 'de';

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $createdBy = null;

    #[ORM\Column]
    private ?int $tenantId = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function setUpdatedAtValue(): void
    {
        $this->updatedAt = new DateTimeImmutable();
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

    public function getReportType(): ?string
    {
        return $this->reportType;
    }

    public function setReportType(string $reportType): static
    {
        $this->reportType = $reportType;
        return $this;
    }

    public function getSchedule(): ?string
    {
        return $this->schedule;
    }

    public function setSchedule(string $schedule): static
    {
        $this->schedule = $schedule;
        return $this;
    }

    public function getFormat(): ?string
    {
        return $this->format;
    }

    public function setFormat(string $format): static
    {
        $this->format = $format;
        return $this;
    }

    public function getRecipients(): array
    {
        return $this->recipients;
    }

    public function setRecipients(array $recipients): static
    {
        $this->recipients = $recipients;
        return $this;
    }

    public function addRecipient(string $email): static
    {
        if (!in_array($email, $this->recipients, true)) {
            $this->recipients[] = $email;
        }
        return $this;
    }

    public function removeRecipient(string $email): static
    {
        $this->recipients = array_filter($this->recipients, fn($r) => $r !== $email);
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

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
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

    public function getLastRunStatus(): ?int
    {
        return $this->lastRunStatus;
    }

    public function setLastRunStatus(?int $lastRunStatus): static
    {
        $this->lastRunStatus = $lastRunStatus;
        return $this;
    }

    public function getLastRunMessage(): ?string
    {
        return $this->lastRunMessage;
    }

    public function setLastRunMessage(?string $lastRunMessage): static
    {
        $this->lastRunMessage = $lastRunMessage;
        return $this;
    }

    public function getPreferredTime(): ?DateTimeInterface
    {
        return $this->preferredTime;
    }

    public function setPreferredTime(?DateTimeInterface $preferredTime): static
    {
        $this->preferredTime = $preferredTime;
        return $this;
    }

    public function getDayOfWeek(): ?int
    {
        return $this->dayOfWeek;
    }

    public function setDayOfWeek(?int $dayOfWeek): static
    {
        $this->dayOfWeek = $dayOfWeek;
        return $this;
    }

    public function getDayOfMonth(): ?int
    {
        return $this->dayOfMonth;
    }

    public function setDayOfMonth(?int $dayOfMonth): static
    {
        $this->dayOfMonth = $dayOfMonth;
        return $this;
    }

    public function getLocale(): ?string
    {
        return $this->locale;
    }

    public function setLocale(?string $locale): static
    {
        $this->locale = $locale;
        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;
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

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    /**
     * Calculate next run time based on schedule configuration
     */
    public function calculateNextRunAt(): void
    {
        $now = new \DateTime();
        $preferredTime = $this->preferredTime ?? new \DateTime('08:00:00');

        $next = clone $now;
        $next->setTime(
            (int) $preferredTime->format('H'),
            (int) $preferredTime->format('i'),
            0
        );

        switch ($this->schedule) {
            case self::SCHEDULE_DAILY:
                if ($next <= $now) {
                    $next->modify('+1 day');
                }
                break;

            case self::SCHEDULE_WEEKLY:
                $targetDay = $this->dayOfWeek ?? 1; // Default: Monday
                $currentDay = (int) $now->format('N');
                $daysUntilTarget = ($targetDay - $currentDay + 7) % 7;

                if ($daysUntilTarget === 0 && $next <= $now) {
                    $daysUntilTarget = 7;
                }

                $next->modify("+{$daysUntilTarget} days");
                break;

            case self::SCHEDULE_MONTHLY:
                $targetDay = $this->dayOfMonth ?? 1; // Default: 1st of month
                $next->setDate((int) $next->format('Y'), (int) $next->format('m'), min($targetDay, (int) $next->format('t')));

                if ($next <= $now) {
                    $next->modify('+1 month');
                    $next->setDate((int) $next->format('Y'), (int) $next->format('m'), min($targetDay, (int) $next->format('t')));
                }
                break;
        }

        $this->nextRunAt = $next;
    }

    /**
     * Check if this report is due to run
     */
    public function isDue(): bool
    {
        if (!$this->isActive || $this->nextRunAt === null) {
            return false;
        }

        return $this->nextRunAt <= new \DateTime();
    }

    /**
     * Get available report types
     */
    public static function getReportTypes(): array
    {
        return [
            self::TYPE_EXECUTIVE => 'Executive Summary',
            self::TYPE_RISK => 'Risk Management',
            self::TYPE_BCM => 'Business Continuity',
            self::TYPE_COMPLIANCE => 'Compliance Status',
            self::TYPE_AUDIT => 'Audit Management',
            self::TYPE_ASSETS => 'Asset Inventory',
            self::TYPE_GDPR => 'Data Protection (GDPR)',
        ];
    }

    /**
     * Get available schedules
     */
    public static function getSchedules(): array
    {
        return [
            self::SCHEDULE_DAILY => 'Daily',
            self::SCHEDULE_WEEKLY => 'Weekly',
            self::SCHEDULE_MONTHLY => 'Monthly',
        ];
    }

    /**
     * Get available formats
     */
    public static function getFormats(): array
    {
        return [
            self::FORMAT_PDF => 'PDF',
            self::FORMAT_EXCEL => 'Excel',
        ];
    }

    /**
     * Get days of week
     */
    public static function getDaysOfWeek(): array
    {
        return [
            1 => 'Monday',
            2 => 'Tuesday',
            3 => 'Wednesday',
            4 => 'Thursday',
            5 => 'Friday',
            6 => 'Saturday',
            7 => 'Sunday',
        ];
    }
}
