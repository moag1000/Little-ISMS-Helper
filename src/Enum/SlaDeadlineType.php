<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * SLA Deadline Type Enum — Sprint 7A F3 Wave 2
 *
 * Enumerates all regulatory and custom SLA deadline categories
 * tracked by SlaDeadlineMonitor.
 *
 * Compliance references:
 *  - gdpr_72h:               GDPR Art. 33 (supervisory authority notification)
 *  - dora_4h:                DORA Art. 19 (major ICT incidents — initial notification)
 *  - dora_24h:               DORA Art. 19 (major ICT incidents — intermediate report)
 *  - dora_1mo:               DORA Art. 19 (major ICT incidents — final report)
 *  - nis2_24h:               NIS2 Art. 23 (significant incidents — early warning)
 *  - nis2_72h:               NIS2 Art. 23 (significant incidents — incident notification)
 *  - nis2_1mo:               NIS2 Art. 23 (significant incidents — final report)
 *  - iso_corrective_action_30d: ISO 27001 Cl. 10.1 (corrective actions)
 *  - custom:                 Tenant-defined SLA
 */
enum SlaDeadlineType: string
{
    case GdprNotification72h          = 'gdpr_72h';
    case DoraInitialNotification4h    = 'dora_4h';
    case DoraIntermediate24h          = 'dora_24h';
    case DoraFinalReport1mo           = 'dora_1mo';
    case Nis2EarlyWarning24h          = 'nis2_24h';
    case Nis2Notification72h          = 'nis2_72h';
    case Nis2FinalReport1mo           = 'nis2_1mo';
    case IsoCorrectiveAction30d       = 'iso_corrective_action_30d';
    case Custom                       = 'custom';

    /**
     * Duration in hours for each deadline type.
     * Used by SlaDeadlineFactory to calculate deadlineAt from triggeredAt.
     */
    public function durationHours(): int
    {
        return match ($this) {
            self::GdprNotification72h        => 72,
            self::DoraInitialNotification4h  => 4,
            self::DoraIntermediate24h        => 24,
            self::DoraFinalReport1mo         => 720,   // 30 days × 24h
            self::Nis2EarlyWarning24h        => 24,
            self::Nis2Notification72h        => 72,
            self::Nis2FinalReport1mo         => 720,   // 30 days × 24h
            self::IsoCorrectiveAction30d     => 720,   // 30 days × 24h
            self::Custom                     => 0,     // caller must set deadlineAt explicitly
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::GdprNotification72h        => 'GDPR Art. 33 — 72h notification',
            self::DoraInitialNotification4h  => 'DORA Art. 19 — 4h initial notification',
            self::DoraIntermediate24h        => 'DORA Art. 19 — 24h intermediate report',
            self::DoraFinalReport1mo         => 'DORA Art. 19 — 1-month final report',
            self::Nis2EarlyWarning24h        => 'NIS2 Art. 23 — 24h early warning',
            self::Nis2Notification72h        => 'NIS2 Art. 23 — 72h notification',
            self::Nis2FinalReport1mo         => 'NIS2 Art. 23 — 1-month final report',
            self::IsoCorrectiveAction30d     => 'ISO 27001 Cl. 10.1 — 30d corrective action',
            self::Custom                     => 'Custom SLA',
        };
    }
}
