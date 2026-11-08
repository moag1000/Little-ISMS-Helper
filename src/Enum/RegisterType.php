<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The norm registers that have no record of their own elsewhere (Paket 9).
 *
 * One generic entry ({@see \App\Entity\IsmsRegisterEntry}) serves all of them;
 * a register type only decides which optional fields the form shows, which
 * records an entry may link to and which norm clause it answers. A new
 * register is a new case here, not a new table.
 *
 * Registers that already live in their own module (assets, suppliers,
 * documents, interested parties, processing activities, …) are listed by
 * {@see \App\Service\Register\RegisterCatalog} next to these.
 */
enum RegisterType: string
{
    case ISSUE_EXTERNAL = 'issue_external';
    case ISSUE_INTERNAL = 'issue_internal';
    case OPPORTUNITY = 'opportunity';
    case LEGAL_REQUIREMENT = 'legal_requirement';
    case SCOPE_INTERFACE = 'scope_interface';
    case ROLE = 'role';
    case COMPETENCE = 'competence';
    case COMMUNICATION = 'communication';
    case IMPROVEMENT = 'improvement';
    /** What is monitored for anomalous behaviour, how often and by whom (ISO 27001 A.8.16). */
    case MONITORING_SOURCE = 'monitoring_source';

    /** Optional entry fields, besides title, description, owner and review date. */
    public const array OPTIONAL_FIELDS = ['category', 'rating', 'reference', 'frequency', 'effectiveFrom'];

    public function normReference(): string
    {
        return match ($this) {
            self::ISSUE_EXTERNAL, self::ISSUE_INTERNAL => 'ISO 27001 4.1',
            self::OPPORTUNITY => 'ISO 27001 6.1.1',
            self::LEGAL_REQUIREMENT => 'ISO 27001 4.2 b · A.5.31 · NIS-2 Art. 21',
            self::SCOPE_INTERFACE => 'ISO 27001 4.3 c',
            self::ROLE => 'ISO 27001 5.3 · NIS-2 Art. 20',
            self::COMPETENCE => 'ISO 27001 7.2 · NIS-2 Art. 20 (2)',
            self::COMMUNICATION => 'ISO 27001 7.4',
            self::IMPROVEMENT => 'ISO 27001 10.1 · 9.3.2 g',
            self::MONITORING_SOURCE => 'ISO 27001 A.8.16 · NIS-2 Art. 21 (2) b',
        };
    }

    /**
     * Optional fields this register uses.
     *
     * @return list<string>
     */
    public function fields(): array
    {
        return match ($this) {
            self::ISSUE_EXTERNAL, self::ISSUE_INTERNAL => ['category', 'rating'],
            self::OPPORTUNITY => ['rating'],
            self::LEGAL_REQUIREMENT => ['category', 'reference', 'effectiveFrom'],
            self::SCOPE_INTERFACE => ['category'],
            self::ROLE => ['effectiveFrom'],
            self::COMPETENCE => ['category', 'reference'],
            self::COMMUNICATION => ['category', 'frequency', 'reference'],
            self::IMPROVEMENT => ['category', 'rating'],
            self::MONITORING_SOURCE => ['category', 'frequency', 'reference'],
        };
    }

    public function uses(string $field): bool
    {
        return in_array($field, $this->fields(), true);
    }

    /**
     * Choices of the category field (translation key suffix => stored value).
     *
     * @return list<string>
     */
    public function categories(): array
    {
        return match ($this) {
            self::ISSUE_EXTERNAL => ['political', 'economic', 'social', 'technological', 'legal', 'environmental', 'market'],
            self::ISSUE_INTERNAL => ['organisation', 'culture', 'resources', 'processes', 'technology', 'information'],
            self::LEGAL_REQUIREMENT => ['law', 'regulation', 'contract', 'standard', 'internal'],
            self::SCOPE_INTERFACE => ['supplier', 'customer', 'group', 'authority', 'it_service', 'other'],
            self::COMPETENCE => ['basic', 'intermediate', 'expert'],
            self::COMMUNICATION => ['email', 'meeting', 'intranet', 'training', 'report', 'other'],
            self::IMPROVEMENT => ['management_review', 'audit', 'incident', 'exercise', 'other'],
            self::MONITORING_SOURCE => ['network', 'system', 'application', 'cloud', 'identity', 'physical', 'other'],
            default => [],
        };
    }

    /** @return list<string> */
    public static function frequencies(): array
    {
        return ['weekly', 'monthly', 'quarterly', 'semi_annually', 'annually', 'on_event'];
    }

    /**
     * Records an entry of this register may link to ({@see \App\Service\Register\RegisterLinkTypes}).
     *
     * @return list<string>
     */
    public function linkFields(): array
    {
        return match ($this) {
            self::ISSUE_EXTERNAL, self::ISSUE_INTERNAL => ['risk', 'interested_party'],
            self::OPPORTUNITY => ['issue_entry', 'risk', 'objective'],
            self::LEGAL_REQUIREMENT => ['compliance_framework', 'interested_party', 'supplier', 'document'],
            self::SCOPE_INTERFACE => ['supplier', 'asset', 'business_process', 'location'],
            self::ROLE => ['competence_entry', 'document'],
            self::COMPETENCE => ['training'],
            self::COMMUNICATION => ['interested_party', 'document'],
            self::IMPROVEMENT => ['risk', 'objective', 'document'],
            self::MONITORING_SOURCE => ['asset', 'supplier', 'document'],
        };
    }

    /**
     * Role that maintains the register when an entry has no owner.
     * Every persona role inherits ROLE_MANAGER, so all of them may write.
     */
    public function maintainerRole(): string
    {
        return match ($this) {
            self::LEGAL_REQUIREMENT => 'ROLE_COMPLIANCE_MANAGER',
            self::OPPORTUNITY => 'ROLE_RISK_MANAGER',
            default => 'ROLE_ISB',
        };
    }

    /** Default months until an entry is reviewed again. */
    public function reviewMonths(): int
    {
        return match ($this) {
            self::COMMUNICATION, self::IMPROVEMENT => 3,
            default => 12,
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::ISSUE_EXTERNAL, self::ISSUE_INTERNAL => 'nav-process',
            self::OPPORTUNITY => 'ui-lightbulb',
            self::LEGAL_REQUIREMENT => 'compliance-shield',
            self::SCOPE_INTERFACE => 'nav-link',
            self::ROLE => 'nav-users',
            self::COMPETENCE => 'nav-stars',
            self::COMMUNICATION => 'nav-chat-quote',
            self::IMPROVEMENT => 'util-arrow-up',
            self::MONITORING_SOURCE => 'nav-activity',
        };
    }

    /** Translation key of a field label; fields whose meaning differs per register get their own. */
    public function labelKey(string $field): string
    {
        return in_array($field, ['category', 'rating', 'reference', 'effectiveFrom'], true)
            ? sprintf('register.type.%s.field.%s', $this->value, $field)
            : sprintf('register.field.%s', $field);
    }
}
