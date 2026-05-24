<?php

declare(strict_types=1);

namespace App\AlvaHint\FormRule;

use App\AlvaHint\AlvaFormHint;
use App\AlvaHint\AlvaHintFormRuleInterface;
use App\Entity\AuditFinding;
use App\Entity\User;

/**
 * P-19 Form-Step-Inline-Hint — AuditFinding form: major nonconformity
 * implies CAPA obligation.
 *
 * Fires when the user classifies the finding as `major_nc`
 * (Major-Nonconformity). ISO 27001 Cl. 10.1.1 ("Nonconformity and
 * corrective action") obliges the organization to react to a
 * nonconformity, evaluate the need for action, and implement any
 * action needed — in audit practice this means a CAPA record linked
 * back to the finding before closure. The form anchor surfaces this
 * obligation up-front so the user can plan the CAPA before saving the
 * finding.
 *
 * Pre-save heads-up only (tier `warning`).
 */
final class AuditFindingMajorNcRequiresCapaInlineRule implements AlvaHintFormRuleInterface
{
    public function key(): string
    {
        return 'audit_finding.form.major_nc_requires_capa';
    }

    public function entityType(): string
    {
        return 'audit_finding';
    }

    public function requiredModules(): array
    {
        return ['audits'];
    }

    public function requiredRoles(): array
    {
        return [];
    }

    public function supports(array $payload, User $user): bool
    {
        $type = $payload['type'] ?? null;
        if (!is_string($type) || $type === '') {
            return false;
        }
        return $type === AuditFinding::TYPE_MAJOR_NC;
    }

    public function evaluate(array $payload, User $user): AlvaFormHint
    {
        return new AlvaFormHint(
            key: $this->key(),
            field: 'type',
            tier: 'warning',
            titleTranslationKey: 'alva_hint.form.audit_finding_major_nc_requires_capa.title',
            bodyTranslationKey: 'alva_hint.form.audit_finding_major_nc_requires_capa.body',
            translationDomain: 'alva',
            mood: 'warning',
        );
    }
}
