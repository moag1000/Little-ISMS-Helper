<?php

declare(strict_types=1);

namespace App\Service\ComplianceWizard;

use App\Entity\Tenant;

/**
 * The wizard checks that read a norm's own records — registers, top-management
 * decisions, training participation, statutory reports — as one entry point
 * for the wizard, plus short definitions for the category providers.
 *
 * Name and description keys follow `wizard.check.<key>` / `<key>_desc`.
 */
final class RecordCheck
{
    public function __construct(
        private readonly RegisterCoverageCheck $registers,
        private readonly ManagementDecisionCheck $decisions,
        private readonly TrainingParticipationCheck $participation,
        private readonly ReportingDutyCheck $reporting,
    ) {
    }

    /**
     * @param array<string, mixed> $check
     * @return array{score: float, details: array<string, mixed>, gap: array<string, mixed>|null}|null null for another check type
     */
    public function check(array $check, ?Tenant $tenant): ?array
    {
        return match ($check['type'] ?? null) {
            'register_coverage' => $this->registers->check($check, $tenant),
            'management_decision' => $this->decisions->check($check, $tenant),
            'training_participation' => $this->participation->check($check, $tenant),
            'reporting_duty' => $this->reporting->check($check, $tenant),
            default => null,
        };
    }

    /**
     * A clause answered by norm registers ({@see \App\Enum\RegisterType} values).
     *
     * @param list<string> $registers
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public static function register(string $key, array $registers, string $clause, string $priority = 'high', array $extra = []): array
    {
        return self::definition($key, $clause, $priority, 'app_register_index', [
            'type' => 'register_coverage',
            'registers' => $registers,
        ] + $extra);
    }

    /**
     * A clause answered by top-management decisions ({@see \App\Enum\ManagementDecisionType} values).
     *
     * @param list<string> $types
     * @return array<string, mixed>
     */
    public static function decision(string $key, array $types, string $clause, string $priority = 'critical'): array
    {
        return self::definition($key, $clause, $priority, 'app_management_decision_index', [
            'type' => 'management_decision',
            'decision_types' => $types,
        ]);
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private static function definition(string $key, string $clause, string $priority, string $route, array $config): array
    {
        return [
            'name' => 'wizard.check.' . $key,
            'description' => 'wizard.check.' . $key . '_desc',
            'priority' => $priority,
            'clause' => $clause,
            'route' => $route,
        ] + $config;
    }
}
