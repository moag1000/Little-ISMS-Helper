<?php

declare(strict_types=1);

namespace App\Service\AlvaHint;

use App\AlvaHint\AlvaFormHint;
use App\AlvaHint\AlvaHintFormRuleInterface;
use App\Entity\User;
use App\Service\ModuleConfigurationService;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Aggregates {@see AlvaHintFormRuleInterface} services and returns the
 * subset of inline hints that apply to the given form payload snapshot.
 *
 * Mirrors {@see \App\AlvaHint\AlvaHintService} for show-page hints, but
 * with three deliberate differences:
 *
 * 1. Multiple hints CAN fire per request (one per form field at most) —
 *    show-page rules cap at 1 per page, form rules cap at 1 per
 *    `field` anchor to avoid stacking conflicting messages on the same
 *    input.
 * 2. No dismissal lookup — form hints are ephemeral.
 * 3. No render-count telemetry — form hints fire on every keystroke (or
 *    debounced equivalent), so per-render counting would overwhelm the
 *    table. Telemetry, if added later, must be aggregated client-side
 *    or via the existing dismiss endpoint.
 *
 * Foundation pattern P-19: Form-Step-Inline-Hint.
 */
final class AlvaHintFormEvaluator
{
    /** @var array<int, string>|null */
    private ?array $activeModulesCache = null;

    /**
     * @param iterable<AlvaHintFormRuleInterface> $rules
     */
    public function __construct(
        private readonly Security $security,
        private readonly ModuleConfigurationService $moduleConfiguration,
        private readonly iterable $rules = [],
    ) {
    }

    /**
     * Evaluate all rules for the given entity type + payload, return the
     * collected hints. At most one hint per form-field anchor wins —
     * deterministic by rule iteration order.
     *
     * @param array<string, mixed> $payload
     * @return list<AlvaFormHint>
     */
    public function evaluate(string $entityType, array $payload): array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return [];
        }

        $activeModules = $this->getActiveModules();

        $hintsByField = [];
        foreach ($this->rules as $rule) {
            if ($rule->entityType() !== $entityType) {
                continue;
            }

            $required = $rule->requiredModules();
            if ($required !== [] && array_diff($required, $activeModules) !== []) {
                continue;
            }

            foreach ($rule->requiredRoles() as $role) {
                if (!$this->security->isGranted($role)) {
                    continue 2;
                }
            }

            if (!$rule->supports($payload, $user)) {
                continue;
            }

            $hint = $rule->evaluate($payload, $user);

            // One hint per field — first match wins. Rule registration
            // order in services.yaml therefore acts as a coarse priority.
            if (!isset($hintsByField[$hint->field])) {
                $hintsByField[$hint->field] = $hint;
            }
        }

        return array_values($hintsByField);
    }

    /**
     * @return array<int, string>
     */
    private function getActiveModules(): array
    {
        return $this->activeModulesCache ??= $this->moduleConfiguration->getActiveModules();
    }
}
