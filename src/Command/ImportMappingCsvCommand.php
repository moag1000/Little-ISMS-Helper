<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceMappingRepository;
use App\Repository\ComplianceRequirementRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Import curated cross-framework mappings from CSV files under fixtures/mappings/.
 *
 * Usage:
 *   bin/console app:mappings:import-csv fixtures/mappings/public/nis2_iso27001_v1.csv
 *   bin/console app:mappings:import-csv fixtures/mappings/public/nis2_iso27001_v1.csv --dry-run
 */
#[AsCommand(
    name: 'app:mappings:import-csv',
    description: 'Import curated cross-framework mappings from a CSV file',
)]
final class ImportMappingCsvCommand extends Command
{
    private const REQUIRED_HEADERS = [
        'source_framework',
        'source_requirement_id',
        'target_framework',
        'target_requirement_id',
        'mapping_percentage',
        'mapping_type',
        'confidence',
        'bidirectional',
        'rationale',
        'source_catalog',
        'validated_at',
        'validated_by',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly ComplianceRequirementRepository $requirementRepository,
        private readonly ComplianceMappingRepository $mappingRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Path to the CSV file')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Parse and validate only, no DB writes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $file = (string) $input->getArgument('file');
        $dryRun = (bool) $input->getOption('dry-run');

        if (!is_file($file) || !is_readable($file)) {
            $io->error(sprintf('File not readable: %s', $file));
            return Command::FAILURE;
        }

        $handle = fopen($file, 'r');
        if ($handle === false) {
            $io->error('Could not open file.');
            return Command::FAILURE;
        }

        $headers = null;
        $lineNo = 0;
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];

        $frameworkCache = [];
        $requirementCache = [];

        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $lineNo++;
            if ($row === [null] || $row === [] || ($row[0] ?? '') === '') {
                continue;
            }
            if (str_starts_with((string) $row[0], '#')) {
                continue;
            }
            if ($headers === null) {
                $headers = $row;
                foreach (self::REQUIRED_HEADERS as $h) {
                    if (!in_array($h, $headers, true)) {
                        $io->error(sprintf('Missing required header: %s', $h));
                        fclose($handle);
                        return Command::FAILURE;
                    }
                }
                continue;
            }

            if (count($row) !== count($headers)) {
                $errors[] = sprintf('Line %d: column count mismatch', $lineNo);
                $skipped++;
                continue;
            }

            $data = array_combine($headers, $row);

            $sourceFramework = $this->getFramework($data['source_framework'], $frameworkCache);
            $targetFramework = $this->getFramework($data['target_framework'], $frameworkCache);
            if ($sourceFramework === null || $targetFramework === null) {
                $errors[] = sprintf('Line %d: framework not found (%s -> %s)', $lineNo, $data['source_framework'], $data['target_framework']);
                $skipped++;
                continue;
            }

            $sourceReq = $this->getRequirement($sourceFramework, $data['source_requirement_id'], $requirementCache);
            $targetReq = $this->getRequirement($targetFramework, $data['target_requirement_id'], $requirementCache);
            if ($sourceReq === null || $targetReq === null) {
                $missing = [];
                if ($sourceReq === null) {
                    $missing[] = sprintf('source=%s:%s', $sourceFramework->getCode(), $data['source_requirement_id']);
                }
                if ($targetReq === null) {
                    $missing[] = sprintf('target=%s:%s', $targetFramework->getCode(), $data['target_requirement_id']);
                }
                $errors[] = sprintf('Line %d: %s', $lineNo, implode(', ', $missing));
                $skipped++;
                continue;
            }

            $existing = $this->mappingRepository->findOneBy([
                'sourceRequirement' => $sourceReq,
                'targetRequirement' => $targetReq,
                'source' => $data['source_catalog'],
            ]);

            if ($existing instanceof ComplianceMapping) {
                $existing->setValidUntil(new DateTimeImmutable());
                $updated++;
            }

