<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ComplianceFramework;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Loader-Fixer: re-runs framework loaders idempotently to pick up requirements
 * that were added in newer loader versions after the initial load.
 *
 * Loaders with an idempotent findOrCreate flow (BSI/GDPR/ISO27701/...) will add
 * only the missing deltas. Loaders that still skip when requirements exist will
 * report 0 added (still safe to run).
 */
class ComplianceLoaderFixerService
{
    /**
     * Ordered map of framework code → label + invoker.
     *
     * @var array<string, array{label: string, invoker: callable}>
     */
    private readonly array $map;

    public function __construct(
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly ComplianceRequirementRepository $requirementRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly LoggerInterface $logger,
        \App\Command\LoadIso27001RequirementsCommand $iso27001,
        \App\Command\LoadIso27005RequirementsCommand $iso27005,
        \App\Command\LoadIso27701RequirementsCommand $iso27701,
        \App\Command\LoadIso22301RequirementsCommand $iso22301,
        \App\Command\LoadNis2RequirementsCommand $nis2,
        \App\Command\LoadNis2UmsuCGRequirementsCommand $nis2UmsuCg,
        \App\Command\LoadDoraRequirementsCommand $dora,
        \App\Command\LoadTisaxRequirementsCommand $tisax,
        \App\Command\LoadBsiItGrundschutzRequirementsCommand $bsiGrundschutz,
        \App\Command\LoadC5RequirementsCommand $c5,
        \App\Command\LoadC52026RequirementsCommand $c52026,
        \App\Command\LoadGdprRequirementsCommand $gdpr,
        \App\Command\LoadBdsgRequirementsCommand $bdsg,
        \App\Command\LoadNistCsfRequirementsCommand $nistCsf,
        \App\Command\LoadSoc2RequirementsCommand $soc2,
        \App\Command\LoadCisControlsRequirementsCommand $cis,
        \App\Command\LoadEuAiActRequirementsCommand $euAiAct,
        \App\Command\LoadKritisRequirementsCommand $kritis,
        \App\Command\LoadKritisHealthRequirementsCommand $kritisHealth,
        \App\Command\LoadTkgRequirementsCommand $tkg,
        \App\Command\LoadDigavRequirementsCommand $digav,
        \App\Command\LoadGxpRequirementsCommand $gxp,
    ) {
        $this->map = [
            'ISO27001'        => ['label' => 'ISO/IEC 27001:2022',                 'invoker' => fn(SymfonyStyle $io) => $iso27001($io)],
            'ISO27005'        => ['label' => 'ISO/IEC 27005:2022',                 'invoker' => fn(SymfonyStyle $io) => $iso27005($io)],
            'ISO27701'        => ['label' => 'ISO/IEC 27701:2019',                 'invoker' => fn(SymfonyStyle $io) => $iso27701($io)],
            'ISO-22301'       => ['label' => 'ISO 22301:2019',                     'invoker' => fn(SymfonyStyle $io) => $iso22301($io)],
            'NIS2'            => ['label' => 'NIS2 Directive (2022/2555)',         'invoker' => fn(SymfonyStyle $io) => $nis2($io)],
            'NIS2UMSUCG'      => ['label' => 'NIS2UmsuCG (DE)',                    'invoker' => fn(SymfonyStyle $io) => $nis2UmsuCg($io)],
            'DORA'            => ['label' => 'EU-DORA (2022/2554)',                'invoker' => fn(SymfonyStyle $io) => $dora($io)],
            'TISAX'           => ['label' => 'TISAX (VDA ISA 6.0.4)',              'invoker' => fn(SymfonyStyle $io) => $tisax($io)],
            'BSI_GRUNDSCHUTZ' => ['label' => 'BSI IT-Grundschutz',                 'invoker' => fn(SymfonyStyle $io) => $bsiGrundschutz($io)],
            'BSI-C5'          => ['label' => 'BSI C5:2020',                        'invoker' => fn(SymfonyStyle $io) => $c5($io)],
            'BSI-C5-2026'     => ['label' => 'BSI C5:2026',                        'invoker' => fn(SymfonyStyle $io) => $c52026($io)],
            'GDPR'            => ['label' => 'GDPR (2016/679)',                    'invoker' => fn(SymfonyStyle $io) => $gdpr($io)],
            'BDSG'            => ['label' => 'BDSG 2018/2024',                     'invoker' => fn(SymfonyStyle $io) => $bdsg($io)],
            'NIST-CSF'        => ['label' => 'NIST CSF 2.0',                       'invoker' => fn(SymfonyStyle $io) => $nistCsf($io)],
            'SOC2'            => ['label' => 'SOC 2 (2017 rev. 2022)',             'invoker' => fn(SymfonyStyle $io) => $soc2($io)],
            'CIS-CONTROLS'    => ['label' => 'CIS Controls v8.1',                  'invoker' => fn(SymfonyStyle $io) => $cis($io)],
            'EU-AI-ACT'       => ['label' => 'EU AI Act (2024/1689)',              'invoker' => fn(SymfonyStyle $io) => $euAiAct($io)],
            'KRITIS'          => ['label' => 'KRITIS (§8a/§8b)',                   'invoker' => fn(SymfonyStyle $io) => $kritis($io)],
            'KRITIS-HEALTH'   => ['label' => 'KRITIS Health (KHPatSiG)',           'invoker' => fn(SymfonyStyle $io) => $kritisHealth($io)],
            'TKG-2024'        => ['label' => 'TKG 2024',                           'invoker' => fn(SymfonyStyle $io) => $tkg($io)],
            'DIGAV'           => ['label' => 'DiGAV',                              'invoker' => fn(SymfonyStyle $io) => $digav($io)],
            'GXP'             => ['label' => 'GxP',                                'invoker' => fn(SymfonyStyle $io) => $gxp($io)],
        ];
    }

