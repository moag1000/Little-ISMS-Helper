<?php

declare(strict_types=1);

namespace App\AlvaHint\FormRule;

use App\AlvaHint\AlvaFormHint;
use App\AlvaHint\AlvaHintFormRuleInterface;
use App\Entity\User;

/**
 * P-19 Form-Step-Inline-Hint — BCP form: no crisis-team assigned.
 *
 * Fires while the user is editing a BusinessContinuityPlan and neither
 * `responseTeamMembers` (per-plan JSON struct, ISO 22301 §8.5.3) nor
 * `crisisTeams` (cross-plan reusable Many-to-Many to CrisisTeam entity)
 * is set. A BC-plan without a team is effectively unusable — nobody
 * authorised to call the activation criteria.
 *
 * The check intentionally accepts ANY non-empty signal in either field
 * (mirrors the BC plan dual-source design where `responseTeamMembers`
 * is per-plan and `crisisTeams` is reusable across plans — Junior-ISB
 * audit C2-06).
 *
 * Pre-save heads-up only (tier `warning`).
 */
final class BcpNoCrisisTeamInlineRule implements AlvaHintFormRuleInterface
{
    public function key(): string
    {
        return 'bcp.form.no_team_assigned';
    }

    public function entityType(): string
    {
        return 'business_continuity_plan';
    }

    public function requiredModules(): array
    {
        return ['bcm'];
    }

    public function requiredRoles(): array
    {
        return [];
    }

    public function supports(array $payload, User $user): bool
    {
        $hasResponseTeam = $this->fieldHasValue($payload, 'responseTeamMembers');
        $hasCrisisTeams = $this->fieldHasValue($payload, 'crisisTeams');

        // Form needs to expose at least ONE of the two fields before we
        // surface the hint — otherwise we'd shout at users on a half-
        // loaded `new` route.
        if (!array_key_exists('responseTeamMembers', $payload) && !array_key_exists('crisisTeams', $payload)) {
            return false;
        }

        return !$hasResponseTeam && !$hasCrisisTeams;
    }

    public function evaluate(array $payload, User $user): AlvaFormHint
    {
        return new AlvaFormHint(
            key: $this->key(),
            field: 'crisisTeams',
            tier: 'warning',
            titleTranslationKey: 'alva_hint.form.bcp_no_crisis_team.title',
            bodyTranslationKey: 'alva_hint.form.bcp_no_crisis_team.body',
            translationDomain: 'alva',
            mood: 'warning',
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function fieldHasValue(array $payload, string $key): bool
    {
        if (!array_key_exists($key, $payload)) {
            return false;
        }
        $value = $payload[$key];

        if ($value === null || $value === '' || $value === false) {
            return false;
        }
        if (is_array($value)) {
            return $value !== [];
        }
        // Strings: a literal "0" / "[]" / "{}" is considered empty here.
        if (is_string($value)) {
            $trimmed = trim($value);
            return $trimmed !== '' && $trimmed !== '[]' && $trimmed !== '{}' && $trimmed !== '0';
        }

        return true;
    }
}
