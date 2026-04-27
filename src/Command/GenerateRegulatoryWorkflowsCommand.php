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
            ->addOption('workflow', null, InputOption::VALUE_REQUIRED, 'Generate only specific workflow (data-breach, incident-high, incident-low, risk-treatment, dpia, dsr, capa, change-request, management-review, control-verification, supplier-assessment, training-verification, bc-plan-activation, document-review, incident-post-mortem)')
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
            // Phase 10.1 — CRITICAL
            'dsr' => $this->getDsrWorkflow(),
            'capa' => $this->getCapaWorkflow(),
            'change-request' => $this->getChangeRequestWorkflow(),
            'management-review' => $this->getManagementReviewWorkflow(),
            // Phase 10.2 — HIGH
            'control-verification' => $this->getControlVerificationWorkflow(),
            'supplier-assessment' => $this->getSupplierAssessmentWorkflow(),
            // Phase 10.3 — MEDIUM
            'training-verification' => $this->getTrainingVerificationWorkflow(),
            'bc-plan-activation' => $this->getBcPlanActivationWorkflow(),
            'document-review' => $this->getDocumentReviewWorkflow(),
            'incident-post-mortem' => $this->getIncidentPostMortemWorkflow(),
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
    /**
     * GDPR Art. 12(3) — Data Subject Request (30-day SLA)
     */
    private function getDsrWorkflow(): array
    {
        return [
            'name' => 'Data Subject Request Processing (GDPR Art. 12-22)',
            'description' => 'Workflow for processing data subject rights requests with 30-day response deadline',
            'entityType' => 'DataSubjectRequest',
            'isActive' => true,
            'metadata' => [
                'slaEnforcement' => true,
                'slaDeadlineHours' => 720, // 30 days
                'escalationThresholdHours' => 600, // Escalate 5 days before deadline
                'escalationRole' => 'ROLE_DPO',
            ],
            'steps' => [
                [
                    'name' => 'Identity Verification',
                    'description' => 'Verify the identity of the requesting data subject (GDPR Art. 12(6))',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_DPO',
                    'daysToComplete' => 3,
                    'sortOrder' => 1,
                    'isRequired' => true,
                    'autoProgressConditions' => [
                        'type' => 'field_completion',
                        'entity' => 'DataSubjectRequest',
                        'fields' => ['identityVerified', 'identityVerificationMethod'],
                    ],
                ],
                [
                    'name' => 'Request Processing',
                    'description' => 'Process the data subject request: gather data, prepare response',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_USER',
                    'daysToComplete' => 14,
                    'sortOrder' => 2,
                    'isRequired' => true,
                    'autoProgressConditions' => [
                        'type' => 'field_completion',
                        'entity' => 'DataSubjectRequest',
                        'fields' => ['responseDescription'],
                    ],
                ],
                [
                    'name' => 'DPO Review & Approval',
                    'description' => 'DPO reviews the prepared response for completeness and legal compliance',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_DPO',
                    'daysToComplete' => 5,
                    'sortOrder' => 3,
                    'isRequired' => true,
                ],
                [
                    'name' => 'Response Delivery',
                    'description' => 'Send the response to the data subject and document delivery',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_DPO',
                    'daysToComplete' => 3,
                    'sortOrder' => 4,
                    'isRequired' => true,
                    'autoProgressConditions' => [
                        'type' => 'field_completion',
                        'entity' => 'DataSubjectRequest',
                        'fields' => ['completedAt'],
                    ],
                ],
            ],
        ];
    }

    /**
     * ISO 27001 Clause 10.1 — Corrective Action (CAPA) Lifecycle
     */
    private function getCapaWorkflow(): array
    {
        return [
            'name' => 'Corrective Action Lifecycle (ISO 27001 Cl. 10.1)',
            'description' => 'CAPA workflow: from finding through root cause analysis to verified effectiveness',
            'entityType' => 'CorrectiveAction',
            'isActive' => true,
            'metadata' => [
                'slaEnforcement' => false,
            ],
            'steps' => [
                [
                    'name' => 'Root Cause Analysis',
                    'description' => 'Analyze the root cause of the nonconformity',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_USER',
                    'daysToComplete' => 14,
                    'sortOrder' => 1,
                    'isRequired' => true,
                    'autoProgressConditions' => [
                        'type' => 'field_completion',
                        'entity' => 'CorrectiveAction',
                        'fields' => ['rootCauseAnalysis'],
                    ],
                ],
                [
                    'name' => 'Action Plan Approval',
                    'description' => 'CISO approves the corrective action plan and timeline',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_CISO',
                    'daysToComplete' => 7,
                    'sortOrder' => 2,
                    'isRequired' => true,
                ],
                [
                    'name' => 'Implementation',
                    'description' => 'Execute the corrective actions as planned',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_USER',
                    'daysToComplete' => 30,
                    'sortOrder' => 3,
                    'isRequired' => true,
                    'autoProgressConditions' => [
                        'type' => 'field_completion',
                        'entity' => 'CorrectiveAction',
                        'fields' => ['actualCompletionDate'],
                    ],
                ],
                [
                    'name' => 'Effectiveness Verification',
                    'description' => 'Internal auditor verifies the corrective action is effective (ISO 27001 Cl. 10.1(f)). If ineffective, loops back to Implementation.',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_AUDITOR',
                    'daysToComplete' => 14,
                    'sortOrder' => 4,
                    'isRequired' => true,
                    'metadata' => [
                        'rejectAction' => 'loop_back',
                        'rejectTargetStep' => 3, // Back to Implementation
                    ],
                ],
                [
                    'name' => 'Closure',
                    'description' => 'CISO closes the corrective action after verified effectiveness',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_CISO',
                    'daysToComplete' => 3,
                    'sortOrder' => 5,
                    'isRequired' => true,
                ],
            ],
        ];
    }

    /**
     * ISO 27001 A.8.32 / Cl. 6.3 — Change Request (CAB) Approval
     */
    private function getChangeRequestWorkflow(): array
    {
        return [
            'name' => 'Change Request Approval (ISO 27001 A.8.32 / Cl. 6.3)',
            'description' => 'Change Advisory Board workflow with security risk assessment',
            'entityType' => 'ChangeRequest',
            'isActive' => true,
            'metadata' => [
                'slaEnforcement' => false,
            ],
            'steps' => [
                [
                    'name' => 'Security Risk Assessment',
                    'description' => 'CISO assesses the security impact of the proposed change',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_CISO',
                    'daysToComplete' => 5,
                    'sortOrder' => 1,
                    'isRequired' => true,
                    'autoProgressConditions' => [
                        'type' => 'field_completion',
                        'entity' => 'ChangeRequest',
                        'fields' => ['riskAssessment'],
                    ],
                ],
                [
                    'name' => 'CAB Review & Approval',
                    'description' => 'Change Advisory Board reviews and decides on the change request. Reject loops back to Security Risk Assessment.',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_MANAGER',
                    'daysToComplete' => 7,
                    'sortOrder' => 2,
                    'isRequired' => true,
                    'metadata' => [
                        'rejectAction' => 'loop_back',
                        'rejectTargetStep' => 1, // Back to Risk Assessment
                    ],
                ],
                [
                    'name' => 'Implementation',
                    'description' => 'Approved change is implemented according to plan',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_USER',
                    'daysToComplete' => 14,
                    'sortOrder' => 3,
                    'isRequired' => true,
                    'autoProgressConditions' => [
                        'type' => 'field_completion',
                        'entity' => 'ChangeRequest',
                        'fields' => ['actualImplementationDate'],
                    ],
                ],
                [
                    'name' => 'Post-Implementation Verification',
                    'description' => 'Verify change was implemented correctly and no side effects occurred',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_CISO',
                    'daysToComplete' => 5,
                    'sortOrder' => 4,
                    'isRequired' => true,
                ],
            ],
        ];
    }

    /**
     * ISO 27001 Clause 9.3 — Management Review
     */
    private function getManagementReviewWorkflow(): array
    {
        return [
            'name' => 'Management Review (ISO 27001 Cl. 9.3)',
            'description' => 'Structured management review workflow with input preparation and action tracking',
            'entityType' => 'ManagementReview',
            'isActive' => true,
            'metadata' => [
                'slaEnforcement' => false,
            ],
            'steps' => [
                [
                    'name' => 'Input Preparation',
                    'description' => 'ISB/CISO collects review inputs: audit results, KPIs, incident trends, risk status, stakeholder feedback (Cl. 9.3.2)',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_CISO',
                    'daysToComplete' => 14,
                    'sortOrder' => 1,
                    'isRequired' => true,
                    'autoProgressConditions' => [
                        'type' => 'field_completion',
                        'entity' => 'ManagementReview',
                        'fields' => ['auditResults', 'changesRelevantToISMS'],
                    ],
                ],
                [
                    'name' => 'Review Execution',
                    'description' => 'Management conducts the review meeting and documents decisions (Cl. 9.3.3)',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_ADMIN',
                    'daysToComplete' => 7,
                    'sortOrder' => 2,
                    'isRequired' => true,
                    'autoProgressConditions' => [
                        'type' => 'field_completion',
                        'entity' => 'ManagementReview',
                        'fields' => ['decisions', 'reviewDate'],
                    ],
                ],
                [
                    'name' => 'Action Planning',
                    'description' => 'Document improvement actions, assign owners and deadlines',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_CISO',
                    'daysToComplete' => 7,
                    'sortOrder' => 3,
                    'isRequired' => true,
                    'autoProgressConditions' => [
                        'type' => 'field_completion',
                        'entity' => 'ManagementReview',
                        'fields' => ['actionItems'],
                    ],
                ],
                [
                    'name' => 'Follow-up Verification',
                    'description' => 'Verify action items are implemented as planned',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_CISO',
                    'daysToComplete' => 30,
                    'sortOrder' => 4,
                    'isRequired' => true,
                ],
            ],
        ];
    }

    /**
     * ISO 27001 Clause 8 — Control Implementation Verification
     */
    private function getControlVerificationWorkflow(): array
    {
        return [
            'name' => 'Control Implementation Verification (ISO 27001 Cl. 8)',
            'description' => 'Multi-tier verification when a control status changes to implemented',
            'entityType' => 'Control',
            'isActive' => true,
            'metadata' => [
                'slaEnforcement' => false,
            ],
            'steps' => [
                [
                    'name' => 'Risk Owner Certification',
                    'description' => 'Risk owner confirms the control is implemented as designed',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_USER',
                    'daysToComplete' => 7,
                    'sortOrder' => 1,
                    'isRequired' => true,
                ],
                [
                    'name' => 'CISO Technical Validation',
                    'description' => 'CISO validates the technical implementation and evidence',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_CISO',
                    'daysToComplete' => 7,
                    'sortOrder' => 2,
                    'isRequired' => true,
                ],
                [
                    'name' => 'Auditor Acceptance',
                    'description' => 'Internal auditor accepts the control as verified (updates SoA). Reject loops back to Risk Owner for re-certification.',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_AUDITOR',
                    'daysToComplete' => 5,
                    'sortOrder' => 3,
                    'isRequired' => true,
                    'metadata' => [
                        'rejectAction' => 'loop_back',
                        'rejectTargetStep' => 1, // Back to Risk Owner
                    ],
                ],
            ],
        ];
    }

    /**
     * ISO 27001 A.5.19-22 — Supplier Security Assessment
     */
    private function getSupplierAssessmentWorkflow(): array
    {
        return [
            'name' => 'Supplier Security Assessment (ISO 27001 A.5.19-22 / GDPR Art. 28 / DORA Art. 28-30)',
            'description' => 'Comprehensive supplier security assessment with DPO review for data processors, DORA assessment for ICT providers, and contract verification',
            'entityType' => 'Supplier',
            'isActive' => true,
            'metadata' => [
                'slaEnforcement' => false,
                'rejectLoopBack' => true, // Rejection at any step loops back to Step 1
            ],
            'steps' => [
                [
                    'name' => 'Initial Security Assessment',
                    'description' => 'Complete security questionnaire, classify criticality (critical/high/medium/low), and perform initial risk assessment',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_USER',
                    'daysToComplete' => 14,
                    'sortOrder' => 1,
                    'isRequired' => true,
                    'autoProgressConditions' => [
                        'type' => 'field_completion',
                        'entity' => 'Supplier',
                        'fields' => ['criticality', 'lastSecurityAssessment'],
                    ],
                ],
                [
                    'name' => 'DPO Privacy Review (GDPR Art. 28)',
                    'description' => 'DPO reviews if supplier processes personal data: verify DPA/AVV exists, check data transfer safeguards, assess sub-processor chain',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_DPO',
                    'daysToComplete' => 7,
                    'sortOrder' => 2,
                    'isRequired' => false, // Only required if supplier is a data processor
                    'autoProgressConditions' => [
                        'type' => 'field_completion',
                        'entity' => 'Supplier',
                        'fields' => ['gdprAvContractSigned'],
                        'condition' => 'gdprProcessorStatus != none',
                    ],
                ],
                [
                    'name' => 'Contract & SLA Verification',
                    'description' => 'Verify security requirements in contract: SLAs, audit rights, incident notification, exit strategy, liability clauses',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_CISO',
                    'daysToComplete' => 7,
                    'sortOrder' => 3,
                    'isRequired' => true,
                    'autoProgressConditions' => [
                        'type' => 'field_completion',
                        'entity' => 'Supplier',
                        'fields' => ['contractStartDate'],
                    ],
                ],
                [
                    'name' => 'DORA ICT Assessment (Financial Sector)',
                    'description' => 'For ICT third-party providers: assess substitutability, concentration risk, exit strategy (DORA Art. 28-30)',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_CISO',
                    'daysToComplete' => 7,
                    'sortOrder' => 4,
                    'isRequired' => false, // Only for ICT providers in financial sector
                    'autoProgressConditions' => [
                        'type' => 'field_completion',
                        'entity' => 'Supplier',
                        'fields' => ['ictCriticality', 'substitutability', 'hasExitStrategy'],
                        'condition' => 'ictCriticality != null',
                    ],
                ],
                [
                    'name' => 'Management Onboarding Approval',
                    'description' => 'Final management decision based on all assessments. Reject loops back to Initial Assessment for rework.',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_MANAGER',
                    'daysToComplete' => 5,
                    'sortOrder' => 5,
                    'isRequired' => true,
                    'metadata' => [
                        'rejectAction' => 'loop_back',
                        'rejectTargetStep' => 1, // Loop back to Initial Assessment
                    ],
                ],
            ],
        ];
    }

    /**
     * ISO 27001 A.6.3 — Training Completion Verification
     */
    private function getTrainingVerificationWorkflow(): array
    {
        return [
            'name' => 'Training Completion Verification (ISO 27001 A.6.3)',
            'description' => 'Verify training completion with manager sign-off',
            'entityType' => 'Training',
            'isActive' => true,
            'metadata' => ['slaEnforcement' => false],
            'steps' => [
                [
                    'name' => 'Training Completion',
                    'description' => 'Participant completes the assigned training',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_USER',
                    'daysToComplete' => 30,
                    'sortOrder' => 1,
                    'isRequired' => true,
                    'autoProgressConditions' => [
                        'type' => 'field_completion',
                        'entity' => 'Training',
                        'fields' => ['completionDate'],
                    ],
                ],
                [
                    'name' => 'Manager Verification',
                    'description' => 'Manager confirms training was completed satisfactorily',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_MANAGER',
                    'daysToComplete' => 7,
                    'sortOrder' => 2,
                    'isRequired' => true,
                ],
            ],
        ];
    }

    /**
     * ISO 22301 — BC Plan Activation & Deactivation
     */
    private function getBcPlanActivationWorkflow(): array
    {
        return [
            'name' => 'BC Plan Activation (ISO 22301)',
            'description' => 'Crisis declaration triggers plan activation, execution tracking, and post-incident review',
            'entityType' => 'BusinessContinuityPlan',
            'isActive' => true,
            'metadata' => [
                'slaEnforcement' => true,
                'slaDeadlineHours' => 1, // Activation must happen within 1h of crisis
                'escalationRole' => 'ROLE_ADMIN',
            ],
            'steps' => [
                [
                    'name' => 'Crisis Declaration',
                    'description' => 'Crisis manager declares crisis and activates BC plan',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_MANAGER',
                    'daysToComplete' => 0, // Immediate
                    'sortOrder' => 1,
                    'isRequired' => true,
                ],
                [
                    'name' => 'Crisis Team Notification',
                    'description' => 'All crisis team members are notified and confirm availability',
                    'stepType' => 'notification',
                    'approverRole' => 'ROLE_USER',
                    'daysToComplete' => 0,
                    'sortOrder' => 2,
                    'isRequired' => true,
                    'autoProgressConditions' => [
                        'type' => 'auto',
                        'condition' => 'status = active',
                    ],
                ],
                [
                    'name' => 'Recovery Execution',
                    'description' => 'Execute recovery procedures as documented in the BC plan',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_MANAGER',
                    'daysToComplete' => 7,
                    'sortOrder' => 3,
                    'isRequired' => true,
                ],
                [
                    'name' => 'Plan Deactivation',
                    'description' => 'Crisis resolved — deactivate plan and return to normal operations',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_MANAGER',
                    'daysToComplete' => 1,
                    'sortOrder' => 4,
                    'isRequired' => true,
                ],
                [
                    'name' => 'Post-Incident Review',
                    'description' => 'Conduct post-incident review: lessons learned, plan updates needed',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_CISO',
                    'daysToComplete' => 14,
                    'sortOrder' => 5,
                    'isRequired' => true,
                ],
            ],
        ];
    }

    /**
     * ISO 27001 Clause 7.5.3 — Document Review Cycle
     */
    private function getDocumentReviewWorkflow(): array
    {
        return [
            'name' => 'Document Review Cycle (ISO 27001 Cl. 7.5.3)',
            'description' => 'Periodic document review with revision and re-approval workflow',
            'entityType' => 'Document',
            'isActive' => true,
            'metadata' => ['slaEnforcement' => false],
            'steps' => [
                [
                    'name' => 'Review Required',
                    'description' => 'Document owner reviews content for accuracy and currency',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_USER',
                    'daysToComplete' => 14,
                    'sortOrder' => 1,
                    'isRequired' => true,
                ],
                [
                    'name' => 'Revision Draft',
                    'description' => 'If changes needed: document owner creates revision draft',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_USER',
                    'daysToComplete' => 14,
                    'sortOrder' => 2,
                    'isRequired' => false,
                ],
                [
                    'name' => 'Approval',
                    'description' => 'CISO or document approver signs off on revised document',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_CISO',
                    'daysToComplete' => 7,
                    'sortOrder' => 3,
                    'isRequired' => true,
                ],
            ],
        ];
    }

    /**
     * ISO 27001 — Incident Post-Mortem & Lessons Learned
     */
    private function getIncidentPostMortemWorkflow(): array
    {
        return [
            'name' => 'Incident Post-Mortem (ISO 27001)',
            'description' => 'Structured post-incident review with lessons learned and improvement actions',
            'entityType' => 'Incident',
            'isActive' => false, // Opt-in — not auto-triggered
            'metadata' => ['slaEnforcement' => false],
            'steps' => [
                [
                    'name' => 'Schedule Post-Mortem',
                    'description' => 'Schedule post-mortem review within 5 days after incident closure',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_CISO',
                    'daysToComplete' => 5,
                    'sortOrder' => 1,
                    'isRequired' => true,
                ],
                [
                    'name' => 'Conduct Review',
                    'description' => 'Execute post-mortem: timeline, root cause, what went well, improvement areas',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_CISO',
                    'daysToComplete' => 7,
                    'sortOrder' => 2,
                    'isRequired' => true,
                    'autoProgressConditions' => [
                        'type' => 'field_completion',
                        'entity' => 'Incident',
                        'fields' => ['rootCause', 'lessonsLearned'],
                    ],
                ],
                [
                    'name' => 'Create Improvement Actions',
                    'description' => 'Document corrective/preventive actions with owners and deadlines',
                    'stepType' => 'approval',
                    'approverRole' => 'ROLE_CISO',
                    'daysToComplete' => 7,
                    'sortOrder' => 3,
                    'isRequired' => true,
                    'autoProgressConditions' => [
                        'type' => 'field_completion',
                        'entity' => 'Incident',
                        'fields' => ['correctiveActions', 'preventiveActions'],
                    ],
                ],
            ],
        ];
    }

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
