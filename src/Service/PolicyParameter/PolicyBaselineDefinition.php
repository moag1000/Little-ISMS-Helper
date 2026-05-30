<?php

declare(strict_types=1);

namespace App\Service\PolicyParameter;

/**
 * One industry baseline: a named preset of policy-parameter values + frameworks
 * + mandatory topics + org-context flags, loaded from config/policy_baselines/*.yaml.
 * Named PolicyBaseline* to avoid collision with App\Entity\IndustryBaseline.
 */
final readonly class PolicyBaselineDefinition
{
    /**
     * @param list<string>          $frameworks
     * @param list<string>          $mandatoryTopics
     * @param array<string, mixed>  $flags
     * @param array<string, array{value: mixed, authority?: string, source?: string}> $parameterPresets
     * @param array<string, string> $labels
     */
    public function __construct(
        public string $sector,
        public array $frameworks = [],
        public array $mandatoryTopics = [],
        public ?string $regulator = null,
        public array $flags = [],
        public array $parameterPresets = [],
        public array $labels = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            sector: (string) ($data['sector'] ?? ''),
            frameworks: array_values($data['frameworks'] ?? []),
            mandatoryTopics: array_values($data['mandatory_topics'] ?? []),
            regulator: isset($data['regulator']) ? (string) $data['regulator'] : null,
            flags: $data['flags'] ?? [],
            parameterPresets: $data['parameter_presets'] ?? [],
            labels: $data['labels'] ?? [],
        );
    }

    /** @return array<string, mixed> param-key => value (authority/source stripped) */
    public function presetValues(): array
    {
        $out = [];
        foreach ($this->parameterPresets as $key => $preset) {
            $out[$key] = $preset['value'] ?? null;
        }

        return $out;
    }

    public function presetAuthority(string $key): ?string
    {
        $authority = $this->parameterPresets[$key]['authority'] ?? null;

        return $authority === null ? null : (string) $authority;
    }
}
