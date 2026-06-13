<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\ComplianceFrameworkRepository;
use App\Service\Compliance\DoraRtsItsCatalogueLoader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Standalone CLI wrapper around {@see DoraRtsItsCatalogueLoader}.
 *
 * The Level-2 catalogue (RTS / ITS / CIRs / Joint Guidelines under DORA,
 * Regulation EU 2022/2554) now lives in the shared service so the SAME rows
 * also flow through the framework-loader registry path
 * ({@see LoadDoraRequirementsCommand::loadRequirements()}). This command is
 * kept for operators who want to (re)load just the Level-2 depth on an
 * existing DORA framework. It upserts (update=true) — existing Level-2 rows
 * are refreshed, never skipped.
 *
 * See {@see DoraRtsItsCatalogueLoader} for the full identifier convention,
 * source references and validation caveat.
 */
#[AsCommand(
    name: 'app:load-dora-rts-its-full',
    description: 'Load DORA Level-2 catalogue (RTS, ITS, CIRs, Joint Guidelines) as ComplianceRequirement rows.'
)]
final class LoadDoraRtsItsFullCommand extends Command
{
    public function __construct(
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly EntityManagerInterface $em,
        private readonly DoraRtsItsCatalogueLoader $catalogueLoader,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $framework = $this->frameworkRepository->findOneBy(['code' => 'DORA']);
        if ($framework === null) {
            $io->error('Framework DORA not in DB. Run app:load-dora-requirements first.');
            return Command::FAILURE;
        }

        // update=true preserves the original upsert behaviour of this command.
        $stats = $this->catalogueLoader->load($framework, update: true);
        $this->em->flush();

        $io->success(sprintf(
            'DORA Level-2: %d created, %d updated. %d Level-2 IDs across %d RTS/ITS/CIR blocks.',
            $stats['created'],
            $stats['updated'],
            $stats['total'],
            $stats['blocks'],
        ));

        return Command::SUCCESS;
    }
}
