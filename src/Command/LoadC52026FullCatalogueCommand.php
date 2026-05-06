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
use Symfony\Component\Yaml\Yaml;

#[AsCommand(
    name: 'app:load-c5-2026-full-catalogue',
    description: 'Load the full BSI C5:2026 catalogue (168 criteria) from the bundled BSI YAML in fixtures/library/catalogues/bsi-c5-2026-en/ as ComplianceRequirement rows.'
)]
final class LoadC52026FullCatalogueCommand extends Command
{
    public function __construct(
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly EntityManagerInterface $em,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $catDir = $this->projectDir . '/fixtures/library/catalogues/bsi-c5-2026-en';
        if (!is_dir($catDir)) {
            $io->error("Catalogue directory not found: {$catDir}");
            return Command::FAILURE;
        }

        $framework = $this->frameworkRepository->findOneBy(['code' => 'BSI-C5-2026']);
        if ($framework === null) {
            $io->error("Framework BSI-C5-2026 not in DB. Run the alignment migration first.");
            return Command::FAILURE;
        }

        $reqRepo = $this->em->getRepository(ComplianceRequirement::class);
        $created = 0; $updated = 0;

        foreach (glob($catDir . '/*.yml') ?: [] as $f) {
            $area = basename($f, '.yml');
            $content = file_get_contents($f);
            // The BSI YAML uses anchors with duplicate names which breaks Symfony YAML.
            // Parse with regex instead, since structure is regular.
            preg_match_all(
                '/^  identifier:\s+&\S+\s+\'(\d+)\'\n  name:\s+\'([^\']+)\'/m',
                $content,
                $matches,
                PREG_SET_ORDER
            );
            foreach ($matches as [$_, $num, $title]) {
                $reqId = sprintf('%s-%s', $area, $num);
                $req = $reqRepo->findOneBy(['framework' => $framework, 'requirementId' => $reqId]);
                if ($req === null) {
                    $req = new ComplianceRequirement();
                    $req->setFramework($framework);
                    $req->setRequirementId($reqId);
                    $req->setRequirementType('core');
                    $req->setPriority('medium');
                    $created++;
                } else {
                    $updated++;
                }
                $req->setTitle(mb_substr($title, 0, 250));
                $req->setDescription(sprintf("BSI C5:2026 / %s / Kriterium %s. Quelle: BSI Cloud Computing Compliance Criteria Catalogue C5:2026 (final 2026-03-26, CC-BY-ND 4.0).\n\nTitel: %s", $area, $num, $title));
                $req->setCategory($area);
                $this->em->persist($req);
            }
        }
        $this->em->flush();
        $io->success(sprintf('BSI C5:2026 full catalogue: %d created, %d updated.', $created, $updated));
        return Command::SUCCESS;
    }
}
