<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Resolvability gate: every TISAX cross-framework mapping's source/target control
 * id must resolve to a LIVE ComplianceRequirement. Fails (exit 1) on any dangling
 * id. This closes the re-fork risk an ISA minor revision (6.0.x → 6.1) introduces:
 * a renumbered control silently dangles a mapping and the whole reuse graph rots.
 *
 * Run after every library re-key / ISA bump; wire into deploy/CI-with-DB.
 */
#[AsCommand(
    name: 'app:tisax:verify-mappings',
    description: 'Verify every TISAX cross-framework mapping id resolves to a live control (exit 1 on dangling)',
)]
final class TisaxVerifyMappingsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dir = $this->projectDir . '/fixtures/library/mappings';
        $files = glob($dir . '/*tisax*.yaml') ?: [];
        $files = array_filter($files, static fn (string $f): bool => !str_contains($f, 'crosswalk') && !str_contains($f, 'anchors'));

        if ($files === []) {
            $io->warning('No TISAX mapping fixtures found.');
            return Command::SUCCESS;
        }

        $idCache = [];   // framework code → set<requirement id>
        $totalDangling = 0;
        $rows = [];

        foreach ($files as $file) {
            $doc = Yaml::parseFile($file);
            $lib = $doc['library'] ?? [];
            $srcCode = (string) ($lib['source_framework'] ?? '');
            $tgtCode = (string) ($lib['target_framework'] ?? '');
            $maps = $doc['mappings'] ?? [];
            if ($maps === [] || $srcCode === '' || $tgtCode === '') {
                continue;
            }

            $srcIds = $idCache[$srcCode] ??= $this->idsOf($srcCode);
            $tgtIds = $idCache[$tgtCode] ??= $this->idsOf($tgtCode);
            // A missing framework (not loaded in this DB) is reported but not a
            // dangling error — only ids absent from a PRESENT framework count.
            $srcPresent = $srcIds !== null;
            $tgtPresent = $tgtIds !== null;

            $dangling = 0;
            $danglingIds = [];
            foreach ($maps as $m) {
                $source = trim((string) ($m['source'] ?? ''));
                if ($srcPresent && $source !== '' && !isset($srcIds[$source])) {
                    ++$dangling;
                    $danglingIds[] = "src:{$source}";
                }
                foreach ((array) ($m['targets'] ?? [$m['target'] ?? null]) as $t) {
                    $t = trim((string) $t);
                    if ($tgtPresent && $t !== '' && !isset($tgtIds[$t])) {
                        ++$dangling;
                        $danglingIds[] = "tgt:{$t}";
                    }
                }
            }
            if ($danglingIds !== []) {
                $io->writeln(sprintf('  <comment>%s</comment>: %s', basename($file), implode(', ', $danglingIds)));
            }
            $totalDangling += $dangling;
            $rows[] = [
                basename($file),
                $srcPresent ? 'yes' : 'NOT LOADED',
                $tgtPresent ? 'yes' : 'NOT LOADED',
                count($maps),
                $dangling > 0 ? "<error>{$dangling}</error>" : '0',
            ];
        }

        $io->table(['Mapping', 'Source FW', 'Target FW', 'Entries', 'Dangling'], $rows);

        if ($totalDangling > 0) {
            $io->error(sprintf('%d dangling mapping id(s) — a control was renumbered/removed. Re-key the mappings.', $totalDangling));
            return Command::FAILURE;
        }
        $io->success('All TISAX mapping ids resolve to live controls (frameworks present).');
        return Command::SUCCESS;
    }

    /**
     * @return array<string, true>|null  null when the framework is not loaded.
     */
    private function idsOf(string $code): ?array
    {
        $framework = $this->em->getRepository(ComplianceFramework::class)->findOneBy(['code' => $code]);
        if ($framework === null) {
            return null;
        }
        $ids = [];
        foreach ($this->em->getRepository(ComplianceRequirement::class)
            ->createQueryBuilder('r')->select('r.requirementId')
            ->where('r.framework = :fw')->setParameter('fw', $framework)
            ->getQuery()->getArrayResult() as $row) {
            $ids[trim((string) $row['requirementId'])] = true;
        }
        return $ids;
    }
}
