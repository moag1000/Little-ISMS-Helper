<?php

declare(strict_types=1);

namespace App\Security\Persona;

use App\Security\Voter\ReportAccessVoter;

/**
 * The working role a user has in the ISMS ("which persona am I").
 *
 * One table for everything that used to be decided per feature: the
 * dashboard a persona lands on, the guided tour it gets, which persona
 * dashboards it may open and which reports it may download. Resolution
 * (user → persona) lives in {@see PersonaResolver}.
 *
 * Case order is the priority: when a user holds several persona roles,
 * the first matching case is the one they land as. Generic roles
 * (admin, manager, auditor, user) come last, so an explicit persona role
 * always wins over them.
 */
enum Persona: string
{
    case CISO = 'ciso';
    case ISB = 'isb';
    case RISK_MANAGER = 'risk_manager';
    case COMPLIANCE_MANAGER = 'compliance_manager';
    case DPO = 'dpo';
    case BCM_OFFICER = 'bcm_officer';
    case TOP_MANAGEMENT = 'top_management';
    case ADMIN = 'admin';
    case GROUP_CISO = 'group_ciso';
    case KONZERN_AUDITOR = 'konzern_auditor';
    case MANAGER = 'manager';
    case AUDITOR = 'auditor';
    case RISK_OWNER = 'risk_owner';
    case USER = 'user';

    /** Reports every operational persona (manager level and above) may download. */
    private const array ALL_REPORTS = [
        ReportAccessVoter::AUDIT_PACKAGE,
        ReportAccessVoter::CERTIFICATION_BUNDLE,
        ReportAccessVoter::CERTIFICATION_READINESS,
        ReportAccessVoter::EVIDENCE_PACKAGE,
        ReportAccessVoter::BOARD_ONE_PAGER,
        ReportAccessVoter::REVIEW_OUTPUT,
        ReportAccessVoter::QUARTERLY_TREND,
    ];

    /** What an auditor needs for the audit — read-only exports of the evidence. */
    private const array AUDIT_REPORTS = [
        ReportAccessVoter::AUDIT_PACKAGE,
        ReportAccessVoter::CERTIFICATION_BUNDLE,
        ReportAccessVoter::CERTIFICATION_READINESS,
        ReportAccessVoter::EVIDENCE_PACKAGE,
        ReportAccessVoter::REVIEW_OUTPUT,
    ];

    /** What top management needs to steer (ISO 27001 5.1, 9.3; NIS-2 Art. 20). */
    private const array MANAGEMENT_REPORTS = [
        ReportAccessVoter::BOARD_ONE_PAGER,
        ReportAccessVoter::REVIEW_OUTPUT,
        ReportAccessVoter::QUARTERLY_TREND,
        ReportAccessVoter::CERTIFICATION_READINESS,
    ];

    /**
     * Symfony roles that make a user this persona.
     *
     * @return list<string>
     */
    public function roles(): array
    {
        return match ($this) {
            self::CISO => ['ROLE_CISO'],
            self::ISB => ['ROLE_ISB'],
            self::RISK_MANAGER => ['ROLE_RISK_MANAGER'],
            self::COMPLIANCE_MANAGER => ['ROLE_COMPLIANCE_MANAGER'],
            self::DPO => ['ROLE_DPO'],
            self::BCM_OFFICER => ['ROLE_BCM_OFFICER', 'ROLE_GROUP_BCM_OFFICER'],
            self::TOP_MANAGEMENT => ['ROLE_TOP_MGMT'],
            self::ADMIN => ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'],
            self::GROUP_CISO => ['ROLE_GROUP_CISO'],
            self::KONZERN_AUDITOR => ['ROLE_KONZERN_AUDITOR'],
            self::MANAGER => ['ROLE_MANAGER'],
            self::AUDITOR => ['ROLE_AUDITOR'],
            self::RISK_OWNER => ['ROLE_FUNCTION_OWNER'],
            self::USER => ['ROLE_USER'],
        };
    }

