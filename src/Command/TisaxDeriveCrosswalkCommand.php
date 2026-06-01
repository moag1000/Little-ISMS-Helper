<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ComplianceRequirement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Derive legacy-TISAX-id → canonical VDA-ISA control-number (1.1.1) candidates
 * via the shared ISO 27001:2022 anchor.
 *
 * The legacy seed schemes (ACC-/INF-/CMP-/…) each assert an ISO 27001 target
 * (see fixtures/library/mappings/tisax-legacy-iso-anchors.yaml — extracted from
 * SeedTisaxIso27001MappingsCommand). The user-imported VDA-ISA controls each
 * carry their own ISO 27001:2022 anchors in dataSourceMapping.iso27001. Joining
 * on that shared anchor yields a CANDIDATE legacy → 1.1.1 mapping.
 *
 * This is a PROPOSAL, not a certification: a unique single-candidate match is
 * high-confidence ('derived'), a multi-candidate match is 'ambiguous' (needs a
 * human pick), and no-anchor-match stays 'needs_human_review'. The official
 * VDA-ISA catalogue is never embedded — the canonical numbers live only in the
 * tenant's imported data, read at runtime. Output is written for review; nothing
 * is auto-applied to the crosswalk unless --write-confirmed is passed.
 */
#[AsCommand(
    name: 'app:tisax:derive-crosswalk',
    description: 'Propose legacy-id → 1.1.1 crosswalk targets via the ISO 27001:2022 anchor bridge',
)]
final class TisaxDeriveCrosswalkCommand extends Command
{
    /** Frameworks whose imported controls carry the canonical numbering + ISO anchors. */
    private const FRAMEWORK_CODES = ['TISAX', 'TISAX-VDA-ISA-6'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('write-confirmed', null, InputOption::VALUE_NONE,
            'Append the unique high-confidence (derived) targets to the crosswalk fixture.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $anchorsPath = $this->projectDir . '/fixtures/library/mappings/tisax-legacy-iso-anchors.yaml';
        if (!is_file($anchorsPath)) {
            $io->error('Anchors fixture missing: ' . $anchorsPath);
            return Command::FAILURE;
        }
        /** @var array<string, list<string>> $anchors */
        $anchors = (array) (Yaml::parseFile($anchorsPath)['anchors'] ?? []);

        $iso2controls = $this->buildIsoIndex();
        if ($iso2controls === []) {
            $io->warning('No imported VDA-ISA controls with ISO:2022 anchors found — '
                . 'import a workbook first so the bridge has a target side.');
            return Command::FAILURE;
        }

        $derived = [];
        $ambiguous = [];
        $unresolved = [];

        foreach ($anchors as $legacyId => $isos) {
            $domain = $this->domainOf((string) $legacyId);
            $candidates = [];
            foreach ((array) $isos as $iso) {
                foreach ($iso2controls[$iso] ?? [] as $controlId) {
                    $candidates[$controlId] = true;
                }
            }
            $candidates = array_keys($candidates);
            sort($candidates, SORT_NATURAL);

            if ($candidates === []) {
                $unresolved[(string) $legacyId] = ['domain' => $domain, 'iso' => $isos];
            } elseif (count($candidates) === 1) {
                $derived[(string) $legacyId] = ['target' => $candidates[0], 'domain' => $domain, 'via' => $isos, 'confidence' => 'derived'];
            } else {
                $ambiguous[(string) $legacyId] = ['candidates' => $candidates, 'domain' => $domain, 'via' => $isos];
            }
        }

        $proposal = [
            'generated_by' => 'app:tisax:derive-crosswalk',
            'method' => 'legacy-id -> ISO 27001:2022 anchor -> canonical 1.1.1',
            'counts' => [
                'total' => count($anchors),
                'derived_unique' => count($derived),
                'ambiguous' => count($ambiguous),
                'unresolved' => count($unresolved),
            ],
            'derived' => $derived,
            'ambiguous' => $ambiguous,
            'needs_human_review' => $unresolved,
        ];

        $outPath = $this->projectDir . '/var/tisax-crosswalk-proposal.yaml';
        @mkdir(\dirname($outPath), 0o775, true);
        file_put_contents($outPath, Yaml::dump($proposal, 6, 2));

        $io->success(sprintf(
            'Bridge: %d unique-derived, %d ambiguous, %d unresolved (of %d). Proposal: %s',
            count($derived), count($ambiguous), count($unresolved), count($anchors), $outPath,
        ));
        $io->note('Unique-derived are high-confidence CANDIDATES for human confirmation, not certified targets.');

        if ($input->getOption('write-confirmed')) {
            $this->appendConfirmedToCrosswalk($derived, $io);
        }

        return Command::SUCCESS;
    }

    /**
     * Build ISO 27001:2022 anchor → list<canonical control id> from imported controls.
     *
     * @return array<string, list<string>>
     */
    private function buildIsoIndex(): array
    {
        $rows = $this->em->getRepository(ComplianceRequirement::class)
            ->createQueryBuilder('cr')
            ->select('cr.requirementId', 'cr.dataSourceMapping')
            ->join('cr.framework', 'f')
            ->where('f.code IN (:codes)')
            ->andWhere("cr.requirementId LIKE '%.%.%'")
            ->setParameter('codes', self::FRAMEWORK_CODES)
            ->getQuery()
            ->getArrayResult();

        $index = [];
        foreach ($rows as $row) {
            $id = (string) $row['requirementId'];
            $iso = (string) (($row['dataSourceMapping'] ?? [])['iso27001'] ?? '');
            if ($iso === '' || preg_match('/2022:\s*([^\n]+)/u', $iso, $m) !== 1) {
                continue;
            }
            foreach (preg_split('/[,\s]+/', trim($m[1])) ?: [] as $anchor) {
                $anchor = trim($anchor);
                if (preg_match('/^A\.\d/', $anchor) === 1) {
                    $index[$anchor][] = $id;
                }
            }
        }

        return $index;
    }

    /** Extract the domain prefix (e.g. "ACC-1.1" → "ACC", "TISAX-CONF-SC-1.1" → "TISAX-CONF-SC"). */
    private function domainOf(string $legacyId): string
    {
        return preg_match('/^(.*)-\d/', $legacyId, $m) === 1 ? $m[1] : $legacyId;
    }

    /**
     * Write the derived targets to a LOCAL, git-ignored file under var/ — never
     * into the committed crosswalk fixture. The derived canonical 1.1.1 numbers
     * come from the tenant's licensed VDA-ISA workbook; embedding them in a
     * version-controlled fixture would ship copyrighted catalogue numbering. The
     * local file is the assessor's working artifact for confirming the bridge
     * proposals before they are (manually, per-tenant) trusted.
     *
     * @param array<string, array{target: string, domain: string, via: mixed, confidence: string}> $derived
     */
    private function appendConfirmedToCrosswalk(array $derived, SymfonyStyle $io): void
    {
        $path = $this->projectDir . '/var/tisax-crosswalk-confirmed.local.yaml';
        @mkdir(\dirname($path), 0o775, true);
        $doc = [
            'note' => 'LOCAL ONLY — derived from the tenant licensed VDA-ISA workbook. '
                . 'Do NOT commit (copyrighted catalogue numbering).',
            'generated_by' => 'app:tisax:derive-crosswalk --write-confirmed',
            'iso_anchor_derived' => array_map(
                static fn (array $d): array => ['target' => $d['target'], 'domain' => $d['domain'], 'confidence' => 'derived', 'via' => $d['via']],
                $derived,
            ),
        ];
        file_put_contents($path, Yaml::dump($doc, 6, 2));
        $io->success(sprintf('Wrote %d derived targets to LOCAL %s (NOT the committed fixture).', count($derived), $path));
    }
}
