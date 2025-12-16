<?php

namespace App\Command;

use App\Entity\WizardSession;
use App\Repository\TenantRepository;
use App\Repository\WizardSessionRepository;
use App\Service\ComplianceWizardService;
use App\Service\PdfExportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Wizard Report Command
 *
 * Phase 7E: Generates compliance wizard reports from the command line.
 * Supports multiple output formats and can run assessments for any framework.
 *
 * Usage:
 *   php bin/console app:wizard-report iso27001                    # Run ISO 27001 assessment
 *   php bin/console app:wizard-report dora --tenant=1             # Run DORA for specific tenant
 *   php bin/console app:wizard-report nis2 --output=report.pdf    # Export to PDF
 *   php bin/console app:wizard-report --list                      # List available wizards
 */
#[AsCommand(
    name: 'app:wizard-report',
    description: 'Generate compliance wizard assessment reports',
)]
class WizardReportCommand extends Command
{
    private const AVAILABLE_WIZARDS = [
        'iso27001' => 'ISO 27001:2022 Information Security',
        'nis2' => 'NIS2 Directive (EU 2022/2555)',
        'dora' => 'DORA - Digital Operational Resilience Act',
        'tisax' => 'TISAX - Automotive Information Security',
        'gdpr' => 'GDPR/DSGVO - Data Protection',
        'bsi_grundschutz' => 'BSI IT-Grundschutz',
    ];

    public function __construct(
        private readonly ComplianceWizardService $wizardService,
        private readonly TenantRepository $tenantRepository,
        private readonly WizardSessionRepository $sessionRepository,
        private readonly ?PdfExportService $pdfExportService = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('wizard', InputArgument::OPTIONAL, 'Wizard type (iso27001, nis2, dora, tisax, gdpr, bsi_grundschutz)')
            ->addOption('tenant', 't', InputOption::VALUE_REQUIRED, 'Tenant ID to run assessment for')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output file path (PDF or JSON)')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Output format: json, pdf, console (default: console)', 'console')
            ->addOption('list', 'l', InputOption::VALUE_NONE, 'List all available wizards')
            ->addOption('sessions', 's', InputOption::VALUE_NONE, 'Show completed wizard sessions')
            ->addOption('session-id', null, InputOption::VALUE_REQUIRED, 'Export specific session by ID')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Run assessment for all available wizards')
            ->setHelp(<<<'HELP'
                The <info>%command.name%</info> command generates compliance wizard assessment reports.

                <info>Run an assessment:</info>
                    <comment>php %command.full_name% iso27001</comment>
                    <comment>php %command.full_name% dora</comment>
                    <comment>php %command.full_name% nis2</comment>

                <info>Run for specific tenant:</info>
                    <comment>php %command.full_name% iso27001 --tenant=1</comment>

                <info>Export to file:</info>
                    <comment>php %command.full_name% iso27001 --output=report.pdf</comment>
                    <comment>php %command.full_name% iso27001 --output=report.json --format=json</comment>

                <info>Run all available wizards:</info>
                    <comment>php %command.full_name% --all</comment>

                <info>List available wizards:</info>
                    <comment>php %command.full_name% --list</comment>

                <info>Show completed sessions:</info>
                    <comment>php %command.full_name% --sessions</comment>

                <info>Export existing session:</info>
                    <comment>php %command.full_name% --session-id=5 --output=report.pdf</comment>

                <info>Available Wizards:</info>
                    - iso27001: ISO 27001:2022 Information Security
                    - nis2: NIS2 Directive (EU 2022/2555)
                    - dora: DORA - Digital Operational Resilience Act
                    - tisax: TISAX - Automotive Information Security
                    - gdpr: GDPR/DSGVO - Data Protection
                    - bsi_grundschutz: BSI IT-Grundschutz
                HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // List mode
        if ($input->getOption('list')) {
            return $this->listWizards($io);
        }

        // Sessions mode
        if ($input->getOption('sessions')) {
            return $this->showSessions($io, $input->getOption('tenant'));
        }

        // Export specific session
        $sessionId = $input->getOption('session-id');
        if ($sessionId !== null) {
            return $this->exportSession($io, (int) $sessionId, $input);
        }

        // Run all wizards
        if ($input->getOption('all')) {
            return $this->runAllWizards($io, $input);
        }

        // Run specific wizard
        $wizard = $input->getArgument('wizard');
        if ($wizard === null) {
            $io->error('Please specify a wizard type or use --list to see available options.');
            return Command::FAILURE;
        }

        return $this->runWizard($io, $wizard, $input);
    }

