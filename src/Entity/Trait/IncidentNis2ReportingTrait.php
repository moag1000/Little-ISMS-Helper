<?php

declare(strict_types=1);

namespace App\Entity\Trait;

use App\Util\LegalPeriod;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * NIS-2 Art. 23 reporting: the three deadlines (24 h early warning, 72 h
 * notification, final report one month after the notification) and the
 * early-warning statement on unlawful or malicious acts. Extracted from
 * Incident to keep the entity within its god-class budget.
 */
trait IncidentNis2ReportingTrait
{
    /**
     * NIS-2 Art. 23 (4) (a): the early warning states whether the incident is
     * suspected of being caused by unlawful or malicious acts. Null = not yet
     * assessed (the BSI export then reports "unknown" instead of guessing).
     */
    #[ORM\Column(type: Types::BOOLEAN, nullable: true)]
    #[Groups(['incident:read', 'incident:write'])]
    private ?bool $nis2SuspectedMalicious = null;

    public function getNis2SuspectedMalicious(): ?bool
    {
        return $this->nis2SuspectedMalicious;
    }

    public function setNis2SuspectedMalicious(?bool $nis2SuspectedMalicious): static
    {
        $this->nis2SuspectedMalicious = $nis2SuspectedMalicious;
        return $this;
    }


    /**
     * Get early warning deadline (24 hours from detection)
     */
    #[Groups(['incident:read'])]
    public function getEarlyWarningDeadline(): ?DateTimeImmutable
    {
        if (!$this->detectedAt instanceof DateTimeInterface) {
            return null;
        }
        return $this->detectedAt instanceof DateTimeImmutable
            ? $this->detectedAt->modify('+24 hours')
            : DateTimeImmutable::createFromMutable($this->detectedAt)->modify('+24 hours');
    }

    /**
     * Get detailed notification deadline (72 hours from detection)
     */
    #[Groups(['incident:read'])]
    public function getDetailedNotificationDeadline(): ?DateTimeImmutable
    {
        if (!$this->detectedAt instanceof DateTimeInterface) {
            return null;
        }
        return $this->detectedAt instanceof DateTimeImmutable
            ? $this->detectedAt->modify('+72 hours')
            : DateTimeImmutable::createFromMutable($this->detectedAt)->modify('+72 hours');
    }

    /**
     * NIS-2 Art. 23 (4) (d): one month after the incident notification was
     * submitted; until then the 72-hour notification deadline is the anchor.
     */
    #[Groups(['incident:read'])]
    public function getFinalReportDeadline(): ?DateTimeImmutable
    {
        $anchor = $this->detailedNotificationReportedAt ?? $this->getDetailedNotificationDeadline();

        return $anchor === null ? null : LegalPeriod::addMonths($anchor, 1);
    }

    #[Groups(['incident:read'])]
    public function isEarlyWarningOverdue(): bool
    {
        if ($this->earlyWarningReportedAt instanceof DateTimeImmutable) {
            return false; // Already reported
        }
        $deadline = $this->getEarlyWarningDeadline();
        if (!$deadline instanceof DateTimeImmutable) {
            return false; // No deadline if detectedAt is not set
        }
        return new DateTimeImmutable() > $deadline;
    }

    #[Groups(['incident:read'])]
    public function isDetailedNotificationOverdue(): bool
    {
        if ($this->detailedNotificationReportedAt instanceof DateTimeImmutable) {
            return false; // Already reported
        }
        $deadline = $this->getDetailedNotificationDeadline();
        if (!$deadline instanceof DateTimeImmutable) {
            return false; // No deadline if detectedAt is not set
        }
        return new DateTimeImmutable() > $deadline;
    }

    #[Groups(['incident:read'])]
    public function isFinalReportOverdue(): bool
    {
        if ($this->finalReportSubmittedAt instanceof DateTimeImmutable) {
            return false; // Already submitted
        }
        $deadline = $this->getFinalReportDeadline();
        if (!$deadline instanceof DateTimeImmutable) {
            return false; // No deadline if detectedAt is not set
        }
        return new DateTimeImmutable() > $deadline;
    }

    /**
     * Get hours remaining until early warning deadline
     */
    #[Groups(['incident:read'])]
    public function getHoursUntilEarlyWarningDeadline(): int
    {
        if ($this->earlyWarningReportedAt instanceof DateTimeImmutable) {
            return 0;
        }
        $deadline = $this->getEarlyWarningDeadline();
        if (!$deadline instanceof DateTimeImmutable) {
            return 0; // No deadline if detectedAt is not set
        }
        $now = new DateTimeImmutable();
        $diff = $now->diff($deadline);
        return $diff->invert ? 0 : ($diff->days * 24 + $diff->h);
    }

    /**
     * Get hours remaining until detailed notification deadline
     */
    #[Groups(['incident:read'])]
    public function getHoursUntilDetailedNotificationDeadline(): int
    {
        if ($this->detailedNotificationReportedAt instanceof DateTimeImmutable) {
            return 0;
        }
        $deadline = $this->getDetailedNotificationDeadline();
        if (!$deadline instanceof DateTimeImmutable) {
            return 0; // No deadline if detectedAt is not set
        }
        $now = new DateTimeImmutable();
        $diff = $now->diff($deadline);
        return $diff->invert ? 0 : ($diff->days * 24 + $diff->h);
    }

    /**
     * Get days remaining until final report deadline
     */
    #[Groups(['incident:read'])]
    public function getDaysUntilFinalReportDeadline(): int
    {
        if ($this->finalReportSubmittedAt instanceof DateTimeImmutable) {
            return 0;
        }
        $deadline = $this->getFinalReportDeadline();
        if (!$deadline instanceof DateTimeImmutable) {
            return 0; // No deadline if detectedAt is not set
        }
        $now = new DateTimeImmutable();
        $diff = $now->diff($deadline);
        return $diff->invert ? 0 : $diff->days;
    }

    /**
     * Get hours until final report deadline (for NIS2 one-month deadline)
     */
    #[Groups(['incident:read'])]
    public function getHoursUntilFinalReportDeadline(): float
    {
        if ($this->finalReportSubmittedAt instanceof DateTimeImmutable) {
            return 0;
        }
        $deadline = $this->getFinalReportDeadline();
        if (!$deadline instanceof DateTimeImmutable) {
            return 0; // No deadline if detectedAt is not set
        }
        $now = new DateTimeImmutable();
        $diff = $deadline->getTimestamp() - $now->getTimestamp();
        return $diff / 3600; // Convert seconds to hours
    }
}
