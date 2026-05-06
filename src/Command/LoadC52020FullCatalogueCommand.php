<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ComplianceRequirement;
use App\Repository\ComplianceFrameworkRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Loads the full BSI C5:2020 catalogue (121 criteria) from the bundled
 * fixtures/library/catalogues/bsi-c5-2020-en/inventory.json (extracted verbatim
 * from BSI's official C5_2020_Editierbar.xlsx reference table).
 */
#[AsCommand(
    name: 'app:load-c5-2020-full-catalogue',
    description: 'Load the full BSI C5:2020 catalogue (121 criteria) from fixtures/library/catalogues/bsi-c5-2020-de/inventory.json.'
)]
final class LoadC52020FullCatalogueCommand extends Command
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
        $jsonPath = $this->projectDir . '/fixtures/library/catalogues/bsi-c5-2020-de/inventory.json';
        if (!is_readable($jsonPath)) {
            $io->error("Inventory not found: {$jsonPath}");
            return Command::FAILURE;
        }
        $framework = $this->frameworkRepository->findOneBy(['code' => 'BSI-C5']);
        if ($framework === null) {
            $io->error('Framework BSI-C5 not in DB.');
            return Command::FAILURE;
        }

        $inventory = json_decode((string) file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);
        $reqRepo = $this->em->getRepository(ComplianceRequirement::class);
        $created = 0; $updated = 0;
        foreach ($inventory as $reqId => $row) {
            $title = is_array($row) ? ($row['title'] ?? $reqId) : $reqId;
            $area = is_array($row) ? ($row['area'] ?? null) : null;
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
            $req->setTitle(mb_substr((string) $title, 0, 250));
            $req->setDescription(sprintf(
                "BSI C5:2020 / %s — %s. Quelle: BSI Cloud Computing Compliance Criteria Catalogue C5:2020 (offizielle Editierbar-Referenz, Version 6).",
                $reqId,
                $title
            ));
            if ($area !== null) {
                $req->setCategory(mb_substr((string) $area, 0, 100));
            }
            $this->em->persist($req);
        }
        $this->em->flush();
        $io->success(sprintf('BSI C5:2020 full: %d created, %d updated. Total: %d.', $created, $updated, count($inventory)));
        return Command::SUCCESS;
    }
}
