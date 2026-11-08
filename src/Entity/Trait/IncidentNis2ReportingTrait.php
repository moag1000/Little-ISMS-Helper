<?php

declare(strict_types=1);

namespace App\Entity\Trait;

use App\Entity\Tenant;
use App\Enum\IncidentSeverity;
use App\Service\Incident\Nis2SignificanceCriteria;
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
     * Default pre-check thresholds for "significant incident" (NIS-2 Art. 23
     * (3)); the organisation's own values come from
     * {@see Nis2SignificanceCriteria}. The organisation decides
     * ({@see $notificationRequired}).
     */
    public const int NIS2_FINANCIAL_LOSS_THRESHOLD_EUR = Nis2SignificanceCriteria::DEFAULT_FINANCIAL_LOSS_EUR;
    public const int NIS2_AFFECTED_PERSONS_THRESHOLD = Nis2SignificanceCriteria::DEFAULT_AFFECTED_PERSONS;

    /**
     * The one definition of "NIS-2 reportable": the organisation's own
     * assessment once made, otherwise the pre-check. Never for organisations
     * classified as not NIS-2 regulated, never without a detection time.
     */
    public function requiresNis2Reporting(): bool
    {
        if (!$this->detectedAt instanceof DateTimeInterface) {
            return false;
        }
        if ($this->tenant?->getNis2Classification() === Tenant::NIS2_NOT_REGULATED) {
            return false;
        }
        if ($this->notificationRequired !== null) {
            return $this->notificationRequired;
        }

        return $this->getNis2SignificanceReasons() !== [];
    }

    /** True once the organisation has decided whether the incident is reportable. */
    public function isNis2AssessmentConfirmed(): bool
    {
        return $this->notificationRequired !== null;
    }

    /**
     * Why the pre-check considers the incident significant — translation keys
     * `incident.nis2_reason.<key>` (domain incident). Empty = no criterion met.
     *
     * @return list<string>
     */
    public function getNis2SignificanceReasons(): array
    {
        $reasons = [];
        if (in_array($this->severity, [IncidentSeverity::High, IncidentSeverity::Critical], true)) {
            $reasons[] = 'severity';
        }
        if ($this->affectedBusinessProcesses->exists(
            static fn ($k, $process): bool => $process->getCriticality() === 'critical'
        )) {
            $reasons[] = 'critical_process';
        }
        if ($this->crossBorderImpact) {
            $reasons[] = 'cross_border';
        }
        if ((int) $this->affectedUsersCount >= Nis2SignificanceCriteria::affectedPersons($this->tenant)) {
            $reasons[] = 'affected_persons';
        }
        if ((float) $this->estimatedFinancialImpact >= Nis2SignificanceCriteria::financialLossEur($this->tenant)) {
            $reasons[] = 'financial_loss';
        }
        if ($this->nis2SuspectedMalicious === true) {
            $reasons[] = 'malicious';
        }

        return $reasons;
    }

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
     * The text that goes to the authority (NIS-2 Art. 23 (4), DORA Art. 19),
     * written by hand or accepted from "Entwurf vorschlagen". The MUS export
     * sends it as the short description when it is set.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['incident:read', 'incident:write'])]
    private ?string $reportSummary = null;

    public function getReportSummary(): ?string
    {
        return $this->reportSummary;
    }

    public function setReportSummary(?string $reportSummary): static
    {
        $this->reportSummary = $reportSummary;
        return $this;
    }


    /**
     * NIS-2 Art. 23 (4) (c): the CSIRT or authority may ask for an
     * intermediate report on relevant status updates. Optional: only once the
     * request is recorded does the planner add the duty, due by the date the
     * request names (else 72 h after the request).
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['incident:read', 'incident:write'])]
    private ?DateTimeImmutable $nis2InterimRequestedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['incident:read', 'incident:write'])]
    private ?DateTimeImmutable $nis2InterimDueAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['incident:read', 'incident:write'])]
    private ?DateTimeImmutable $nis2InterimReportedAt = null;

    public function getNis2InterimRequestedAt(): ?DateTimeImmutable
    {
        return $this->nis2InterimRequestedAt;
    }

    public function setNis2InterimRequestedAt(?DateTimeImmutable $requestedAt): static
    {
        $this->nis2InterimRequestedAt = $requestedAt;
        return $this;
    }

    public function getNis2InterimDueAt(): ?DateTimeImmutable
    {
        return $this->nis2InterimDueAt;
    }

    public function setNis2InterimDueAt(?DateTimeImmutable $dueAt): static
    {
        $this->nis2InterimDueAt = $dueAt;
        return $this;
    }

    public function getNis2InterimReportedAt(): ?DateTimeImmutable
    {
        return $this->nis2InterimReportedAt;
    }

    public function setNis2InterimReportedAt(?DateTimeImmutable $reportedAt): static
    {
        $this->nis2InterimReportedAt = $reportedAt;
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
