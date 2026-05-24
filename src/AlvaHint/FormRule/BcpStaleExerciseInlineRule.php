<?php

declare(strict_types=1);

namespace App\AlvaHint\FormRule;

use App\AlvaHint\AlvaFormHint;
use App\AlvaHint\AlvaHintFormRuleInterface;
use App\Entity\User;

/**
 * P-19 Form-Step-Inline-Hint — BCP form: stale or missing exercise.
 *
 * Fires while the user is editing a BusinessContinuityPlan and the
 * `lastTested` field is either empty (plan never exercised) or older
 * than 12 months. ISO 22301 Cl. 8.5 requires a documented exercise
 * cadence — auditors flag plans with no recorded exercise within the
 * last reporting year as a Major-NC candidate.
 *
 * Pre-save heads-up only (tier `warning`).
 */
final class BcpStaleExerciseInlineRule implements AlvaHintFormRuleInterface
{
    /**
     * Conventional ISO 22301 cadence — exercise every 12 months at a
     * minimum (the cl. 8.5 wording is "at planned intervals", but the
     * audit-default in practice is annual).
     */
    private const int STALE_AFTER_DAYS = 365;

    public function key(): string
    {
        return 'bcp.form.last_tested_stale_or_missing';
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
        // Form may not yet expose lastTested (e.g. on `new` route before
        // any plan exists) — only fire when the field is at least present
        // in the payload as an empty or filled value, so the heads-up
        // anchors meaningfully next to the field.
        if (!array_key_exists('lastTested', $payload)) {
            return false;
        }

        $raw = $payload['lastTested'];

        // Empty value on an existing plan = never exercised — fire the hint.
        if ($raw === null || $raw === '' || $raw === false) {
            return true;
        }

        $date = $this->parseDate($raw);
        if ($date === null) {
            // Unparseable date — stay quiet rather than misfire.
            return false;
        }

        $threshold = (new \DateTimeImmutable('today'))->sub(new \DateInterval('P' . self::STALE_AFTER_DAYS . 'D'));
        return $date < $threshold;
    }

    public function evaluate(array $payload, User $user): AlvaFormHint
    {
        return new AlvaFormHint(
            key: $this->key(),
            field: 'lastTested',
            tier: 'warning',
            titleTranslationKey: 'alva_hint.form.bcp_stale_exercise.title',
            bodyTranslationKey: 'alva_hint.form.bcp_stale_exercise.body',
            translationDomain: 'alva',
            mood: 'warning',
        );
    }

    private function parseDate(mixed $raw): ?\DateTimeImmutable
    {
        if ($raw instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($raw);
        }
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }
}
