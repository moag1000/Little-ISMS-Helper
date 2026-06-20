<?php

declare(strict_types=1);

namespace App\Service\Compliance;

use App\Command\SeedBsiIso27001MappingsCommand;
use App\Command\SeedGdprIso27001MappingsCommand;
use App\Repository\ComplianceMappingRepository;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * MappingSeedService — runs the idempotent cross-framework mapping seed
 * commands from a non-CLI context (e.g. an async job).
 *
 * The seed *logic* already lives in the canonical, well-tested console
 * commands ({@see SeedGdprIso27001MappingsCommand},
 * {@see SeedBsiIso27001MappingsCommand}). Re-implementing the ~50 hand-curated
 * mapping rows here would duplicate a regulatory source of truth and risk
 * drift, so this service deliberately takes the lower-risk *command-invocation*
 * route: it runs each command via a buffered {@see Application} (mirroring
 * {@see \App\Service\Setup\SetupConsoleRunner}) and derives the seeded count
 * from a before/after delta on the mapping repository — no fragile stdout
 * parsing, no console coupling leaking out of the public surface.
 *
 * Each command is idempotent (skips existing source→target pairs), so this
 * service is safe to re-run: a second invocation seeds 0 new mappings.
 *
 * A pair is only seeded when BOTH frameworks are loaded — the caller passes
 * the set of currently-loaded framework codes and this service maps each known
 * pair to its seed command, skipping pairs whose frameworks are absent.
 */
class MappingSeedService
{
    /**
     * Registry of known cross-framework seed pairs.
     *
     * @var list<array{source: string, target: string, command: string}>
     */
    private const PAIRS = [
        [
            'source' => 'BSI_GRUNDSCHUTZ',
            'target' => 'ISO27001',
            'command' => 'app:seed-bsi-iso27001-mappings',
        ],
        [
            'source' => 'GDPR',
            'target' => 'ISO27001',
            'command' => 'app:seed-gdpr-iso27001-mappings',
        ],
    ];

    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly ComplianceMappingRepository $mappingRepository,
    ) {
    }

    /**
     * Seed a single cross-framework pair, identified by source/target code.
     *
     * Returns {seeded, skipped}: `seeded` = newly created mappings,
     * `skipped` = pre-existing mappings left untouched (idempotency proof on
     * re-run). Returns {0, 0} with `ran=false` when the pair is unknown or one
     * of the two frameworks is not loaded.
     *
     * @param list<string> $loadedCodes Framework codes currently loaded
     * @return array{seeded: int, skipped: int, ran: bool}
     */
    public function seedPair(string $sourceCode, string $targetCode, array $loadedCodes): array
    {
        foreach (self::PAIRS as $pair) {
            if ($pair['source'] === $sourceCode && $pair['target'] === $targetCode) {
                if (!in_array($sourceCode, $loadedCodes, true) || !in_array($targetCode, $loadedCodes, true)) {
                    return ['seeded' => 0, 'skipped' => 0, 'ran' => false];
                }

                return $this->runSeedCommand($pair['command']) + ['ran' => true];
            }
        }

        return ['seeded' => 0, 'skipped' => 0, 'ran' => false];
    }

    /**
     * Seed every known pair whose BOTH frameworks are present in $loadedCodes.
     *
     * @param list<string> $loadedCodes Framework codes currently loaded
     * @return array{seeded: int, skipped: int, pairs: list<array{source: string, target: string, seeded: int, skipped: int}>}
     */
    public function seedAvailablePairs(array $loadedCodes): array
    {
        $totalSeeded = 0;
        $totalSkipped = 0;
        $pairResults = [];

        foreach (self::PAIRS as $pair) {
            if (!in_array($pair['source'], $loadedCodes, true) || !in_array($pair['target'], $loadedCodes, true)) {
                continue;
            }

            $result = $this->runSeedCommand($pair['command']);
            $totalSeeded += $result['seeded'];
            $totalSkipped += $result['skipped'];
            $pairResults[] = [
                'source' => $pair['source'],
                'target' => $pair['target'],
                'seeded' => $result['seeded'],
                'skipped' => $result['skipped'],
            ];
        }

        return [
            'seeded' => $totalSeeded,
            'skipped' => $totalSkipped,
            'pairs' => $pairResults,
        ];
    }

    /**
     * Run an idempotent seed command via a buffered console Application and
     * derive the seeded count from a before/after mapping-count delta.
     *
     * `seeded` is the authoritative net-new count (delta). `skipped` is the
     * count of mappings that already existed before this run (i.e. the
     * pre-existing total) — on a re-run this equals the full pair total and
     * `seeded` is 0, which is the idempotency guarantee callers assert on.
     *
     * @return array{seeded: int, skipped: int}
     */
    private function runSeedCommand(string $commandName): array
    {
        $before = $this->countMappings();

        $application = new Application($this->kernel);
        $application->setAutoExit(false);

        $application->run(
            new ArrayInput([
                'command' => $commandName,
                '--no-interaction' => true,
            ]),
            new BufferedOutput(),
        );

        $after = $this->countMappings();
        $seeded = max(0, $after - $before);

        return ['seeded' => $seeded, 'skipped' => $before];
    }

    private function countMappings(): int
    {
        return $this->mappingRepository->count([]);
    }
}