    /**
     * @return list<array{code:string, label:string, loaded_count:int, framework_id:?int, exists:bool}>
     */
    public function getStatus(): array
    {
        $rows = [];
        foreach ($this->map as $code => $entry) {
            $framework = $this->frameworkRepository->findOneBy(['code' => $code]);
            $loaded = $framework !== null
                ? $this->requirementRepository->count(['complianceFramework' => $framework])
                : 0;
            $rows[] = [
                'code' => $code,
                'label' => $entry['label'],
                'loaded_count' => $loaded,
                'framework_id' => $framework?->getId(),
                'exists' => $framework !== null,
            ];
        }
        return $rows;
    }

    public function knownFrameworks(): array
    {
        return array_keys($this->map);
    }

    /**
     * @return array{added:int, output:string, success:bool, code:string, before:int, after:int}
     */
    public function fixOne(string $code, string $actorDescription = 'system'): array
    {
        if (!isset($this->map[$code])) {
            return [
                'added' => 0,
                'output' => sprintf('Unknown framework code: %s', $code),
                'success' => false,
                'code' => $code,
                'before' => 0,
                'after' => 0,
            ];
        }

        $before = $this->countRequirementsFor($code);
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $success = true;
        try {
            $rc = ($this->map[$code]['invoker'])($io);
            if (is_int($rc) && $rc !== 0) {
                $success = false;
            }
        } catch (\Throwable $e) {
            $this->logger->error('Loader fixer failed', ['code' => $code, 'error' => $e->getMessage()]);
            $success = false;
            $output->writeln('EXCEPTION: ' . $e->getMessage());
        }

        // Clear EM to force a fresh count after the inner flush.
        $this->entityManager->clear(ComplianceFramework::class);
        $after = $this->countRequirementsFor($code);
        $added = max(0, $after - $before);

        $this->auditLogger->logCustom(
            'compliance.loader_fixer.run',
            'ComplianceFramework',
            null,
            ['loaded_before' => $before],
            ['loaded_after' => $after, 'added' => $added, 'success' => $success],
            sprintf('Loader-Fixer: %s — added %d (total %d, %s)', $code, $added, $after, $actorDescription),
        );

        return [
            'added' => $added,
            'output' => $output->fetch(),
            'success' => $success,
            'code' => $code,
            'before' => $before,
            'after' => $after,
        ];
    }

    /**
     * @return array<string, array{added:int, output:string, success:bool, code:string, before:int, after:int}>
     */
    public function fixAll(string $actorDescription = 'system'): array
    {
        $results = [];
        foreach (array_keys($this->map) as $code) {
            $results[$code] = $this->fixOne($code, $actorDescription);
        }
        return $results;
    }

    private function countRequirementsFor(string $code): int
    {
        $framework = $this->frameworkRepository->findOneBy(['code' => $code]);
        return $framework === null
            ? 0
            : $this->requirementRepository->count(['complianceFramework' => $framework]);
    }
}
