<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Repository\ComplianceFrameworkRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Populates the BSI-Grundschutz Standard- and Kern-Absicherung variant frameworks
 * with the BSI 200-2 process steps. The actual control catalogue (Bausteine
 * with hundreds of requirements) is shared with the base BSI-GRUNDSCHUTZ
 * framework. The variants differ in scope and process depth, not in catalogue
 * content, so the per-variant requirement set documents the procedural steps.
 */
#[AsCommand(
    name: 'app:load-bsi-grundschutz-variants',
    description: 'Load BSI IT-Grundschutz Standard- and Kern-Absicherung process steps (BSI 200-2) as ComplianceRequirement rows.'
)]
final class LoadBsiGrundschutzVariantsCommand extends Command
{
    /** @var array<string, array<string, string>> code => [reqId => title] */
    private const STEPS = [
        'BSI-GRUNDSCHUTZ-STANDARD' => [
            'BSI-200-2.1'  => 'Initiierung des Sicherheitsprozesses',
            'BSI-200-2.2'  => 'Sicherheitsleitlinie',
            'BSI-200-2.3'  => 'Aufbau der Sicherheitsorganisation',
            'BSI-200-2.4'  => 'Bereitstellung der erforderlichen Ressourcen',
            'BSI-200-2.5'  => 'Strukturanalyse',
            'BSI-200-2.6'  => 'Schutzbedarfsfeststellung',
            'BSI-200-2.7'  => 'Modellierung nach IT-Grundschutz (Standard-Bausteine)',
            'BSI-200-2.8'  => 'IT-Grundschutz-Check (Basis- und Standard-Anforderungen)',
            'BSI-200-2.9'  => 'Risikoanalyse für hohe und sehr hohe Schutzbedarfe',
            'BSI-200-2.10' => 'Risikoanalyse-Konsolidierung',
            'BSI-200-2.11' => 'Realisierung der Maßnahmen',
            'BSI-200-2.12' => 'Aufrechterhaltung und kontinuierliche Verbesserung',
            'BSI-200-2.13' => 'Zertifizierung nach ISO 27001 auf der Basis von IT-Grundschutz',
        ],
        'BSI-GRUNDSCHUTZ-KERN' => [
            'BSI-200-2K.1'  => 'Identifikation der Kronjuwelen',
            'BSI-200-2K.2'  => 'Beschleunigte Schutzbedarfsfeststellung (nur Kronjuwelen)',
            'BSI-200-2K.3'  => 'Strukturanalyse beschränkt auf Kronjuwelen-Umgebung',
            'BSI-200-2K.4'  => 'Modellierung nach IT-Grundschutz (Fokus Kronjuwelen)',
            'BSI-200-2K.5'  => 'Grundschutz-Check (Basis-Anforderungen Kronjuwelen)',
            'BSI-200-2K.6'  => 'Risikoanalyse für Kronjuwelen',
            'BSI-200-2K.7'  => 'Realisierung priorisierter Maßnahmen (Kronjuwelen)',
            'BSI-200-2K.8'  => 'Übergang von Kern- zu Standard-Absicherung',
        ],
    ];

    public function __construct(
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $reqRepo = $this->em->getRepository(ComplianceRequirement::class);
        $totalCreated = 0; $totalUpdated = 0;

        foreach (self::STEPS as $code => $steps) {
            $framework = $this->frameworkRepository->findOneBy(['code' => $code]);
            if ($framework === null) {
                $io->warning("Framework {$code} not in DB — skipping.");
                continue;
            }
            $created = 0; $updated = 0;
            foreach ($steps as $reqId => $title) {
                $req = $reqRepo->findOneBy(['framework' => $framework, 'requirementId' => $reqId]);
                if ($req === null) {
                    $req = new ComplianceRequirement();
                    $req->setFramework($framework);
                    $req->setRequirementId($reqId);
                    $req->setRequirementType('core');
                    $req->setPriority('high');
                    $created++;
                } else {
                    $updated++;
                }
                $req->setTitle($title);
                $req->setDescription(sprintf('BSI 200-2 / %s — %s. Quelle: BSI-Standard 200-2 (IT-Grundschutz-Methodik) Prozessschritt.', $reqId, $title));
                $req->setCategory($code === 'BSI-GRUNDSCHUTZ-KERN' ? 'Kern-Absicherung' : 'Standard-Absicherung');
                $this->em->persist($req);
            }
            $this->em->flush();
            $io->writeln(sprintf('  %s: %d created, %d updated.', $code, $created, $updated));
            $totalCreated += $created;
            $totalUpdated += $updated;
        }
        $io->success(sprintf('BSI-Grundschutz variants: %d created, %d updated.', $totalCreated, $totalUpdated));
        return Command::SUCCESS;
    }
}
