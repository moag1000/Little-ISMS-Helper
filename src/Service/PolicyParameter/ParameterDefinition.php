<?php

declare(strict_types=1);

namespace App\Service\PolicyParameter;

/**
 * Immutable definition of one policy parameter, loaded from
 * config/policy_parameters/*.yaml. See design spec 2026-05-30.
 */
final readonly class ParameterDefinition
{
    /**
     * @param list<string>          $allowed
     * @param list<string>          $isoClauses
     * @param array<string, mixed>  $frameworkConstraints
     * @param array<string, mixed>  $templateSlot
     * @param array<string, string> $labels
     */
    public function __construct(
        public string $key,
        public string $category,
        public string $type,
        public mixed $default,
        public array $allowed = [],
        public array $isoClauses = [],
        public array $frameworkConstraints = [],
        public array $templateSlot = [],
        public string $wizardStep = 'governance_controls',
        public array $labels = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(string $key, array $data): self
    {
        return new self(
            key: $key,
            category: (string) ($data['category'] ?? 'uncategorised'),
            type: (string) ($data['type'] ?? 'string'),
            default: $data['default'] ?? null,
            allowed: array_values($data['allowed'] ?? []),
            isoClauses: array_values($data['iso_clauses'] ?? []),
            frameworkConstraints: $data['framework_constraints'] ?? [],
            templateSlot: $data['template_slot'] ?? [],
            wizardStep: (string) ($data['wizard_step'] ?? 'governance_controls'),
            labels: $data['labels'] ?? [],
        );
    }

    public function frameworkMin(string $framework): mixed
    {
        return $this->frameworkConstraints[$framework]['min'] ?? null;
    }

    public function frameworkAuthority(string $framework): ?string
    {
        $authority = $this->frameworkConstraints[$framework]['authority'] ?? null;

        return $authority === null ? null : (string) $authority;
    }
}
