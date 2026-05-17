<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * ThreatIntelligence lifecycle states (MITRE/STIX threat triage).
 *
 * Backed by the existing `threat_intelligence.status` column. Choice list
 * comes from ThreatIntelligenceType FormType; values are unchanged so no
 * data migration is needed.
 *
 * Phase 1 status-enum rollout — typed surface for application code while
 * the storage column and setStatus(string) accessor stay backward-compatible.
 */
enum ThreatIntelligenceStatus: string
{
    case New = 'new';
    case Analyzing = 'analyzing';
    case Mitigated = 'mitigated';
    case Monitoring = 'monitoring';
    case Closed = 'closed';

    public function label(): string
    {
        return 'status_type.' . $this->value;
    }

    public function pillVariant(): string
    {
        return match ($this) {
            self::New        => 'danger',
            self::Analyzing  => 'info',
            self::Mitigated  => 'success',
            self::Monitoring => 'warning',
            self::Closed     => 'neutral',
        };
    }
}
