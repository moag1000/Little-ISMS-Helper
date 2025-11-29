<?php

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
 * Generate Regulatory-Compliant Workflow Definitions
 *
 * Creates pre-configured workflow definitions based on regulatory requirements:
 * - GDPR Art. 33/34 (Data Breach Notification - 72h deadline)
 * - ISO 27001:2022 Clause 5.24-5.28 (Incident Management)
 * - ISO 27001:2022 Clause 6.1.3 (Risk Treatment)
 * - GDPR Art. 35/36 (Data Protection Impact Assessment)
 *
 * Based on: docs/WORKFLOW_REQUIREMENTS.md
 */
#[AsCommand(
    name: 'app:generate-regulatory-workflows',
    description: 'Generate regulatory-compliant workflow definitions (GDPR, ISO 27001, etc.)'
)]
class GenerateRegulatoryWorkflowsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('overwrite', null, InputOption::VALUE_NONE, 'Overwrite existing workflow definitions')
            ->addOption('workflow', null, InputOption::VALUE_REQUIRED, 'Generate only specific workflow (data-breach, incident-high, incident-low, risk-treatment, dpia)')
            ->setHelp(<<<'HELP'
This command generates regulatory-compliant workflow definitions based on:

<info>Available Workflows:</info>
  • <comment>data-breach</comment>        GDPR Art. 33/34 - 72h notification deadline
  • <comment>incident-high</comment>      ISO 27001 High/Critical Severity Incidents
  • <comment>incident-low</comment>       ISO 27001 Low/Medium Severity Incidents
  • <comment>risk-treatment</comment>     ISO 27001 Risk Treatment Approval
  • <comment>dpia</comment>               GDPR Art. 35 Data Protection Impact Assessment

<info>Examples:</info>
  # Generate all workflows
  <comment>php bin/console app:generate-regulatory-workflows</comment>

  # Generate only Data Breach workflow
  <comment>php bin/console app:generate-regulatory-workflows --workflow=data-breach</comment>

  # Overwrite existing definitions
  <comment>php bin/console app:generate-regulatory-workflows --overwrite</comment>

<info>Regulatory References:</info>
  See docs/WORKFLOW_REQUIREMENTS.md for detailed requirements and SLAs.
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $overwrite = $input->getOption('overwrite');
        $specificWorkflow = $input->getOption('workflow');

        $io->title('Regulatory Workflow Generator');
        $io->text('Generating workflows based on GDPR, ISO 27001, and BSI IT-Grundschutz requirements');

        $workflows = $this->getWorkflowDefinitions();

        if ($specificWorkflow) {
            if (!isset($workflows[$specificWorkflow])) {
                $io->error("Unknown workflow: {$specificWorkflow}");
                $io->listing(array_keys($workflows));
                return Command::FAILURE;
            }
            $workflows = [$specificWorkflow => $workflows[$specificWorkflow]];
        }

        $created = 0;
        $skipped = 0;
        $updated = 0;

        foreach ($workflows as $key => $definition) {
            $existing = $this->entityManager->getRepository(Workflow::class)
                ->findOneBy(['name' => $definition['name'], 'entityType' => $definition['entityType']]);

            if ($existing && !$overwrite) {
                $io->warning("Workflow '{$definition['name']}' already exists. Use --overwrite to replace.");
                $skipped++;
                continue;
            }

            if ($existing && $overwrite) {
                // Remove existing workflow and its steps
                $this->entityManager->remove($existing);
                $this->entityManager->flush();
                $updated++;
                $io->text("Replacing existing workflow: {$definition['name']}");
            } else {
                $created++;
                $io->text("Creating new workflow: {$definition['name']}");
            }

            $workflow = $this->createWorkflow($definition);
            $this->entityManager->persist($workflow);
            $this->entityManager->flush();

            $io->success("✓ {$definition['name']} ({$workflow->getSteps()->count()} steps)");
        }

        $io->newLine();
        $io->success([
            "Workflow generation complete!",
            "Created: {$created}",
            "Updated: {$updated}",
            "Skipped: {$skipped}",
        ]);

        if ($created > 0 || $updated > 0) {
            $io->note('Workflows are ready to use. They will be automatically triggered when relevant entities are created.');
        }

        return Command::SUCCESS;
    }

    /**
     * Get all workflow definitions
     */
    private function getWorkflowDefinitions(): array
    {
        return [
            'data-breach' => $this->getDataBreachWorkflow(),
            'incident-high' => $this->getIncidentHighSeverityWorkflow(),
            'incident-low' => $this->getIncidentLowSeverityWorkflow(),
            'risk-treatment' => $this->getRiskTreatmentWorkflow(),
            'dpia' => $this->getDpiaWorkflow(),
        ];
    }

    /**
     * GDPR Art. 33/34 - Data Breach Workflow
     * Total SLA: 72 hours (regulatory requirement)
     */
    private function getDataBreachWorkflow(): array
    {
        return [
            'name' => 'GDPR Data Breach Notification (Art. 33/34)',
            'description' => 'Regulatory-compliant workflow for GDPR data breach handling with 72-hour notification deadline',
            'entityType' => 'DataBreach',
            'isActive' => true,
            'metadata' => [
                'slaEnforcement' => true,
                'slaDeadlineHours' => 72, // GDPR Art. 33 requirement
                'escalationThresholdHours' => 60, // Escalate 12h before deadline
                'escalationRole' => 'ROLE_ADMIN', // Escalate to board/management
            ],
            'steps' => [
                [
                    'name' => 'Initial Assessment (DPO)',
                    'description' => 'Data Protection Officer assesses breach severity, affected data subjects, and notification requirements (GDPR Art. 33/34)',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_DPO',
                    'daysToComplete' => 1, // 24h SLA
                    'sortOrder' => 1,
                    'isRequired' => true,
                    'autoProgressConditions' => [
                        'type' => 'field_completion',
                        'entity' => 'DataBreach',
                        'fields' => ['severity', 'affectedDataSubjectsCount', 'dataCategories', 'notificationRequired'],
                    ],
                ],
                [
                    'name' => 'Technical Assessment (CISO)',
                    'description' => 'Technical analysis of breach cause, scope, containment, and recovery measures',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_CISO',
                    'daysToComplete' => 1, // 24h SLA (parallel with DPO)
                    'sortOrder' => 2,
                    'isRequired' => true,
                    'autoProgressConditions' => [
                        'type' => 'field_completion',
                        'entity' => 'DataBreach',
                        'fields' => ['rootCause', 'affectedSystems', 'containmentMeasures', 'recoveryMeasures'],
                    ],
                ],
                [
                    'name' => 'Management Information',
                    'description' => 'Notify executive management of high-risk breach for budget approval and PR strategy',
                    'stepType' => 'notification',
                    'approverRole' => 'ROLE_MANAGER',
                    'daysToComplete' => 0, // Auto-progress (notification only)
                    'sortOrder' => 3,
                    'isRequired' => false,
                    'autoProgressConditions' => [
                        'type' => 'auto',
                        'condition' => 'severity >= high',
                    ],
                ],
                [
                    'name' => 'Supervisory Authority Notification (DPO)',
                    'description' => 'File official notification with supervisory authority within 72h deadline (GDPR Art. 33)',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_DPO',
                    'daysToComplete' => 1, // Remaining time to meet 72h total
                    'sortOrder' => 4,
                    'isRequired' => true,
                    'autoProgressConditions' => [
                        'type' => 'field_completion',
                        'entity' => 'DataBreach',
                        'fields' => ['authorityNotificationDate', 'authorityNotificationMethod'],
                    ],
                ],
                [
                    'name' => 'Data Subject Notification (DPO)',
                    'description' => 'Notify affected data subjects if high risk (GDPR Art. 34)',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_DPO',
                    'daysToComplete' => 1, // "Unverzüglich" (without undue delay)
                    'sortOrder' => 5,
                    'isRequired' => false,
                    'autoProgressConditions' => [
                        'type' => 'field_completion',
                        'entity' => 'DataBreach',
                        'fields' => ['dataSubjectNotificationDate', 'dataSubjectNotificationMethod'],
                        'condition' => 'dataSubjectNotificationRequired = true',
                    ],
                ],
                [
                    'name' => 'Final Documentation (DPO)',
                    'description' => 'Complete breach documentation and lessons learned (GDPR Art. 33 Abs. 5)',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_DPO',
                    'daysToComplete' => 7,
                    'sortOrder' => 6,
                    'isRequired' => true,
                    'autoProgressConditions' => [
                        'type' => 'field_completion',
                        'entity' => 'DataBreach',
                        'fields' => ['lessonsLearned', 'processImprovements'],
                    ],
                ],
            ],
        ];
    }

    /**
     * ISO 27001 High/Critical Severity Incident Workflow
     */
    private function getIncidentHighSeverityWorkflow(): array
    {
        return [
            'name' => 'Incident Response - High/Critical Severity',
            'description' => 'Escalated incident response workflow for high and critical severity security incidents (ISO 27001:2022)',
            'entityType' => 'Incident',
            'isActive' => true,
            'steps' => [
                [
                    'name' => 'Immediate Response (CISO)',
                    'description' => 'Activate crisis team, initiate containment, inform stakeholders',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_CISO',
                    'daysToComplete' => 0, // 1h SLA (expressed as fraction of day)
                    'sortOrder' => 1,
                    'isRequired' => true,
                ],
                [
                    'name' => 'Crisis Management (Crisis Team)',
                    'description' => 'Coordinate all response measures, management briefing, external communication',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_CRISIS_MANAGER',
                    'daysToComplete' => 0, // 4h SLA
                    'sortOrder' => 2,
                    'isRequired' => true,
                ],
                [
                    'name' => 'Technical Response (Security + IT)',
                    'description' => 'Forensic preservation, incident containment, system recovery',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_SECURITY_MANAGER',
                    'daysToComplete' => 0, // 8h SLA
                    'sortOrder' => 3,
                    'isRequired' => true,
                ],
                [
                    'name' => 'Legal/Compliance Check (Legal + DPO)',
                    'description' => 'Verify notification requirements (NIS2, GDPR), inform contractual partners',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_DPO',
                    'daysToComplete' => 0, // 12h SLA
                    'sortOrder' => 4,
                    'isRequired' => true,
                ],
                [
                    'name' => 'Recovery Validation (CISO + IT Manager)',
                    'description' => 'Validate system integrity, ensure business continuity, approve return to normal operations',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_CISO',
                    'daysToComplete' => 1, // 24h SLA
                    'sortOrder' => 5,
                    'isRequired' => true,
                ],
                [
                    'name' => 'Post-Incident Review (Management)',
                    'description' => 'Executive summary, budget for improvements, policy updates',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_MANAGER',
                    'daysToComplete' => 14,
                    'sortOrder' => 6,
                    'isRequired' => true,
                ],
            ],
        ];
    }

    /**
     * ISO 27001 Low/Medium Severity Incident Workflow
     */
    private function getIncidentLowSeverityWorkflow(): array
    {
        return [
            'name' => 'Incident Response - Low/Medium Severity',
            'description' => 'Standard incident response workflow for low and medium severity security incidents (ISO 27001:2022)',
            'entityType' => 'Incident',
            'isActive' => false, // Disabled by default (high-severity is default)
            'steps' => [
                [
                    'name' => 'Triage (Security Analyst)',
                    'description' => 'Classify incident, perform initial containment, escalate if needed',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_SECURITY_ANALYST',
                    'daysToComplete' => 0, // 4h SLA
                    'sortOrder' => 1,
                    'isRequired' => true,
                ],
                [
                    'name' => 'Investigation (Security Team)',
                    'description' => 'Root cause analysis, identify affected systems, plan remediation',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_SECURITY_MANAGER',
                    'daysToComplete' => 1, // 24h SLA
                    'sortOrder' => 2,
                    'isRequired' => true,
                ],
                [
                    'name' => 'Remediation (IT Operations)',
                    'description' => 'Fix vulnerability, apply patches, document changes',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_IT_MANAGER',
                    'daysToComplete' => 3, // 72h SLA
                    'sortOrder' => 3,
                    'isRequired' => true,
                ],
                [
                    'name' => 'Review (Security Manager)',
                    'description' => 'Lessons learned, process improvement recommendations',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_SECURITY_MANAGER',
                    'daysToComplete' => 7,
                    'sortOrder' => 4,
                    'isRequired' => true,
                ],
            ],
        ];
    }

    /**
     * ISO 27001 Risk Treatment Approval Workflow
     * SLA varies by risk value (low/medium/high)
     */
    private function getRiskTreatmentWorkflow(): array
    {
        return [
            'name' => 'Risk Treatment Plan Approval',
            'description' => 'Multi-tier approval workflow for risk treatment plans based on risk value (ISO 27001:2022 Clause 6.1.3)',
            'entityType' => 'Risk',
            'isActive' => true,
            'steps' => [
                [
                    'name' => 'Risk Owner Approval',
                    'description' => 'Risk owner reviews and approves proposed treatment plan',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_RISK_OWNER',
                    'daysToComplete' => 7, // Low risk: 7 days, Medium: 5 days, High: 3 days
                    'sortOrder' => 1,
                    'isRequired' => true,
                    'autoProgressConditions' => [
                        'type' => 'risk_appetite',
                        'entity' => 'Risk',
                        'riskScoreField' => 'residualRisk',
                        // Auto-approve if residual risk is within risk appetite
                        // This implements ISO 27005:2022 risk acceptance workflow
                    ],
                ],
                [
                    'name' => 'CISO Technical Review',
                    'description' => 'CISO evaluates technical security measures and implementation feasibility',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_CISO',
                    'daysToComplete' => 3, // Required for Medium and High risks only
                    'sortOrder' => 2,
                    'isRequired' => false, // Required only if risk value >= medium
                ],
                [
                    'name' => 'DPO Privacy Impact Review',
                    'description' => 'Data Protection Officer assesses privacy implications if personal data involved',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_DPO',
                    'daysToComplete' => 2, // Required for High risks with personal data
                    'sortOrder' => 3,
                    'isRequired' => false, // Required only if high risk + personal data
                ],
                [
                    'name' => 'CFO Budget Approval',
                    'description' => 'CFO approves budget for risk treatment measures',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_CFO',
                    'daysToComplete' => 3, // Required for Medium and High risks
                    'sortOrder' => 4,
                    'isRequired' => false, // Required only if risk value >= medium
                ],
                [
                    'name' => 'CEO/Board Approval',
                    'description' => 'Executive approval for high-value risk treatment plans',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_CEO',
                    'daysToComplete' => 3, // Required for High risks only
                    'sortOrder' => 5,
                    'isRequired' => false, // Required only if risk value >= high
                ],
                [
                    'name' => 'Audit Committee Notification',
                    'description' => 'Notify audit committee of approved high-risk treatment',
                    'stepType' => 'notification',
                    'approverRole' => 'ROLE_AUDITOR',
                    'daysToComplete' => 0, // Auto-progress (notification only)
                    'sortOrder' => 6,
                    'isRequired' => false, // Only for high risks
                ],
            ],
        ];
    }

    /**
     * GDPR Art. 35/36 - Data Protection Impact Assessment Workflow
     */
    private function getDpiaWorkflow(): array
    {
        return [
            'name' => 'DPIA - Data Protection Impact Assessment',
            'description' => 'Data Protection Impact Assessment workflow for high-risk processing activities (GDPR Art. 35/36)',
            'entityType' => 'DPIA',
            'isActive' => true,
            'steps' => [
                [
                    'name' => 'DPIA Creation (Data Owner)',
                    'description' => 'Systematic description of processing, necessity assessment, risk evaluation',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_DATA_OWNER',
                    'daysToComplete' => 14,
                    'sortOrder' => 1,
                    'isRequired' => true,
                ],
                [
                    'name' => 'DPO Review',
                    'description' => 'DPO reviews completeness and GDPR compliance',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_DPO',
                    'daysToComplete' => 7,
                    'sortOrder' => 2,
                    'isRequired' => true,
                ],
                [
                    'name' => 'Technical Security Review (CISO)',
                    'description' => 'CISO evaluates technical security measures and protection level',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_CISO',
                    'daysToComplete' => 5,
                    'sortOrder' => 3,
                    'isRequired' => true,
                ],
                [
                    'name' => 'Risk Assessment',
                    'description' => 'Risk manager evaluates residual risk and acceptance criteria',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_RISK_MANAGER',
                    'daysToComplete' => 5,
                    'sortOrder' => 4,
                    'isRequired' => true,
                ],
                [
                    'name' => 'Management Approval',
                    'description' => 'Management approves or rejects DPIA and allocates budget for measures',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_MANAGER',
                    'daysToComplete' => 3,
                    'sortOrder' => 5,
                    'isRequired' => true,
                ],
                [
                    'name' => 'DPO Final Check & Supervisory Authority Consultation',
                    'description' => 'DPO performs final check. If high residual risk remains, initiate prior consultation with supervisory authority (GDPR Art. 36)',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_DPO',
                    'daysToComplete' => 2,
                    'sortOrder' => 6,
                    'isRequired' => true,
                    'autoProgressConditions' => [
                        'type' => 'field_completion',
                        'entity' => 'DPIA',
                        'fields' => ['residualRiskLevel'],
                        'condition' => '(residualRiskLevel = low OR residualRiskLevel = medium) OR ((residualRiskLevel = high OR residualRiskLevel = critical) AND supervisoryConsultationDate != null)',
                        // GDPR Art. 36 Conditional Logic:
                        // - Low/Medium risk: auto-progress immediately (no consultation required)
                        // - High/Critical risk: auto-progress ONLY when supervisoryConsultationDate is filled
                    ],
                ],
            ],
        ];
    }

    /**
     * Create workflow entity from definition
     */
    private function createWorkflow(array $definition): Workflow
    {
        $workflow = new Workflow();
        $workflow->setName($definition['name']);
        $workflow->setDescription($definition['description']);
        $workflow->setEntityType($definition['entityType']);
        $workflow->setIsActive($definition['isActive']);

        foreach ($definition['steps'] as $stepData) {
            $step = new WorkflowStep();
            $step->setName($stepData['name']);
            $step->setDescription($stepData['description']);
            $step->setStepType($stepData['stepType']);
            $step->setApproverRole($stepData['approverRole']);
            $step->setDaysToComplete($stepData['daysToComplete']);
            $step->setStepOrder($stepData['sortOrder']);
            $step->setIsRequired($stepData['isRequired']);

            // Store auto-progression conditions as JSON metadata
            if (isset($stepData['autoProgressConditions'])) {
                $step->setMetadata([
                    'autoProgressConditions' => $stepData['autoProgressConditions'],
                ]);
            }

            $workflow->addStep($step);
        }

        return $workflow;
    }
}
