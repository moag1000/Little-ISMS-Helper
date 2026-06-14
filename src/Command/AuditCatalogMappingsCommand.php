<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ComplianceFramework;
use App\Repository\ComplianceFrameworkRepository;
use App\Service\ComplianceFrameworkLoaderService;
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
 * app:audit-catalog-mappings — Phase-0 dangling-mapping inventory (Task 0.3).
 *
 * Loads every UI-wired framework (ComplianceFrameworkLoaderService registry) into
 * the CURRENT database, records the exact (frameworkCode, requirementId) pairs the
 * wired loaders actually produce, then checks the three mapping sources against
 * that produced set:
 *   - fixtures/library/mappings/*.yaml   (57 curated YAML crosswalks)
 *   - fixtures/mappings/public/*.csv     (22 CSV crosswalks)
 *
 * Reports, per source and framework-pair:
 *   - unresolved framework references (library framework id ≠ any loaded code)
 *   - dangling source/target requirementIds (id not produced by the wired loader)
 *
 * RUN AGAINST A SCRATCH DB ONLY — this command loads catalog data (mutates).
 * Example (local):
 *   DATABASE_URL="mysql://banda:***@127.0.0.1:3306/little_isms_catalog_audit?serverVersion=8.0&charset=utf8mb4" \
 *     php bin/console doctrine:schema:create
 *   DATABASE_URL="...little_isms_catalog_audit..." php bin/console app:audit-catalog-mappings
 *
 * Output: var/audit/catalog_mappings_inventory.json + console summary.
 * This is the DYNAMIC complement to the static check_compliance_catalog.py gate.
 */
