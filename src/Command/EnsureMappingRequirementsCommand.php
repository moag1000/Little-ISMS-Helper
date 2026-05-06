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
    name: 'app:mapping:ensure-requirements',
    description: 'Walk every mapping fixture, insert ComplianceRequirement stubs for IDs that are not yet in the DB so MappingLibraryLoader stops skipping entries.'
)]
final class EnsureMappingRequirementsCommand extends Command
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
        $dir = $this->projectDir . '/fixtures/library/mappings';
        $files = glob($dir . '/*.yaml') ?: [];

        $needed = [];
        foreach ($files as $f) {
            try {
                $data = Yaml::parseFile($f);
            } catch (\Throwable) {
                continue;
            }
            $lib = $data['library'] ?? null;
            if (!is_array($lib)) {
                continue;
            }
            $sourceFw = (string) ($lib['source_framework'] ?? '');
            $targetFw = (string) ($lib['target_framework'] ?? '');
            foreach (($data['mappings'] ?? []) as $entry) {
                $src = $entry['source'] ?? null;
                $tgt = $entry['target'] ?? null;
                if (is_string($src) && $src !== '' && $sourceFw !== '') {
                    $needed[$sourceFw][$src] = true;
                }
                if (is_string($tgt) && $tgt !== '' && $targetFw !== '') {
                    $needed[$targetFw][$tgt] = true;
                }
            }
        }

        $created = 0;
        $skippedFw = 0;
        $existing = 0;
        $reqRepo = $this->em->getRepository(ComplianceRequirement::class);

        foreach ($needed as $fwCode => $reqIds) {
            $fw = $this->frameworkRepository->findOneBy(['code' => $fwCode])
                ?? $this->frameworkRepository->findOneBy(['name' => $fwCode]);
            if ($fw === null) {
                $io->warning(sprintf('Framework not found: %s (skipping %d requirements)', $fwCode, count($reqIds)));
                $skippedFw += count($reqIds);
                continue;
            }
            foreach (array_keys($reqIds) as $reqId) {
                $existingReq = $reqRepo->findOneBy(['framework' => $fw, 'requirementId' => $reqId]);
                if ($existingReq !== null) {
                    $existing++;
                    continue;
                }
                $req = new ComplianceRequirement();
                $req->setFramework($fw);
                $req->setRequirementId($reqId);
                $req->setTitle($reqId);
                $req->setDescription(sprintf('Stub requirement for %s/%s. Imported via app:mapping:ensure-requirements; full text to be populated by the dedicated catalogue loader.', $fwCode, $reqId));
                $req->setPriority('medium');
                $req->setRequirementType('core');
                $this->em->persist($req);
                $created++;
            }
            $this->em->flush();
        }

        $io->success(sprintf('Created %d stub requirements. %d already existed. %d skipped (framework missing).', $created, $existing, $skippedFw));

        return Command::SUCCESS;
    }
}
