<?php

declare(strict_types=1);

namespace App\Command;

use App\Workflow\Mirror\RegulatoryWorkflowMirror;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Writes the database rows of the regulatory approval chains from their YAML.
 *
 * The chains are defined only in config/workflows/regulatory/*.yaml; this command is a thin wrapper
 * around {@see RegulatoryWorkflowMirror::sync()}, the same call as the Quick-Fix action
 * "Workflow-Definitionen abgleichen" (operators use that one — no shell needed). Rows are updated in
 * place, so running approvals keep their current step. Until S17 the command carried its own copy of
 * all steps in PHP, which drifted from the YAML (owner approvals of risk treatment, DPIA and control
 * verification never reached the database).
 */
#[AsCommand(
    name: 'app:generate-regulatory-workflows',
    description: 'Align the database rows of the regulatory approval chains with config/workflows/regulatory/*.yaml'
)]
class GenerateRegulatoryWorkflowsCommand extends Command
{
    /** Keys of the former PHP definitions, still accepted by --workflow. */
    private const LEGACY_KEYS = [
        'data-breach' => 'gdpr_data_breach',
        'incident-high' => 'incident_high_severity',
        'incident-low' => 'incident_low_severity',
        'risk-treatment' => 'risk_treatment',
        'change-request' => 'change_request',
        'management-review' => 'management_review',
        'control-verification' => 'control_verification',
        'supplier-assessment' => 'supplier_assessment',
        'training-verification' => 'training_verification',
        'bc-plan-activation' => 'bc_plan_activation',
        'document-review' => 'document_review',
        'incident-post-mortem' => 'incident_post_mortem',
    ];

    public function __construct(
        private readonly RegulatoryWorkflowMirror $mirror,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('overwrite', null, InputOption::VALUE_NONE, 'Kept for old scripts; rows are always updated in place')
            ->addOption('workflow', null, InputOption::VALUE_REQUIRED, 'Only this chain (YAML name, e.g. gdpr_data_breach)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only list what differs')
            ->setHelp(<<<'HELP'
Aligns the database rows of the regulatory approval chains with config/workflows/regulatory/*.yaml.
Operators use the Quick-Fix page (/quick-fix → "Workflow-Definitionen abgleichen") instead.

  <comment>php bin/console app:generate-regulatory-workflows</comment>
  <comment>php bin/console app:generate-regulatory-workflows --dry-run</comment>
  <comment>php bin/console app:generate-regulatory-workflows --workflow=gdpr_data_breach</comment>
HELP
            );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $only = $input->getOption('workflow');
        if (is_string($only)) {
            $only = self::LEGACY_KEYS[$only] ?? $only;
            if (!in_array($only, $this->mirror->workflowNames(), true)) {
                $io->error(sprintf('Unknown workflow: %s', $only));
                $io->listing($this->mirror->workflowNames());

                return Command::FAILURE;
            }
        }

        $drift = array_values(array_filter(
            $this->mirror->drift(),
            static fn (array $entry): bool => $only === null || $entry['workflow'] === $only,
        ));
        foreach ($drift as $entry) {
            $io->text(sprintf('%s (%s): %s', $entry['workflow'], $entry['title'], $entry['state']));
            $io->listing($entry['differences']);
        }

        if ($input->getOption('dry-run')) {
            $io->success(sprintf('%d chain(s) differ from the YAML.', count($drift)));

            return Command::SUCCESS;
        }

        $result = $this->mirror->sync(is_string($only) ? $only : null);
        $io->success(sprintf(
            'Created: %d, updated: %d, unchanged: %d.',
            $result['created'],
            $result['updated'],
            $result['unchanged'],
        ));
        if ($result['kept_steps'] > 0) {
            $io->note(sprintf(
                '%d step(s) the YAML dropped stay until the approvals standing on them are finished.',
                $result['kept_steps'],
            ));
        }

        return Command::SUCCESS;
    }
}