#[AsCommand(
    name: 'app:audit-catalog-mappings',
    description: 'Inventory dangling cross-framework mappings vs. what wired loaders actually produce (Phase-0, scratch DB only).'
)]
final class AuditCatalogMappingsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ComplianceFrameworkLoaderService $loaderService,
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('skip-load', null, InputOption::VALUE_NONE, 'Assume frameworks already loaded; skip the load phase.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$input->getOption('skip-load')) {
            $this->loadAllFrameworks($io);
        }

        [$produced, $producedByCode, $normToCode] = $this->buildProducedSet();
        $io->section('Produced catalog');
        $io->writeln(sprintf(
            '%d frameworks with requirements, %d (code, requirementId) pairs produced by wired loaders.',
            count($producedByCode),
            count($produced),
        ));

        $yamlReport = $this->auditYaml($produced, $producedByCode, $normToCode);
        $csvReport = $this->auditCsv($produced, $producedByCode, $normToCode);

        $report = [
            'produced_frameworks' => array_keys($producedByCode),
            'produced_pair_count' => count($produced),
            'yaml_library' => $yamlReport,
            'csv_public' => $csvReport,
        ];

        $this->renderSummary($io, $yamlReport, 'YAML library (fixtures/library/mappings)');
        $this->renderSummary($io, $csvReport, 'CSV public (fixtures/mappings/public)');

        $outPath = $this->projectDir . '/var/audit/catalog_mappings_inventory.json';
        @mkdir(dirname($outPath), 0775, true);
        file_put_contents($outPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $io->success('Inventory written to ' . $outPath);

        return Command::SUCCESS;
    }

    private function loadAllFrameworks(SymfonyStyle $io): void
    {
        $io->section('Loading all wired frameworks');
        foreach ($this->loaderService->getAvailableFrameworks() as $f) {
            $code = (string) $f['code'];
            // Pre-create the framework row so "Full" loaders that require a
            // pre-existing row (ISO42001/27017/27018/EU-CRA/PCI-DSS) succeed on a
            // fresh schema:create DB without their alignment migration.
            if (!$this->frameworkRepository->findOneBy(['code' => $code]) instanceof ComplianceFramework) {
                $fw = new ComplianceFramework();
                $fw->setCode($code);
                $fw->setName((string) ($f['name'] ?? $code));
                $fw->setVersion((string) ($f['version'] ?? '1.0'));
                $fw->setApplicableIndustry((string) ($f['industry'] ?? 'all_sectors'));
                $fw->setRegulatoryBody((string) ($f['regulatory_body'] ?? 'n/a'));
                $fw->setMandatory((bool) ($f['mandatory'] ?? false));
                $fw->setRequiredModules((array) ($f['required_modules'] ?? []));
                $this->em->persist($fw);
                $this->em->flush();
            }
            $result = $this->loaderService->loadFramework($code);
            $status = ($result['success'] ?? false) ? 'OK' : ('skip: ' . ($result['message'] ?? '?'));
            $io->writeln(sprintf('  %-16s %s', $code, $status));
        }
    }

    /**
     * @return array{0: array<string,true>, 1: array<string,true>, 2: array<string,string>}
     */
    private function buildProducedSet(): array
    {
        $rows = $this->em->createQuery(
            'SELECT f.code AS code, r.requirementId AS rid
             FROM App\Entity\ComplianceRequirement r JOIN r.framework f'
        )->getArrayResult();

        $produced = [];
        $byCode = [];
        $normToCode = [];
        foreach ($rows as $row) {
            $code = (string) $row['code'];
            $rid = (string) $row['rid'];
            $produced[$code . '::' . $rid] = true;
            $byCode[$code] = true;
            $normToCode[self::normalize($code)] = $code;
        }
        return [$produced, $byCode, $normToCode];
    }

    /**
     * @param array<string,true> $produced
     * @param array<string,true> $byCode
     * @param array<string,string> $normToCode
     */
    private function auditYaml(array $produced, array $byCode, array $normToCode): array
    {
        $dir = $this->projectDir . '/fixtures/library/mappings';
        $files = glob($dir . '/*.yaml') ?: [];
        $out = ['file_count' => count($files), 'files' => [], 'unresolved_frameworks' => []];

        foreach ($files as $file) {
            try {
                $data = Yaml::parseFile($file);
            } catch (\Throwable $e) {
                $out['files'][basename($file)] = ['error' => $e->getMessage()];
                continue;
            }
            $lib = $data['library'] ?? [];
            $srcFw = (string) ($lib['source_framework'] ?? '');
            $tgtFw = (string) ($lib['target_framework'] ?? '');
            $srcCode = $this->resolve($srcFw, $byCode, $normToCode);
            $tgtCode = $this->resolve($tgtFw, $byCode, $normToCode);

            if ($srcCode === null && $srcFw !== '') {
                $out['unresolved_frameworks'][$srcFw] = true;
            }
            if ($tgtCode === null && $tgtFw !== '') {
                $out['unresolved_frameworks'][$tgtFw] = true;
            }

            $mappings = $data['mappings'] ?? [];
            $total = count($mappings);
            $danglingSrc = [];
            $danglingTgt = [];
            foreach ($mappings as $m) {
                $s = (string) ($m['source'] ?? '');
                $t = (string) ($m['target'] ?? '');
                if ($srcCode === null || !isset($produced[$srcCode . '::' . $s])) {
                    $danglingSrc[] = $s;
                }
                if ($tgtCode === null || !isset($produced[$tgtCode . '::' . $t])) {
                    $danglingTgt[] = $t;
                }
            }
            $out['files'][basename($file)] = [
                'source_framework' => $srcFw,
                'target_framework' => $tgtFw,
                'resolved_source' => $srcCode,
                'resolved_target' => $tgtCode,
                'mappings' => $total,
                'dangling_source' => count($danglingSrc),
                'dangling_target' => count($danglingTgt),
                'sample_dangling_source' => array_slice(array_values(array_unique($danglingSrc)), 0, 5),
                'sample_dangling_target' => array_slice(array_values(array_unique($danglingTgt)), 0, 5),
            ];
        }
        $out['unresolved_frameworks'] = array_keys($out['unresolved_frameworks']);
        return $out;
    }

    /**
     * @param array<string,true> $produced
     * @param array<string,true> $byCode
     * @param array<string,string> $normToCode
     */
    private function auditCsv(array $produced, array $byCode, array $normToCode): array
    {
        $dir = $this->projectDir . '/fixtures/mappings/public';
        $files = glob($dir . '/*.csv') ?: [];
        $out = ['file_count' => count($files), 'files' => [], 'unresolved_frameworks' => []];

        foreach ($files as $file) {
            $rows = $this->readCsv($file);
            if ($rows === []) {
                $out['files'][basename($file)] = ['error' => 'no data rows / header'];
                continue;
            }
            $total = 0;
            $danglingSrc = [];
            $danglingTgt = [];
            foreach ($rows as $r) {
                $srcFw = (string) ($r['source_framework'] ?? '');
                $tgtFw = (string) ($r['target_framework'] ?? '');
                $s = (string) ($r['source_requirement_id'] ?? '');
                $t = (string) ($r['target_requirement_id'] ?? '');
                $srcCode = $this->resolve($srcFw, $byCode, $normToCode);
                $tgtCode = $this->resolve($tgtFw, $byCode, $normToCode);
                if ($srcCode === null && $srcFw !== '') {
                    $out['unresolved_frameworks'][$srcFw] = true;
                }
                if ($tgtCode === null && $tgtFw !== '') {
                    $out['unresolved_frameworks'][$tgtFw] = true;
                }
                $total++;
                if ($srcCode === null || !isset($produced[$srcCode . '::' . $s])) {
                    $danglingSrc[] = $srcFw . ':' . $s;
                }
                if ($tgtCode === null || !isset($produced[$tgtCode . '::' . $t])) {
                    $danglingTgt[] = $tgtFw . ':' . $t;
                }
            }
            $out['files'][basename($file)] = [
                'mappings' => $total,
                'dangling_source' => count($danglingSrc),
                'dangling_target' => count($danglingTgt),
                'sample_dangling_source' => array_slice(array_values(array_unique($danglingSrc)), 0, 5),
                'sample_dangling_target' => array_slice(array_values(array_unique($danglingTgt)), 0, 5),
            ];
        }
        $out['unresolved_frameworks'] = array_keys($out['unresolved_frameworks']);
        return $out;
    }

    /**
     * @return list<array<string,string>>
     */
    private function readCsv(string $file): array
    {
        $fh = fopen($file, 'r');
        if ($fh === false) {
            return [];
        }
        $header = null;
        $rows = [];
        while (($line = fgetcsv($fh)) !== false) {
            if ($line === [null]) {
                continue; // blank line — fgetcsv yields [null]
            }
            $first = (string) ($line[0] ?? '');
            if ($header === null) {
                if (str_starts_with(ltrim($first), '#') || $first === '') {
                    continue; // comment / blank before header
                }
                $header = $line;
                continue;
            }
            if (str_starts_with(ltrim($first), '#')) {
                continue;
            }
            $row = [];
            foreach ($header as $i => $col) {
                $row[(string) $col] = (string) ($line[$i] ?? '');
            }
            $rows[] = $row;
        }
        fclose($fh);
        return $rows;
    }

    /**
     * @param array<string,true> $byCode
     * @param array<string,string> $normToCode
     */
    private function resolve(string $libId, array $byCode, array $normToCode): ?string
    {
        if ($libId === '') {
            return null;
        }
        if (isset($byCode[$libId])) {
            return $libId;
        }
        $norm = self::normalize($libId);
        return $normToCode[$norm] ?? null;
    }

    private static function normalize(string $code): string
    {
        return strtoupper(preg_replace('/[-_.\s]/', '', $code) ?? $code);
    }

    private function renderSummary(SymfonyStyle $io, array $report, string $title): void
    {
        $io->section($title);
        $totalMappings = 0;
        $totalDanglingSrc = 0;
        $totalDanglingTgt = 0;
        $fullyDangling = [];
        foreach ($report['files'] as $name => $f) {
            if (isset($f['error'])) {
                continue;
            }
            $totalMappings += $f['mappings'];
            $totalDanglingSrc += $f['dangling_source'];
            $totalDanglingTgt += $f['dangling_target'];
            if ($f['mappings'] > 0 && ($f['dangling_source'] + $f['dangling_target']) >= 2 * $f['mappings']) {
                $fullyDangling[] = $name;
            }
        }
        $io->writeln(sprintf('Files: %d | Mappings: %d', $report['file_count'], $totalMappings));
        $io->writeln(sprintf('Dangling source-IDs: %d | Dangling target-IDs: %d', $totalDanglingSrc, $totalDanglingTgt));
        if ($report['unresolved_frameworks'] !== []) {
            $io->warning('Unresolved framework refs (no loaded framework matches): ' . implode(', ', $report['unresolved_frameworks']));
        }
        if ($fullyDangling !== []) {
            $io->writeln('<comment>Fully-dangling files (both sides unresolved/missing): ' . count($fullyDangling) . '</comment>');
            foreach (array_slice($fullyDangling, 0, 15) as $n) {
                $io->writeln('  - ' . $n);
            }
        }
    }
}
