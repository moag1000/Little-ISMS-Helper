<?php

declare(strict_types=1);

namespace App\Service\PolicyParameter;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads industry baselines from %kernel.project_dir%/config/policy_baselines/*.yaml.
 */
final class PolicyBaselineCatalog
{
    /** @var array<string, PolicyBaselineDefinition>|null */
    private ?array $cache = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/policy_baselines')]
        private readonly string $baselineDir,
    ) {
    }

    public function get(string $sector): PolicyBaselineDefinition
    {
        $all = $this->all();
        if (!isset($all[$sector])) {
            throw new \InvalidArgumentException(sprintf('Unknown policy baseline sector "%s".', $sector));
        }

        return $all[$sector];
    }

    /** @return list<string> */
    public function sectors(): array
    {
        return array_keys($this->all());
    }

    /** @return array<string, PolicyBaselineDefinition> */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $defs = [];
        foreach (glob($this->baselineDir . '/*.yaml') ?: [] as $file) {
            /** @var array<string, mixed> $data */
            $data = Yaml::parseFile($file) ?? [];
            $def = PolicyBaselineDefinition::fromArray($data);
            $defs[$def->sector] = $def;
        }

        return $this->cache = $defs;
    }
}
