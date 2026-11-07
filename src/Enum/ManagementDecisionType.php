<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What top management decides on (ISO 27001 5.1, 5.2, 6.1.2, 6.1.3, 6.2, 7.1,
 * 9.3.3; NIS-2 Art. 20 (1)). One record type for every decision the norms
 * reserve for top management, so the board sees them in one list with evidence.
 */
enum ManagementDecisionType: string
{
    /** ISMS scope and information security policy approved (5.1 a, 5.2). */
    case Policy = 'policy';
    /** Risk acceptance criteria / approval thresholds approved (6.1.2 a). */
    case RiskCriteria = 'risk_criteria';
    /** One residual risk accepted at board level (6.1.3 f, 8.3). */
    case RiskAcceptance = 'risk_acceptance';
    /** Risk accepted automatically under approved criteria — points to that approval. */
    case AutoPolicy = 'auto_policy';
    /** Statement of Applicability approved (6.1.3 d). */
    case Soa = 'soa';
    /** Results of a management review (9.3.3). */
    case ManagementReview = 'mgmt_review';
    /** Cybersecurity risk-management measures approved by the management body (NIS-2 Art. 20 (1)). */
    case Nis2Measures = 'nis2_measures';
    /** Resources for the ISMS (5.1 c, 7.1). */
    case Resources = 'resources';
    /** Information security objectives (5.1 a, 6.2). */
    case Objectives = 'objectives';
    case Other = 'other';

    public function label(): string
    {
        return 'management_decision.type.' . $this->value;
    }

    public function normReference(): string
    {
        return match ($this) {
            self::Policy => 'ISO 27001 5.1 a, 5.2',
            self::RiskCriteria => 'ISO 27001 6.1.2 a',
            self::RiskAcceptance => 'ISO 27001 6.1.3 f, 8.3',
            self::AutoPolicy => 'ISO 27001 6.1.3 f',
            self::Soa => 'ISO 27001 6.1.3 d',
            self::ManagementReview => 'ISO 27001 9.3.3',
            self::Nis2Measures => 'NIS-2 Art. 20 (1)',
            self::Resources => 'ISO 27001 5.1 c, 7.1',
            self::Objectives => 'ISO 27001 5.1 a, 6.2',
            self::Other => 'ISO 27001 5.1',
        };
    }

    /** Months an approval stays valid before top management confirms it again; null = no expiry. */
    public function validityMonths(): ?int
    {
        return match ($this) {
            self::Policy, self::RiskCriteria, self::Soa, self::Nis2Measures, self::Objectives => 12,
            default => null,
        };
    }

    /**
     * Types someone prepares and puts before top management. The others are
     * recorded where the decision is taken: risk acceptance in the risk
     * workflow, review results when the review is completed, automatic
     * acceptance by the acceptance service.
     *
     * @return list<self>
     */
    public static function proposable(bool $nis2Active): array
    {
        $types = [self::Policy, self::RiskCriteria, self::Soa, self::Objectives, self::Resources];
        if ($nis2Active) {
            $types[] = self::Nis2Measures;
        }
        $types[] = self::Other;

        return $types;
    }
}
