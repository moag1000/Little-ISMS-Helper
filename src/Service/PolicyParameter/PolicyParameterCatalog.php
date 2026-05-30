<?php

declare(strict_types=1);

namespace App\Service\PolicyParameter;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads + caches the policy-parameter catalog from
 * %kernel.project_dir%/config/policy_parameters/*.yaml.
 */
final class PolicyParameterCatalog
{
    /** @var array<string, ParameterDefinition>|null */
    private ?array $cache = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/policy_parameters')]
        private readonly string $catalogDir,
    ) {
    }

    public function get(string $key): ParameterDefinition
    {
        $all = $this->all();
        if (!isset($all[$key])) {
            throw new \InvalidArgumentException(sprintf('Unknown policy parameter "%s".', $key));
        }

        return $all[$key];
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->all());
    }

    /** @return array<string, ParameterDefinition> */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $defs = [];
        foreach (glob($this->catalogDir . '/*.yaml') ?: [] as $file) {
            /** @var array<string, array<string, mixed>> $parsed */
            $parsed = Yaml::parseFile($file) ?? [];
            foreach ($parsed as $key => $data) {
                $defs[$key] = ParameterDefinition::fromArray($key, $data);
            }
        }

        return $this->cache = $defs;
    }
}
