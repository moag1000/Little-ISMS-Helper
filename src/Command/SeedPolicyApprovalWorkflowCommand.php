<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Workflow;
use App\Entity\WorkflowStep;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Seed the `policy-approval` workflow definition (Phase 4-C / W3-C).
 *
 * Reflects the 6-step approval pipeline from
 * `docs/plans/policy-wizard/05-architecture.md` §9.1:
 *
 *   1. prepared             — author submits the draft
 *   2. ciso_review          — CISO reviews non-privacy content
 *   3. dpo_cross_check      — DPO cross-checks privacy implications
 *   4. function_owner_review— P1 Risk-Owner sign-off (W3 NEW)
 *   5. top_mgmt_signoff     — Top-Management ceremony
 *   6. published            — terminal state
 *
 * Idempotent: re-running with `--overwrite` replaces the existing
 * workflow row plus all its steps. Without `--overwrite` the command is
 * a no-op when the workflow already exists.
 *
 * Run as one-off after deploy:
 *   php bin/console app:seed-policy-approval-workflow
 */
#[AsCommand(
    name: 'app:seed-policy-approval-workflow',
    description: 'Seeds the 6-step policy-approval workflow (Phase 4-C / W3-C).',
)]
class SeedPolicyApprovalWorkflowCommand extends Command
{
    public const string WORKFLOW_NAME = 'Policy Approval (W3)';
    public const string WORKFLOW_ENTITY_TYPE = 'Document';

    /**
     * @var list<array{name: string, description: string, stepType: string, approverRole: string, daysToComplete: int|null, isRequired: bool, metadata: array<string, mixed>}>
     */
    private const array STEPS = [
        [
            'name' => 'prepared',
            'description' => 'Author prepares the policy draft and submits it for review.',
            'stepType' => 'approval',
            'approverRole' => 'ROLE_USER',
            'daysToComplete' => 3,
            'isRequired' => true,
            'metadata' => ['stage' => 'prepared'],
        ],
        [
            'name' => 'ciso_review',
            'description' => 'CISO reviews the non-privacy content of the policy.',
            'stepType' => 'approval',
            'approverRole' => 'ROLE_CISO',
            'daysToComplete' => 5,
            'isRequired' => true,
            'metadata' => ['stage' => 'ciso_review'],
        ],
        [
            'name' => 'dpo_cross_check',
            'description' => 'Data Protection Officer cross-checks privacy aspects of the policy.',
            'stepType' => 'approval',
            'approverRole' => 'ROLE_DPO',
            'daysToComplete' => 5,
            'isRequired' => true,
            'metadata' => ['stage' => 'dpo_cross_check'],
        ],
        [
            'name' => 'function_owner_review',
            'description' => 'P1 Risk-Owner / function owner reviews policy from the operational view (W3 NEW).',
            'stepType' => 'approval',
            'approverRole' => 'ROLE_FUNCTION_OWNER',
            'daysToComplete' => 5,
            'isRequired' => true,
            'metadata' => ['stage' => 'function_owner_review', 'introduced_in' => 'w3'],
        ],
        [
            'name' => 'top_mgmt_signoff',
            'description' => 'Top management signs off on the policy. Privacy-section-gate must be released first.',
            'stepType' => 'approval',
            'approverRole' => 'ROLE_TOP_MGMT',
            'daysToComplete' => 7,
            'isRequired' => true,
            'metadata' => ['stage' => 'top_mgmt_signoff', 'gated_by' => 'privacy_section_gate'],
        ],
        [
            'name' => 'published',
            'description' => 'Policy is published and visible to acknowledgement audience.',
            'stepType' => 'auto_action',
            'approverRole' => 'ROLE_USER',
            'daysToComplete' => null,
            'isRequired' => true,
            'metadata' => ['stage' => 'published', 'terminal' => true],
        ],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'overwrite',
            null,
            InputOption::VALUE_NONE,
            'Replace any existing policy-approval workflow definition.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $overwrite = (bool) $input->getOption('overwrite');

        $repo = $this->entityManager->getRepository(Workflow::class);
        $existing = $repo->findOneBy([
            'name'       => self::WORKFLOW_NAME,
            'entityType' => self::WORKFLOW_ENTITY_TYPE,
        ]);

        if ($existing instanceof Workflow && !$overwrite) {
            $io->note(sprintf(
                'Policy-approval workflow already exists (id=%d). Use --overwrite to replace.',
                $existing->getId() ?? 0,
            ));
            return Command::SUCCESS;
        }

        if ($existing instanceof Workflow && $overwrite) {
            $this->entityManager->remove($existing);
            $this->entityManager->flush();
            $io->text('Removed existing policy-approval workflow.');
        }

        $workflow = $this->buildWorkflow();
        $this->entityManager->persist($workflow);
        $this->entityManager->flush();

        $io->success(sprintf(
            'Seeded policy-approval workflow with %d steps.',
            $workflow->getSteps()->count(),
        ));

        return Command::SUCCESS;
    }

    public function buildWorkflow(): Workflow
    {
        $workflow = new Workflow();
        $workflow->setName(self::WORKFLOW_NAME);
        $workflow->setEntityType(self::WORKFLOW_ENTITY_TYPE);
        $workflow->setDescription('6-step approval pipeline for policy documents emitted by the Policy Wizard.');
        $workflow->setIsActive(true);
        $workflow->setMetadata([
            'phase'   => '4-C',
            'sprint'  => 'W3-C',
            'version' => 1,
        ]);

        foreach (self::STEPS as $idx => $stepDef) {
            $step = new WorkflowStep();
            $step->setName($stepDef['name']);
            $step->setDescription($stepDef['description']);
            $step->setStepType($stepDef['stepType']);
            $step->setApproverRole($stepDef['approverRole']);
            $step->setDaysToComplete($stepDef['daysToComplete']);
            $step->setIsRequired($stepDef['isRequired']);
            $step->setStepOrder($idx + 1);
            $step->setMetadata($stepDef['metadata']);
            $workflow->addStep($step);
        }

        return $workflow;
    }
}
