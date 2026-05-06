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

#[AsCommand(
    name: 'app:load-nist-csf-2-0-full-catalogue',
    description: 'Load the full NIST CSF 2.0 catalogue (106 active subcategories) from fixtures/library/catalogues/nist-csf-2-0/csf2_subcategories.json as ComplianceRequirement rows.'
)]
final class LoadNistCsf2FullCatalogueCommand extends Command
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
        $jsonPath = $this->projectDir . '/fixtures/library/catalogues/nist-csf-2-0/csf2_subcategories.json';
        if (!is_readable($jsonPath)) {
            $io->error("Inventory not found: {$jsonPath}");
            return Command::FAILURE;
        }
        $framework = $this->frameworkRepository->findOneBy(['code' => 'NIST-CSF-2.0']);
        if ($framework === null) {
            $io->error("Framework NIST-CSF-2.0 not in DB. Run the alignment migration first.");
            return Command::FAILURE;
        }

        $inventory = json_decode((string) file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($inventory)) {
            $io->error("Inventory JSON malformed.");
            return Command::FAILURE;
        }

        $reqRepo = $this->em->getRepository(ComplianceRequirement::class);
        $created = 0; $updated = 0;
        foreach ($inventory as $reqId => $title) {
            // Function code is the prefix (GV, ID, PR, DE, RS, RC)
            $function = explode('.', (string) $reqId, 2)[0] ?? null;
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
            $req->setDescription(sprintf("NIST CSF 2.0 Subcategory %s. Quelle: NIST CSWP.29 (final 2024-02-26, public domain).\n\n%s", $reqId, $title));
            $req->setCategory($function);
            $this->em->persist($req);
        }
        $this->em->flush();
        $io->success(sprintf('NIST CSF 2.0 catalogue: %d created, %d updated.', $created, $updated));
        return Command::SUCCESS;
    }
}
