<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * One-button "make TISAX consistent" for a whole fleet of tenants.
 *
 * After imports/migrations the downstream effects live in several commands
 * (fulfilment materialisation, transitive NIS2/DORA edges). A consultant running
 * many client tenants wants ONE idempotent entry point that brings every TISAX
 * tenant into a consistent state and is a no-op for tenants that never used
 * TISAX. This command just orchestrates the existing, individually-tested
 * commands — no duplicated logic.
 */
#[AsCommand(
    name: 'app:tisax:reconcile',
    description: 'Reconcile all TISAX tenants: materialise fulfilment + (re)derive transitive NIS2/DORA edges',
)]
final class TisaxReconcileCommand extends Command
{
    #[\Override]
    protected function configure(): void
    {
        $this->addOption('framework', null, InputOption::VALUE_REQUIRED, 'Framework code', 'TISAX');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $app = $this->getApplication();
        if ($app === null) {
            $io->error('No console application available.');
            return Command::FAILURE;
        }

        $io->section('1/2 — Fulfilment sync (all TISAX tenants)');
        $sync = $app->find('app:tisax:sync-fulfillment');
        $rc = $sync->run(new ArrayInput(['--framework' => (string) $input->getOption('framework')]), $output);
        if ($rc !== Command::SUCCESS) {
            return $rc;
        }

        $io->section('2/2 — Transitive TISAX→NIS2/DORA edges');
        $trans = $app->find('app:tisax:derive-transitive-mappings');
        $rc = $trans->run(new ArrayInput(['--force' => true]), $output);
        if ($rc !== Command::SUCCESS) {
            return $rc;
        }

        $io->success('TISAX reconcile complete — coverage, SoA and cross-framework reuse are up to date.');
        return Command::SUCCESS;
    }
}