    /**
     * List available wizards
     */
    private function listWizards(SymfonyStyle $io): int
    {
        $io->title('Available Compliance Wizards');

        $availableWizards = $this->wizardService->getAvailableWizards();

        if (empty($availableWizards)) {
            $io->warning('No wizards available. Required modules may not be active.');
            return Command::SUCCESS;
        }

        $rows = [];
        foreach (self::AVAILABLE_WIZARDS as $code => $description) {
            $available = isset($availableWizards[$code]);
            $rows[] = [
                $code,
                $description,
                $available ? '<fg=green>Available</>' : '<fg=yellow>Requires modules</>',
            ];
        }

        $io->table(['Code', 'Description', 'Status'], $rows);

        $io->note('Run: php bin/console app:wizard-report <wizard-code>');

        return Command::SUCCESS;
    }

    /**
     * Show completed wizard sessions
     */
    private function showSessions(SymfonyStyle $io, ?string $tenantId): int
    {
        $io->title('Completed Wizard Sessions');

        try {
            if ($tenantId !== null) {
                $tenant = $this->tenantRepository->find((int) $tenantId);
                if ($tenant === null) {
                    $io->error("Tenant with ID $tenantId not found.");
                    return Command::FAILURE;
                }
                $sessions = $this->sessionRepository->findBy(
                    ['tenant' => $tenant, 'status' => WizardSession::STATUS_COMPLETED],
                    ['completedAt' => 'DESC']
                );
            } else {
                $sessions = $this->sessionRepository->findBy(
                    ['status' => WizardSession::STATUS_COMPLETED],
                    ['completedAt' => 'DESC'],
                    50
                );
            }
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), "doesn't exist") || str_contains($e->getMessage(), 'not found')) {
                $io->warning('Wizard sessions table not found. Please run database migrations first:');
                $io->text('  php bin/console doctrine:migrations:migrate');
                return Command::SUCCESS;
            }
            throw $e;
        }

        if (empty($sessions)) {
            $io->info('No completed wizard sessions found.');
            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($sessions as $session) {
            $rows[] = [
                $session->getId(),
                $session->getWizardName(),
                $session->getTenant()?->getName() ?? 'N/A',
                $session->getUser()?->getEmail() ?? 'N/A',
                $session->getOverallScore() . '%',
                $session->getCompletedAt()?->format('Y-m-d H:i') ?? 'N/A',
            ];
        }

        $io->table(['ID', 'Wizard', 'Tenant', 'User', 'Score', 'Completed'], $rows);

        $io->note('Export session: php bin/console app:wizard-report --session-id=<ID> --output=report.pdf');

        return Command::SUCCESS;
    }

    /**
     * Export existing session
     */
    private function exportSession(SymfonyStyle $io, int $sessionId, InputInterface $input): int
    {
        $session = $this->sessionRepository->find($sessionId);

        if ($session === null) {
            $io->error("Session with ID $sessionId not found.");
            return Command::FAILURE;
        }

        if (!$session->isCompleted()) {
            $io->error('Session is not completed. Only completed sessions can be exported.');
            return Command::FAILURE;
        }

        $results = $session->getAssessmentResults();
        if (empty($results)) {
            $io->warning('Session has no assessment results.');
            return Command::FAILURE;
        }

        // Add session metadata
        $results['session_id'] = $session->getId();
        $results['user'] = $session->getUser()?->getEmail();
        $results['tenant'] = $session->getTenant()?->getName();
        $results['completed_at'] = $session->getCompletedAt()?->format('Y-m-d H:i:s');

        return $this->outputResults($io, $session->getWizardType(), $results, $input);
    }

    /**
     * Run all available wizards
     */
    private function runAllWizards(SymfonyStyle $io, InputInterface $input): int
    {
        $io->title('Running All Available Compliance Wizards');

        $availableWizards = $this->wizardService->getAvailableWizards();

        if (empty($availableWizards)) {
            $io->warning('No wizards available.');
            return Command::SUCCESS;
        }

        $summaryRows = [];
        foreach (array_keys($availableWizards) as $wizardCode) {
            $io->section("Running: " . self::AVAILABLE_WIZARDS[$wizardCode] ?? $wizardCode);

            $results = $this->wizardService->runAssessment($wizardCode);

            if (!$results['success']) {
                $io->warning("Failed: " . ($results['error'] ?? 'Unknown error'));
                $summaryRows[] = [$wizardCode, 'Failed', '-', '-'];
                continue;
            }

            $summaryRows[] = [
                $wizardCode,
                $results['status'] ?? 'unknown',
                $results['overall_score'] . '%',
                $results['critical_gap_count'] ?? 0,
            ];

            $this->displayResults($io, $results, false);
        }

        $io->section('Summary');
        $io->table(['Wizard', 'Status', 'Score', 'Critical Gaps'], $summaryRows);

        return Command::SUCCESS;
    }

    /**
     * Run a specific wizard
     */
    private function runWizard(SymfonyStyle $io, string $wizard, InputInterface $input): int
    {
        if (!isset(self::AVAILABLE_WIZARDS[$wizard])) {
            $io->error("Unknown wizard: $wizard");
            $io->note('Use --list to see available wizards.');
            return Command::FAILURE;
        }

        $io->title(self::AVAILABLE_WIZARDS[$wizard] . ' Assessment');

        // Check if wizard is available
        if (!$this->wizardService->isWizardAvailable($wizard)) {
            $io->error("Wizard '$wizard' is not available. Required modules may not be active.");

            $config = $this->wizardService->getWizardConfig($wizard);
            if ($config !== null && !empty($config['required_modules'])) {
                $io->listing($config['required_modules']);
            }

            return Command::FAILURE;
        }

        $io->text('Running assessment...');
        $io->newLine();

        $results = $this->wizardService->runAssessment($wizard);

        if (!$results['success']) {
            $io->error('Assessment failed: ' . ($results['error'] ?? 'Unknown error'));
            return Command::FAILURE;
        }

        return $this->outputResults($io, $wizard, $results, $input);
    }

    /**
     * Output results based on format
     */
    private function outputResults(SymfonyStyle $io, string $wizard, array $results, InputInterface $input): int
    {
        $format = $input->getOption('format');
        $outputPath = $input->getOption('output');

        // Auto-detect format from output path
        if ($outputPath !== null && $format === 'console') {
            if (str_ends_with(strtolower($outputPath), '.json')) {
                $format = 'json';
            } elseif (str_ends_with(strtolower($outputPath), '.pdf')) {
                $format = 'pdf';
            }
        }

        switch ($format) {
            case 'json':
                return $this->outputJson($io, $results, $outputPath);

            case 'pdf':
                return $this->outputPdf($io, $wizard, $results, $outputPath);

            case 'console':
            default:
                $this->displayResults($io, $results, true);
                return Command::SUCCESS;
        }
    }

    /**
     * Output as JSON
     */
    private function outputJson(SymfonyStyle $io, array $results, ?string $outputPath): int
    {
        // Remove DateTime objects for JSON serialization
        $results['assessed_at'] = $results['assessed_at'] instanceof \DateTimeInterface
            ? $results['assessed_at']->format('Y-m-d H:i:s')
            : $results['assessed_at'];

        $json = json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if ($outputPath !== null) {
            if (file_put_contents($outputPath, $json) === false) {
                $io->error("Failed to write to: $outputPath");
                return Command::FAILURE;
            }
            $io->success("Report saved to: $outputPath");
        } else {
            $io->writeln($json);
        }

        return Command::SUCCESS;
    }

    /**
     * Output as PDF
     */
    private function outputPdf(SymfonyStyle $io, string $wizard, array $results, ?string $outputPath): int
    {
        if ($this->pdfExportService === null) {
            $io->error('PDF export service not available.');
            return Command::FAILURE;
        }

        if ($outputPath === null) {
            $outputPath = sprintf('wizard_report_%s_%s.pdf', $wizard, date('Y-m-d_H-i-s'));
        }

        try {
            // For PDF, we need to generate HTML first
            $html = $this->generateReportHtml($wizard, $results);
            $pdfContent = $this->pdfExportService->generateFromHtml($html, [
                'title' => ($results['framework_name'] ?? $wizard) . ' Compliance Report',
            ]);

            if (file_put_contents($outputPath, $pdfContent) === false) {
                $io->error("Failed to write to: $outputPath");
                return Command::FAILURE;
            }

            $io->success("PDF report saved to: $outputPath");
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('PDF generation failed: ' . $e->getMessage());
            // Fall back to console output
            $io->note('Displaying results in console instead:');
            $this->displayResults($io, $results, true);
            return Command::FAILURE;
        }
    }

    /**
     * Display results in console
     */
    private function displayResults(SymfonyStyle $io, array $results, bool $detailed): void
    {
        // Overall status
        $score = $results['overall_score'] ?? 0;
        $status = $results['status'] ?? 'unknown';
        $statusColor = match ($status) {
            'compliant' => 'green',
            'partial' => 'yellow',
            'in_progress' => 'cyan',
            default => 'red',
        };

        $io->writeln([
            '',
            sprintf('  <fg=white;bg=%s> %s </> Score: <fg=%s;options=bold>%d%%</>',
                $statusColor, strtoupper($status), $statusColor, $score),
            '',
        ]);

        // Category breakdown
        if ($detailed && !empty($results['categories'])) {
            $io->section('Category Scores');

            $categoryRows = [];
            foreach ($results['categories'] as $key => $category) {
                $categoryScore = $category['score'] ?? 0;
                $gapCount = count($category['gaps'] ?? []);

                $scoreDisplay = $categoryScore >= 80
                    ? "<fg=green>$categoryScore%</>"
                    : ($categoryScore >= 60 ? "<fg=yellow>$categoryScore%</>" : "<fg=red>$categoryScore%</>");

                $categoryRows[] = [
                    $category['name'] ?? $key,
                    $scoreDisplay,
                    $gapCount > 0 ? "<fg=yellow>$gapCount</>" : '<fg=green>0</>',
                ];
            }

            $io->table(['Category', 'Score', 'Gaps'], $categoryRows);
        }

        // Critical gaps
        if ($detailed && !empty($results['critical_gaps'])) {
            $io->section('Critical Gaps');
            $gapStrings = [];
            foreach ($results['critical_gaps'] as $gap) {
                if (is_array($gap)) {
                    // Format: "description (category: xxx, priority: xxx)"
                    $gapStrings[] = sprintf(
                        '%s (Category: %s)',
                        $gap['description'] ?? $gap['message'] ?? 'Unknown gap',
                        $gap['category'] ?? 'N/A'
                    );
                } else {
                    $gapStrings[] = (string) $gap;
                }
            }
            $io->listing($gapStrings);
        }

        // Active modules
        if (!empty($results['active_modules'])) {
            $io->section('Active Modules');
            $io->listing($results['active_modules']);
        }

        // Missing modules
        if (!empty($results['missing_modules'])) {
            $io->section('Missing Modules (would improve assessment)');
            $io->listing($results['missing_modules']);
        }

        // Timestamp
        $assessedAt = $results['assessed_at'] ?? null;
        if ($assessedAt instanceof \DateTimeInterface) {
            $io->note('Assessed at: ' . $assessedAt->format('Y-m-d H:i:s'));
        }
    }

    /**
     * Generate HTML for PDF report
     */
    private function generateReportHtml(string $wizard, array $results): string
    {
        $frameworkName = $results['framework_name'] ?? self::AVAILABLE_WIZARDS[$wizard] ?? $wizard;
        $score = $results['overall_score'] ?? 0;
        $status = $results['status'] ?? 'unknown';
        $statusLabel = match ($status) {
            'compliant' => 'Compliant',
            'partial' => 'Partially Compliant',
            'in_progress' => 'In Progress',
            default => 'Non-Compliant',
        };
        $statusColor = match ($status) {
            'compliant' => '#28a745',
            'partial' => '#ffc107',
            'in_progress' => '#17a2b8',
            default => '#dc3545',
        };

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>{$frameworkName} Compliance Report</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; color: #333; }
        h1 { color: #2c3e50; border-bottom: 2px solid #3498db; padding-bottom: 10px; }
        h2 { color: #34495e; margin-top: 30px; }
        .score-box {
            background: {$statusColor};
            color: white;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            margin: 20px 0;
        }
        .score-value { font-size: 48px; font-weight: bold; }
        .score-label { font-size: 18px; margin-top: 5px; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 12px; text-align: left; }
        th { background: #f8f9fa; font-weight: bold; }
        tr:nth-child(even) { background: #f8f9fa; }
        .gap-list { margin: 10px 0; padding-left: 20px; }
        .gap-item { margin: 5px 0; color: #c0392b; }
        .meta { color: #7f8c8d; font-size: 12px; margin-top: 40px; }
        .good { color: #28a745; }
        .warning { color: #ffc107; }
        .danger { color: #dc3545; }
    </style>
</head>
<body>
    <h1>{$frameworkName} Compliance Report</h1>

    <div class="score-box">
        <div class="score-value">{$score}%</div>
        <div class="score-label">{$statusLabel}</div>
    </div>
HTML;

        // Categories table
        if (!empty($results['categories'])) {
            $html .= '<h2>Category Breakdown</h2><table><tr><th>Category</th><th>Score</th><th>Gaps</th></tr>';

            foreach ($results['categories'] as $key => $category) {
                $categoryScore = $category['score'] ?? 0;
                $gapCount = count($category['gaps'] ?? []);
                $scoreClass = $categoryScore >= 80 ? 'good' : ($categoryScore >= 60 ? 'warning' : 'danger');

                $html .= sprintf(
                    '<tr><td>%s</td><td class="%s">%d%%</td><td>%d</td></tr>',
                    htmlspecialchars($category['name'] ?? $key),
                    $scoreClass,
                    $categoryScore,
                    $gapCount
                );
            }

            $html .= '</table>';
        }

        // Critical gaps
        if (!empty($results['critical_gaps'])) {
            $html .= '<h2>Critical Gaps</h2><ul class="gap-list">';
            foreach ($results['critical_gaps'] as $gap) {
                if (is_array($gap)) {
                    $gapText = sprintf(
                        '%s (Category: %s)',
                        $gap['description'] ?? $gap['message'] ?? 'Unknown gap',
                        $gap['category'] ?? 'N/A'
                    );
                } else {
                    $gapText = (string) $gap;
                }
                $html .= '<li class="gap-item">' . htmlspecialchars($gapText) . '</li>';
            }
            $html .= '</ul>';
        }

        // Active modules
        if (!empty($results['active_modules'])) {
            $html .= '<h2>Active Modules</h2><ul>';
            foreach ($results['active_modules'] as $module) {
                $html .= '<li>' . htmlspecialchars($module) . '</li>';
            }
            $html .= '</ul>';
        }

        // Metadata
        $assessedAt = $results['assessed_at'] ?? null;
        $dateStr = $assessedAt instanceof \DateTimeInterface
            ? $assessedAt->format('Y-m-d H:i:s')
            : date('Y-m-d H:i:s');

        $html .= <<<HTML
    <div class="meta">
        <p>Generated: {$dateStr}</p>
        <p>Report generated by Little ISMS Helper - Compliance Wizard</p>
    </div>
</body>
</html>
HTML;

        return $html;
    }
}
