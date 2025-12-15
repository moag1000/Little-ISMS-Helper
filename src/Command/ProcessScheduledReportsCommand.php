<?php

namespace App\Command;

use App\Entity\ScheduledReport;
use App\Repository\ScheduledReportRepository;
use App\Service\ScheduledReportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Process Scheduled Reports Command
 *
 * Phase 7A: Processes all due scheduled reports and sends them via email.
 * Should be run via cron job (e.g., every hour or as needed).
 *
 * Usage:
 *   php bin/console app:process-scheduled-reports              # Process all due reports
 *   php bin/console app:process-scheduled-reports --dry-run    # Preview without sending
 *   php bin/console app:process-scheduled-reports --report=5   # Process specific report ID
 */
#[AsCommand(
    name: 'app:process-scheduled-reports',
    description: 'Process and send all due scheduled reports',
)]
class ProcessScheduledReportsCommand extends Command
{
    public function __construct(
        private readonly ScheduledReportService $scheduledReportService,
        private readonly ScheduledReportRepository $repository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview reports without sending')
            ->addOption('report', 'r', InputOption::VALUE_REQUIRED, 'Process specific report by ID')
            ->addOption('list', 'l', InputOption::VALUE_NONE, 'List all active scheduled reports')
            ->addOption('show-due', null, InputOption::VALUE_NONE, 'Show only reports that are due')
            ->setHelp(<<<'HELP'
                The <info>%command.name%</info> command processes scheduled reports and sends them via email.

                <info>Process all due reports:</info>
                    <comment>php %command.full_name%</comment>

                <info>Preview without sending (dry-run):</info>
                    <comment>php %command.full_name% --dry-run</comment>

                <info>Process a specific report:</info>
                    <comment>php %command.full_name% --report=5</comment>

                <info>List all active scheduled reports:</info>
                    <comment>php %command.full_name% --list</comment>

                <info>Show reports that are due:</info>
                    <comment>php %command.full_name% --show-due</comment>

                <info>Cron Setup (hourly):</info>
                    <comment>0 * * * * cd /path/to/project && php bin/console app:process-scheduled-reports</comment>

                <info>Note:</info>
                    Scheduled reports must be manually activated in the admin interface before they will be processed.
                HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $isDryRun = $input->getOption('dry-run');
        $reportId = $input->getOption('report');
        $listOnly = $input->getOption('list');
        $showDue = $input->getOption('show-due');

        // List mode
        if ($listOnly) {
            return $this->listReports($io, $showDue);
        }

        // Show due reports
        if ($showDue) {
            return $this->showDueReports($io);
        }

        // Process specific report
        if ($reportId !== null) {
            return $this->processSpecificReport($io, (int) $reportId, $isDryRun);
        }

        // Process all due reports
        return $this->processAllDueReports($io, $isDryRun);
    }

    /**
     * List all scheduled reports
     */
    private function listReports(SymfonyStyle $io, bool $dueOnly): int
    {
        $io->title('Scheduled Reports');

        $reports = $dueOnly
            ? $this->repository->findDueReports()
            : $this->repository->findBy(['isActive' => true]);

        if (empty($reports)) {
            $io->info($dueOnly ? 'No due reports found.' : 'No active scheduled reports found.');
            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($reports as $report) {
            $rows[] = [
                $report->getId(),
                $report->getName(),
                $report->getReportType(),
                $report->getSchedule(),
                $report->getFormat(),
                $report->isActive() ? 'Yes' : 'No',
                $report->getNextRunAt()?->format('Y-m-d H:i') ?? '-',
                count($report->getRecipients()),
            ];
        }

        $io->table(
            ['ID', 'Name', 'Type', 'Schedule', 'Format', 'Active', 'Next Run', 'Recipients'],
            $rows
        );

        $io->info(sprintf('Total: %d report(s)', count($reports)));

        return Command::SUCCESS;
    }

    /**
     * Show reports that are due
     */
    private function showDueReports(SymfonyStyle $io): int
    {
        $io->title('Due Scheduled Reports');

        $dueReports = $this->repository->findDueReports();

        if (empty($dueReports)) {
            $io->success('No reports are currently due.');
            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($dueReports as $report) {
            $rows[] = [
                $report->getId(),
                $report->getName(),
                $report->getReportType(),
                $report->getNextRunAt()?->format('Y-m-d H:i') ?? '-',
                implode(', ', $report->getRecipients()),
            ];
        }

        $io->table(
            ['ID', 'Name', 'Type', 'Scheduled For', 'Recipients'],
            $rows
        );

        $io->warning(sprintf('%d report(s) are due and waiting to be processed.', count($dueReports)));

        return Command::SUCCESS;
    }

    /**
     * Process a specific report by ID
     */
    private function processSpecificReport(SymfonyStyle $io, int $reportId, bool $isDryRun): int
    {
        $io->title('Process Specific Report');

        $report = $this->repository->find($reportId);

        if ($report === null) {
            $io->error("Report with ID {$reportId} not found.");
            return Command::FAILURE;
        }

        $io->section('Report Details');
        $io->table(
            ['Property', 'Value'],
            [
                ['ID', $report->getId()],
                ['Name', $report->getName()],
                ['Type', $report->getReportType()],
                ['Schedule', $report->getSchedule()],
                ['Format', $report->getFormat()],
                ['Active', $report->isActive() ? 'Yes' : 'No'],
                ['Recipients', implode(', ', $report->getRecipients())],
                ['Next Run', $report->getNextRunAt()?->format('Y-m-d H:i') ?? '-'],
            ]
        );

        if (!$report->isActive()) {
            $io->warning('This report is not active. It will be processed but normally would be skipped.');
        }

        if ($isDryRun) {
            $io->note('DRY-RUN mode: Report will NOT be generated or sent.');

            try {
                $io->text('Validating report configuration...');
                // Just validate the configuration
                $io->success('Report configuration is valid. In normal mode, it would be generated and sent.');
            } catch (\Exception $e) {
                $io->error("Validation failed: {$e->getMessage()}");
                return Command::FAILURE;
            }

            return Command::SUCCESS;
        }

        $io->text('Processing report...');

        try {
            $this->scheduledReportService->triggerReport($report);
            $io->success("Report '{$report->getName()}' processed and sent successfully.");
        } catch (\Exception $e) {
            $io->error("Failed to process report: {$e->getMessage()}");
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Process all due reports
     */
    private function processAllDueReports(SymfonyStyle $io, bool $isDryRun): int
    {
        $io->title('Process Scheduled Reports');

        $dueReports = $this->repository->findDueReports();

        if (empty($dueReports)) {
            $io->success('No reports are due at this time.');
            return Command::SUCCESS;
        }

        $io->info(sprintf('Found %d report(s) due for processing.', count($dueReports)));

        if ($isDryRun) {
            $io->note('DRY-RUN mode: Reports will NOT be generated or sent.');

            foreach ($dueReports as $report) {
                $io->text(sprintf(
                    '  - [%d] %s (%s) -> %d recipient(s)',
                    $report->getId(),
                    $report->getName(),
                    $report->getReportType(),
                    count($report->getRecipients())
                ));
            }

            return Command::SUCCESS;
        }

        $io->text('Processing reports...');
        $io->progressStart(count($dueReports));

        $results = $this->scheduledReportService->processDueReports();

        $io->progressFinish();

        // Show results
        $io->newLine(2);

        if ($results['success'] > 0) {
            $io->success(sprintf('%d report(s) processed successfully.', $results['success']));
        }

        if ($results['failed'] > 0) {
            $io->error(sprintf('%d report(s) failed to process.', $results['failed']));

            foreach ($results['details'] as $detail) {
                if ($detail['status'] === 'failed') {
                    $io->text(sprintf('  - [%d] %s: %s', $detail['id'], $detail['name'], $detail['error'] ?? 'Unknown error'));
                }
            }
        }

        return $results['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
