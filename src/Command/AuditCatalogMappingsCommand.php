<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ComplianceFramework;
use App\Repository\ComplianceFrameworkRepository;
use App\Service\Catalog\FrameworkCode;
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

        // Build a per-framework list of all produced requirement IDs for prefix matching
        // (same as auditCsv — needed by resolveRequirementId()).
        /** @var array<string, list<string>> $producedByCodeList */
        $producedByCodeList = [];
        foreach (array_keys($produced) as $pair) {
            [$code, $rid] = explode('::', $pair, 2);
            $producedByCodeList[$code][] = $rid;
        }

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
                // Normalise source(s): accept scalar `source:` OR list `sources:`.
                // A mapping entry counts as dangling-source only if NONE of its IDs resolve.
                $srcIds = $this->normaliseIds($m, 'source', 'sources');
                if ($srcCode === null) {
                    // Framework itself unresolved — every entry dangling by definition.
                    $danglingSrc[] = implode(',', $srcIds) ?: '(empty)';
                } elseif ($srcIds !== []) {
                    $allDangling = true;
                    foreach ($srcIds as $sid) {
                        if ($this->resolveRequirementId($srcCode, $sid, $produced, $producedByCodeList)) {
                            $allDangling = false;
                            break;
                        }
                    }
                    if ($allDangling) {
                        $danglingSrc[] = implode(',', $srcIds);
                    }
                }

                // Normalise target(s): accept scalar `target:` OR list `targets:`.
                // Empty list (targets: []) means "no mapping by design" — skip silently.
                // An entry is dangling-target only if it has IDs and NONE resolve.
                $tgtIds = $this->normaliseIds($m, 'target', 'targets');
                if ($tgtIds === []) {
                    // Intentionally unmapped (e.g. PP/DP controls with no ISO anchor).
                    continue;
                }
                if ($tgtCode === null) {
                    $danglingTgt[] = implode(',', $tgtIds);
                } else {
                    $allDangling = true;
                    foreach ($tgtIds as $tid) {
                        if ($this->resolveRequirementId($tgtCode, $tid, $produced, $producedByCodeList)) {
                            $allDangling = false;
                            break;
                        }
                    }
                    if ($allDangling) {
                        $danglingTgt[] = implode(',', $tgtIds);
                    }
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
     * Normalise a mapping entry's source or target IDs into a flat list.
     *
     * Accepts both the scalar form (`source: 'A.5.1'`) and the list form
     * (`targets: ['A.5.1', 'A.5.2']`).  Empty-list entries (`targets: []`)
     * return an empty array, which the caller interprets as "no mapping by design".
     *
     * @param array<string,mixed> $entry     Single mapping entry from the YAML mappings array
     * @param string              $scalarKey Singular key (e.g. 'source', 'target')
     * @param string              $listKey   Plural key  (e.g. 'sources', 'targets')
     * @return list<string>
     */
    private function normaliseIds(array $entry, string $scalarKey, string $listKey): array
    {
        // Prefer the list key if present (even if empty — intentional no-mapping).
        if (array_key_exists($listKey, $entry)) {
            $raw = $entry[$listKey];
            if (!is_array($raw)) {
                $raw = $raw !== null ? [(string) $raw] : [];
            }
            return array_values(array_filter(array_map('strval', $raw), static fn (string $v) => $v !== ''));
        }

        // Fall back to scalar key.
        if (array_key_exists($scalarKey, $entry)) {
            $v = (string) ($entry[$scalarKey] ?? '');
            return $v !== '' ? [$v] : [];
        }

        return [];
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

        // Build a per-framework list of all produced requirement IDs for prefix matching.
        // Keys: canonicalCode => list<requirementId>
        /** @var array<string, list<string>> $producedByCodeList */
        $producedByCodeList = [];
        foreach (array_keys($produced) as $pair) {
            [$code, $rid] = explode('::', $pair, 2);
            $producedByCodeList[$code][] = $rid;
        }

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
                if ($srcCode === null || !$this->resolveRequirementId($srcCode, $s, $produced, $producedByCodeList)) {
                    $danglingSrc[] = $srcFw . ':' . $s;
                }
                if ($tgtCode === null || !$this->resolveRequirementId($tgtCode, $t, $produced, $producedByCodeList)) {
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
     * Mirror of ImportMappingCsvCommand::getRequirement() tolerance logic — checks
     * whether a CSV requirement ID resolves to any produced (code, requirementId) pair.
     *
     * Resolution order:
     *   1. Exact match
     *   2. Candidate variants (Art./§ stripped, framework-prefix added, Annex-A stripped)
     *   3. Prefix match: any produced ID that starts with a candidate prefix
     *
     * @param array<string,true>           $produced         All produced code::rid pairs
     * @param array<string, list<string>>  $producedByCode   code => list<rid>
     */
    private function resolveRequirementId(
        string $code,
        string $id,
        array $produced,
        array $producedByCode,
    ): bool {
        // 1. Exact match
        if (isset($produced[$code . '::' . $id])) {
            return true;
        }

        // 2. Candidate variants (strip Art./§, add framework prefixes, strip A.)
        foreach ($this->candidateIds($code, $id) as $candidate) {
            if (isset($produced[$code . '::' . $candidate])) {
                return true;
            }
        }

        // 3. Prefix fallback: any produced ID starting with a candidate prefix
        $rids = $producedByCode[$code] ?? [];
        if ($rids === []) {
            return false;
        }
        foreach ($this->prefixCandidates($code, $id) as $prefix) {
            foreach ($rids as $rid) {
                if (str_starts_with($rid, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function candidateIds(string $code, string $id): array
    {
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
     * @return list<string>
     */
    private function prefixCandidates(string $code, string $id): array
    {
        $stripped = preg_replace('/^(Art\.|§)/i', '', $id) ?? $id;
        $strippedAnnex = preg_replace('/^A\./i', '', $stripped) ?? $stripped;
        $candidates = [$stripped . '.', $strippedAnnex . '.'];
        foreach ($this->prefixesFor($code) as $prefix) {
            foreach ([$stripped, $strippedAnnex] as $core) {
                $candidates[] = $prefix . '-' . $core . '.';
                $candidates[] = $prefix . '-' . $core;
            }
        }
        return array_values(array_unique(array_filter($candidates)));
    }

    /**
     * Known prefix variants per framework code — mirrors ImportMappingCsvCommand::prefixesFor().
     *
     * @return list<string>
     */
    private function prefixesFor(string $code): array
    {
        $map = [
            'DORA'        => ['DORA'],
            'ISO27701'    => ['27701', 'ISO27701'],
            'ISO27001'    => ['ISO27001'],
            'ISO27005'    => ['27005', 'ISO27005'],
            'ISO-22301'   => ['ISO22301', 'ISO-22301', '22301'],
            'EU-AI-ACT'   => ['AIACT', 'EUAIACT', 'EU-AI-ACT'],
            'BSI-C5-2026' => ['C5-2026', 'C52026', 'BSI-C5-2026'],
            'BSI-C5'      => ['C5', 'BSI-C5'],
            'CIS-CONTROLS' => ['CIS', 'CIS-CONTROLS'],
            'TKG-2024'    => ['TKG', 'TKG-2024'],
            'KRITIS'      => ['KRITIS'],
            'NIS2UMSUCG'  => ['NIS2UMSUCG', 'NIS2UmsuCG'],
            'NIST-CSF-2.0' => ['NIST-CSF', 'NISTCSF', 'NIST-CSF-2.0'],
            'BDSG'        => ['BDSG'],
            'NIS2'        => ['NIS2'],
        ];

        if (isset($map[$code])) {
            return $map[$code];
        }
        return array_values(array_unique([$code, str_replace(['-', '_'], '', $code)]));
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
        while (($line = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
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
        // Check canonical aliases (e.g. NIST-CSF -> NIST-CSF-2.0)
        $canonical = FrameworkCode::canonicalize($libId);
        if ($canonical !== null && isset($byCode[$canonical])) {
            return $canonical;
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
