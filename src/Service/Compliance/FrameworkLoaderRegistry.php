<?php

declare(strict_types=1);

namespace App\Service\Compliance;

use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Registry that collects all FrameworkLoaderInterface implementations via
 * tagged_iterator and dispatches load/run calls by framework code.
 *
 * Injected by ComplianceFrameworkLoaderService and ComplianceLoaderFixerService
 * instead of the individual Load*RequirementsCommand objects.
 */
final class FrameworkLoaderRegistry
{
    /** @var array<string, FrameworkLoaderInterface> */
    private array $loaders = [];

    /**
     * @param iterable<FrameworkLoaderInterface> $loaders
     */
    public function __construct(iterable $loaders)
    {
        foreach ($loaders as $loader) {
            $this->loaders[$loader->getFrameworkCode()] = $loader;
        }
    }

    /**
     * Returns true when a loader is registered for the given code.
     */
    public function has(string $code): bool
    {
        return isset($this->loaders[$code]);
    }

    /**
     * Load/re-seed a framework's requirements.
     *
     * @return int Command exit code: 0 = success, non-zero = failure
     *
     * @throws \InvalidArgumentException when no loader is registered for $code
     */
    public function load(string $code, bool $update = false, ?SymfonyStyle $io = null): int
    {
        if (!$this->has($code)) {
            throw new \InvalidArgumentException(sprintf('No framework loader registered for code "%s".', $code));
        }

        return $this->loaders[$code]->loadRequirements($update, $io);
    }

    /**
     * @return string[]
     */
    public function knownCodes(): array
    {
        return array_keys($this->loaders);
    }
}
