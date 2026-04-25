<?php

namespace App\Command;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Service\MappingLibraryLoader;
use App\Service\MappingQualityScoreService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

/**
 * Smoke-Test für Mapping-Library:
 * 1. Liest alle fixtures/library/mappings/*.yaml
 * 2. Erstellt Framework- und Requirement-Stubs für alle referenzierten IDs
 *    (idempotent — bestehende werden nicht angefasst)
 * 3. Importiert die Mapping-Library
 * 4. Berechnet MQS-Scores und gibt Übersicht aus
 *
 * Ist explizit dev/test-orientiert und nicht für Produktion gedacht —
 * im Realbetrieb kommen Frameworks und Requirements aus offiziellen
 * Loadern, nicht aus Stubs.
 */
#[AsCommand(
    name: 'app:mapping:library:smoke-test',
    description: 'End-to-end Smoke-Test: Framework-Stubs + Library-Import + MQS-Übersicht.',
)]
class MappingLibrarySmokeTestCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly ComplianceRequirementRepository $requirementRepository,
        private readonly MappingLibraryLoader $loader,
        private readonly MappingQualityScoreService $mqsService,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('skip-stubs', null, InputOption::VALUE_NONE, 'Stub-Erstellung überspringen.');
        $this->addOption('cleanup', null, InputOption::VALUE_NONE, 'Stub-Frameworks und Mappings entfernen (NUR development!).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('cleanup')) {
            return $this->cleanup($io);
        }

        $libraryDir = $this->projectDir . '/fixtures/library/mappings';
        $files = glob($libraryDir . '/*.yaml') ?: [];

        if (empty($files)) {
            $io->warning('Keine Library-Files gefunden.');
            return Command::SUCCESS;
        }

        $io->title(sprintf('Mapping-Library Smoke-Test (%d Files)', count($files)));

        // 1. Alle YAMLs einlesen → Frameworks + Requirements sammeln
        $frameworks = [];  // code => display-name
        $requirements = []; // code => [requirementId => true]
        foreach ($files as $f) {
            $payload = Yaml::parseFile($f);
            $sourceCode = $payload['library']['source_framework'];
            $targetCode = $payload['library']['target_framework'];
            $frameworks[$sourceCode] ??= $sourceCode;
            $frameworks[$targetCode] ??= $targetCode;
            foreach ($payload['mappings'] ?? [] as $entry) {
                $requirements[$sourceCode][$entry['source']] = true;
                $requirements[$targetCode][$entry['target']] = true;
            }
        }

        // 2. Stubs erstellen
        if (!$input->getOption('skip-stubs')) {
            $io->section('Framework-Stubs');
            foreach ($frameworks as $code => $name) {
                $existing = $this->frameworkRepository->findOneBy(['code' => $code]);
                if ($existing === null) {
                    $fw = new ComplianceFramework();
                    $fw->setCode($code);
                    $fw->setName($code);
                    $fw->setDescription('Stub for mapping-library smoke-test.');
                    $fw->setVersion('1.0');
                    $fw->setApplicableIndustry('all');
                    $fw->setRegulatoryBody('Stub');
                    $fw->setScopeDescription('Stub for mapping-library smoke-test.');
                    $this->entityManager->persist($fw);
                    $io->writeln(sprintf('  + Framework <fg=green>%s</> (new)', $code));
                } else {
                    $io->writeln(sprintf('  · Framework %s (exists)', $code));
                }
            }
            $this->entityManager->flush();

            $io->section('Requirement-Stubs');
            $stubCount = 0;
            foreach ($requirements as $fwCode => $reqIds) {
                $fw = $this->frameworkRepository->findOneBy(['code' => $fwCode]);
                foreach (array_keys($reqIds) as $reqId) {
                    $existing = $this->requirementRepository->findOneBy([
                        'complianceFramework' => $fw,
                        'requirementId' => $reqId,
                    ]);
                    if ($existing === null) {
                        $req = new ComplianceRequirement();
                        $req->setFramework($fw);
                        $req->setRequirementId($reqId);
                        $req->setTitle($reqId . ' (stub)');
                        $req->setDescription('Stub for mapping-library smoke-test.');
                        $req->setCategory('stub');
                        $req->setPriority('medium');
                        $this->entityManager->persist($req);
                        $stubCount++;
                    }
                }
            }
            $this->entityManager->flush();
            $io->writeln(sprintf('  + <fg=green>%d</> Requirements created', $stubCount));
        }

        // 3. Library importieren
        $io->section('Library Import');
        $totalImported = 0;
        $totalUpdated = 0;
        $totalSkipped = 0;
        $errors = 0;
        foreach ($files as $f) {
            $result = $this->loader->load($f);
            $name = basename($f);
            if (!$result['success']) {
                $io->writeln(sprintf('  <fg=red>✗</> %s — %s', $name, implode('; ', $result['errors'])));
                $errors++;
                continue;
            }
            $totalImported += $result['imported'];
            $totalUpdated += $result['updated'];
            $totalSkipped += $result['skipped'];
            $io->writeln(sprintf(
                '  <fg=green>✓</> %s — imported %d / updated %d / skipped %d',
                $name,
                $result['imported'],
                $result['updated'],
                $result['skipped'],
            ));
            foreach ($result['warnings'] as $w) {
                $io->writeln(sprintf('    <fg=yellow>!</> %s', $w));
            }
        }

        // 4. MQS-Übersicht
        $io->section('MQS-Score Summary');
        $em = $this->entityManager;
        $rows = $em->createQuery(
            "SELECT
                IDENTITY(m.sourceRequirement) as src,
                IDENTITY(m.targetRequirement) as tgt,
                m.lifecycleState as lifecycle,
                AVG(m.qualityScore) as avg_mqs,
                COUNT(m.id) as n,
                m.source as src_tag
             FROM App\\Entity\\ComplianceMapping m
             WHERE m.lifecycleState != 'deprecated'
             GROUP BY m.source, m.lifecycleState
             ORDER BY avg_mqs DESC"
        )->getArrayResult();

        if (empty($rows)) {
            $io->warning('Keine Mappings importiert — MQS-Übersicht leer.');
        } else {
            $tableRows = [];
            foreach ($rows as $r) {
                $tableRows[] = [
                    $r['src_tag'],
                    $r['lifecycle'],
                    $r['n'],
                    sprintf('%.1f', (float) $r['avg_mqs']),
                ];
            }
            $io->table(['Library-ID', 'Lifecycle', 'Pairs', 'Ø MQS'], $tableRows);
        }

        $io->writeln(sprintf('Total: imported %d, updated %d, skipped %d, errors %d',
            $totalImported, $totalUpdated, $totalSkipped, $errors));

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Entfernt Stub-Frameworks (Description LIKE 'Stub for...') + zugehörige
     * Requirements + Mappings. Nur für Development gedacht — Echte Frameworks
     * mit anderem Description-Wert bleiben unberührt.
     */
    private function cleanup(SymfonyStyle $io): int
    {
        $io->title('Stub-Cleanup (Development-Only)');

        $stubFrameworks = $this->frameworkRepository->createQueryBuilder('f')
            ->where("f.description LIKE 'Stub for mapping-library smoke-test%'")
            ->getQuery()->getResult();

        if (empty($stubFrameworks)) {
            $io->success('Keine Stub-Frameworks gefunden — nichts zu tun.');
            return Command::SUCCESS;
        }

        $io->writeln(sprintf('  Lösche <fg=red>%d</> Stub-Frameworks (cascade auf Requirements + Mappings):', count($stubFrameworks)));
        foreach ($stubFrameworks as $fw) {
            $io->writeln(sprintf('    - %s', $fw->getCode()));
            $this->entityManager->remove($fw);
        }
        $this->entityManager->flush();

        $io->success('Cleanup abgeschlossen.');
        return Command::SUCCESS;
    }
}