            $isBidirectional = strtolower($data['bidirectional']) === 'true';
            $mapping = (new ComplianceMapping())
                ->setSourceRequirement($sourceReq)
                ->setTargetRequirement($targetReq)
                ->setMappingPercentage((int) $data['mapping_percentage'])
                ->setMappingType($data['mapping_type'])
                ->setConfidence($data['confidence'])
                ->setBidirectional($isBidirectional)
                ->setMappingRationale($data['rationale'])
                ->setSource($data['source_catalog'])
                ->setVersion(($existing?->getVersion() ?? 0) + 1)
                ->setValidFrom(new DateTimeImmutable());

            if (!$dryRun) {
                $this->entityManager->persist($mapping);
            }
            $created++;

            // WS-1 inheritance service queries mappings by targetRequirement.
            // For bidirectional mappings we materialize the reverse direction
            // too so inheritance works regardless of which framework the
            // curator chose as "source" in the CSV.
            if ($isBidirectional) {
                $reverse = (new ComplianceMapping())
                    ->setSourceRequirement($targetReq)
                    ->setTargetRequirement($sourceReq)
                    ->setMappingPercentage((int) $data['mapping_percentage'])
                    ->setMappingType($data['mapping_type'])
                    ->setConfidence($data['confidence'])
                    ->setBidirectional(true)
                    ->setMappingRationale($data['rationale'])
                    ->setSource($data['source_catalog'] . '_reverse')
                    ->setVersion(1)
                    ->setValidFrom(new DateTimeImmutable());

                if (!$dryRun) {
                    $this->entityManager->persist($reverse);
                }
                $created++;
            }
        }
        fclose($handle);

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $io->section('Summary');
        $io->writeln(sprintf('Imported: <info>%d</info>, Superseded: <comment>%d</comment>, Skipped: <error>%d</error>', $created, $updated, $skipped));

        if ($errors !== []) {
            $io->warning(sprintf('%d issue(s) encountered:', count($errors)));
            foreach (array_slice($errors, 0, 20) as $err) {
                $io->writeln(' - ' . $err);
            }
        }

        if ($dryRun) {
            $io->note('Dry-run: no DB writes performed.');
        } else {
            $io->success('Mappings imported with versioning (old versions kept, valid_until stamped).');
        }

        return $errors === [] ? Command::SUCCESS : Command::FAILURE;
    }

    private function getFramework(string $code, array &$cache): ?ComplianceFramework
    {
        if (!array_key_exists($code, $cache)) {
            $cache[$code] = $this->frameworkRepository->findOneBy(['code' => $code]);
        }
        return $cache[$code];
    }

    private function getRequirement(
        ComplianceFramework $framework,
        string $requirementId,
        array &$cache,
    ): ?ComplianceRequirement {
        $key = $framework->getCode() . '::' . $requirementId;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        foreach ($this->candidateIds($framework, $requirementId) as $candidate) {
            $hit = $this->requirementRepository->findOneBy([
                'complianceFramework' => $framework,
                'requirementId' => $candidate,
            ]);
            if ($hit instanceof ComplianceRequirement) {
                $cache[$key] = $hit;
                return $hit;
            }
        }

        // Prefix fallback: e.g. CSV has "Art.5" but loader stores "DORA-5.1".
        // Pick the first requirement whose id starts with the normalised prefix.
        foreach ($this->prefixCandidates($framework, $requirementId) as $prefix) {
            $qb = $this->requirementRepository->createQueryBuilder('r')
                ->andWhere('r.framework = :f')
                ->andWhere('r.requirementId LIKE :p')
                ->setParameter('f', $framework)
                ->setParameter('p', $prefix . '%')
                ->orderBy('r.requirementId', 'ASC')
                ->setMaxResults(1);
            $hit = $qb->getQuery()->getOneOrNullResult();
            if ($hit instanceof ComplianceRequirement) {
                $cache[$key] = $hit;
                return $hit;
            }
        }

        $cache[$key] = null;
        return null;
    }

    /**
     * @return list<string>
     */
    private function prefixCandidates(ComplianceFramework $framework, string $id): array
    {
        $stripped = preg_replace('/^(Art\.|§)/i', '', $id) ?? $id;
        $strippedAnnex = preg_replace('/^A\./i', '', $stripped) ?? $stripped;
        $candidates = [$stripped . '.', $strippedAnnex . '.'];
        foreach ($this->prefixesFor($framework->getCode()) as $prefix) {
            foreach ([$stripped, $strippedAnnex] as $core) {
                $candidates[] = $prefix . '-' . $core . '.';
                $candidates[] = $prefix . '-' . $core;
            }
        }
        return array_values(array_unique(array_filter($candidates)));
    }

    /**
     * Tolerate common ID-schema variants between curator CSVs and loader fixtures:
     *   Art.21.2.a <-> NIS2-21.2.a
     *   Art.5      <-> DORA-5.1 (best-effort, picks the first sub-item)
     *   A.5.1      <-> 5.1
     *   §26        <-> BDSG-26
     *
     * @return list<string>
     */
    private function candidateIds(ComplianceFramework $framework, string $id): array
    {
        $code = $framework->getCode();
        $candidates = [$id];

        $stripped = preg_replace('/^(Art\.|§)/i', '', $id) ?? $id;
        $strippedAnnex = preg_replace('/^A\./i', '', $stripped) ?? $stripped;
        foreach ([$stripped, $strippedAnnex] as $variant) {
            if ($variant !== $id && $variant !== null) {
                $candidates[] = $variant;
            }
        }

        foreach ([$id, $stripped, $strippedAnnex] as $core) {
            if ($core === null || $core === '') {
                continue;
            }
            foreach ($this->prefixesFor($code) as $prefix) {
                $candidates[] = $prefix . '-' . $core;
                $candidates[] = $prefix . '_' . $core;
            }
        }

        foreach ($this->prefixesFor($code) as $prefix) {
            if (preg_match('/^' . preg_quote($prefix, '/') . '[-_]/', $id)) {
                $suffix = substr($id, strlen($prefix) + 1);
                $candidates[] = 'Art.' . $suffix;
                $candidates[] = $suffix;
            }
        }

        return array_values(array_unique(array_filter($candidates)));
    }

    /**
     * Known prefix variants per framework code. Loader conventions are inconsistent
     * (e.g. ISO27701 stores "27701-5.2.1", EU-AI-ACT stores "AIACT-1") so we carry
     * a small alias map rather than guess generically.
     *
     * @return list<string>
     */
    private function prefixesFor(string $code): array
    {
        $map = [
            'ISO27701' => ['27701', 'ISO27701'],
            'ISO27001' => ['ISO27001'],
            'ISO27005' => ['27005', 'ISO27005'],
            'ISO-22301' => ['ISO22301', 'ISO-22301', '22301'],
            'EU-AI-ACT' => ['AIACT', 'EUAIACT', 'EU-AI-ACT'],
            'BSI-C5-2026' => ['C5-2026', 'C52026', 'BSI-C5-2026'],
            'BSI-C5' => ['C5', 'BSI-C5'],
            'CIS-CONTROLS' => ['CIS', 'CIS-CONTROLS'],
            'TKG-2024' => ['TKG', 'TKG-2024'],
            'KRITIS' => ['KRITIS'],
            'NIS2UMSUCG' => ['NIS2UMSUCG', 'NIS2UmsuCG'],
            'NIST-CSF' => ['NIST-CSF', 'NISTCSF'],
        ];

        if (isset($map[$code])) {
            return $map[$code];
        }
        return array_values(array_unique([$code, str_replace(['-', '_'], '', $code)]));
    }
}