    /**
     * The voter attribute that guards this persona's own dashboard, or null
     * when the dashboard is not a persona dashboard.
     */
    public function voterAttribute(): ?string
    {
        return match ($this) {
            self::CISO => 'PERSONA_CISO',
            self::ISB => 'PERSONA_ISB',
            self::RISK_MANAGER => 'PERSONA_RISK',
            self::COMPLIANCE_MANAGER => 'PERSONA_COMPLIANCE',
            self::DPO => 'PERSONA_DPO',
            self::BCM_OFFICER => 'PERSONA_BCM',
            self::TOP_MANAGEMENT => 'PERSONA_BOARD',
            default => null,
        };
    }

    public static function fromVoterAttribute(string $attribute): ?self
    {
        foreach (self::cases() as $persona) {
            if ($persona->voterAttribute() === $attribute) {
                return $persona;
            }
        }

        return null;
    }

    /**
     * Personas that may open this persona's dashboard. The board view is
     * also prepared by CISO and compliance manager and read by the group CISO.
     *
     * @return list<self>
     */
    public function dashboardAudience(): array
    {
        return match ($this) {
            self::TOP_MANAGEMENT => [self::TOP_MANAGEMENT, self::CISO, self::COMPLIANCE_MANAGER, self::GROUP_CISO],
            default => [$this],
        };
    }

    /** The persona's own dashboard. */
    public function dashboardRoute(): string
    {
        return match ($this) {
            self::CISO => 'app_dashboard_ciso',
            self::ISB => 'app_dashboard_isb',
            self::RISK_MANAGER => 'app_dashboard_risk_manager',
            self::COMPLIANCE_MANAGER => 'app_dashboard_compliance_manager',
            self::DPO => 'app_dashboard_dpo',
            self::BCM_OFFICER => 'app_dashboard_bcm',
            self::TOP_MANAGEMENT => 'app_dashboard_board',
            self::AUDITOR, self::KONZERN_AUDITOR => 'app_dashboard_auditor',
            default => 'app_dashboard',
        };
    }

    /** Module the dashboard needs; without it the persona falls back to the main dashboard. */
    public function dashboardModule(): ?string
    {
        return match ($this) {
            self::DPO => 'privacy',
            self::BCM_OFFICER => 'bcm',
            default => null,
        };
    }

    /**
     * Personas who open the tool to work off what is waiting for them land
     * on My Day instead of a dashboard (risk owners, plain users, auditors
     * inside the organisation).
     */
    public function landsOnMyDay(): bool
    {
        return in_array($this, [self::AUDITOR, self::RISK_OWNER, self::USER], true);
    }

    /** Guided tour id (GuidedTourService::TOUR_*). */
    public function tour(): string
    {
        return match ($this) {
            self::CISO, self::ADMIN => 'ciso',
            self::ISB, self::RISK_MANAGER, self::BCM_OFFICER, self::MANAGER => 'isb',
            self::COMPLIANCE_MANAGER, self::DPO => 'cm',
            self::TOP_MANAGEMENT, self::GROUP_CISO => 'board',
            self::AUDITOR, self::KONZERN_AUDITOR => 'auditor',
            self::RISK_OWNER => 'risk_owner',
            self::USER => 'junior',
        };
    }

    /**
     * Report-access attributes ({@see ReportAccessVoter}) this persona grants.
     *
     * @return list<string>
     */
    public function reports(): array
    {
        return match ($this) {
            self::CISO, self::ISB, self::RISK_MANAGER, self::COMPLIANCE_MANAGER, self::DPO,
            self::BCM_OFFICER, self::ADMIN, self::MANAGER => self::ALL_REPORTS,
            self::TOP_MANAGEMENT => self::MANAGEMENT_REPORTS,
            self::GROUP_CISO => array_values(array_unique([...self::AUDIT_REPORTS, ...self::MANAGEMENT_REPORTS])),
            self::AUDITOR, self::KONZERN_AUDITOR => self::AUDIT_REPORTS,
            self::RISK_OWNER, self::USER => [],
        };
    }
}
