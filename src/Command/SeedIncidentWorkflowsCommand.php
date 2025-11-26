<?php

namespace App\Command;

use App\Entity\Workflow;
use App\Entity\WorkflowStep;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:seed:incident-workflows',
    description: 'Seeds incident escalation workflow definitions into the database',
)]
class SeedIncidentWorkflowsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Seeding Incident Escalation Workflows');

        $workflows = $this->getWorkflowDefinitions();

        $this->entityManager->beginTransaction();

        try {
            $createdCount = 0;
            $skippedCount = 0;

            foreach ($workflows as $workflowData) {
                // Check if workflow already exists by name
                $existingWorkflow = $this->entityManager->getRepository(Workflow::class)
                    ->findOneBy(['name' => $workflowData['name']]);

                if ($existingWorkflow) {
                    $io->text(sprintf('  <fg=gray>Skipped:</> %s (already exists)', $workflowData['name']));
                    $skippedCount++;
                    continue;
                }

                // Create new workflow
                $workflow = new Workflow();
                $workflow->setName($workflowData['name']);
                $workflow->setDescription($workflowData['description']);
                $workflow->setEntityType($workflowData['entityType']);
                $workflow->setIsActive(true);

                // Add steps
                foreach ($workflowData['steps'] as $stepData) {
                    $step = new WorkflowStep();
                    $step->setName($stepData['name']);
                    $step->setDescription($stepData['description']);
                    $step->setStepOrder($stepData['order']);
                    $step->setStepType($stepData['type']);
                    $step->setApproverRole($stepData['role']);
                    $step->setIsRequired(true);
                    $step->setDaysToComplete($stepData['daysToComplete']);

                    $workflow->addStep($step);
                }

                $this->entityManager->persist($workflow);
                $createdCount++;
                $io->text(sprintf('  <info>Created:</info> %s (%d steps)', $workflowData['name'], count($workflowData['steps'])));
            }

            $this->entityManager->flush();
            $this->entityManager->commit();

            $io->newLine();
            $io->success(sprintf(
                'Incident workflows seeded successfully! Created: %d, Skipped: %d',
                $createdCount,
                $skippedCount
            ));

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            $io->error('Failed to seed workflows: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Get workflow definitions for incident escalation.
     *
     * @return array<int, array{name: string, description: string, entityType: string, steps: array}>
     */
    private function getWorkflowDefinitions(): array
    {
        return [
            // 1. Medium Severity Incident Workflow
            [
                'name' => 'Medium Severity Incident',
                'description' => 'Standard workflow for medium severity incidents requiring incident manager review and resolution verification.',
                'entityType' => 'Incident',
                'steps' => [
                    [
                        'name' => 'Incident Manager Review',
                        'description' => 'Incident Manager reviews the incident details, assesses impact, and determines appropriate response actions.',
                        'order' => 1,
                        'type' => 'approval',
                        'role' => 'ROLE_INCIDENT_MANAGER',
                        'daysToComplete' => 1, // 24 hours = 1 day
                    ],
                    [
                        'name' => 'Resolution Verification',
                        'description' => 'Incident Manager verifies that the incident has been properly resolved and documented.',
                        'order' => 2,
                        'type' => 'approval',
                        'role' => 'ROLE_INCIDENT_MANAGER',
                        'daysToComplete' => 1, // 24 hours = 1 day
                    ],
                ],
            ],

            // 2. High Severity Incident Workflow
            [
                'name' => 'High Severity Incident',
                'description' => 'Escalated workflow for high severity incidents requiring both Incident Manager and CISO involvement with strict SLAs.',
                'entityType' => 'Incident',
                'steps' => [
                    [
                        'name' => 'Incident Manager Assessment',
                        'description' => 'Incident Manager performs initial assessment of the high severity incident, documenting scope and potential impact.',
                        'order' => 1,
                        'type' => 'approval',
                        'role' => 'ROLE_INCIDENT_MANAGER',
                        'daysToComplete' => 1, // 8 hours rounded up to 1 day
                    ],
                    [
                        'name' => 'CISO Review',
                        'description' => 'CISO reviews the incident assessment and provides strategic direction for response and mitigation.',
                        'order' => 2,
                        'type' => 'approval',
                        'role' => 'ROLE_CISO',
                        'daysToComplete' => 1, // 8 hours rounded up to 1 day
                    ],
                    [
                        'name' => 'Mitigation Approval',
                        'description' => 'CISO approves the proposed mitigation strategy and authorizes implementation of remediation measures.',
                        'order' => 3,
                        'type' => 'approval',
                        'role' => 'ROLE_CISO',
                        'daysToComplete' => 1, // 8 hours rounded up to 1 day
                    ],
                ],
            ],

            // 3. Critical Incident Response Workflow
            [
                'name' => 'Critical Incident Response',
                'description' => 'Emergency response workflow for critical incidents requiring immediate action and escalation through multiple management levels.',
                'entityType' => 'Incident',
                'steps' => [
                    [
                        'name' => 'Immediate Response Team Notification',
                        'description' => 'Notify the incident response team immediately to begin containment and initial response actions.',
                        'order' => 1,
                        'type' => 'notification',
                        'role' => 'ROLE_INCIDENT_MANAGER',
                        'daysToComplete' => 1, // 2 hours rounded up to 1 day
                    ],
                    [
                        'name' => 'CISO Escalation',
                        'description' => 'Escalate to CISO for critical incident assessment and strategic decision-making authority.',
                        'order' => 2,
                        'type' => 'approval',
                        'role' => 'ROLE_CISO',
                        'daysToComplete' => 1, // 2 hours rounded up to 1 day
                    ],
                    [
                        'name' => 'Management Approval',
                        'description' => 'Senior management approval for major response actions, resource allocation, and external communications.',
                        'order' => 3,
                        'type' => 'approval',
                        'role' => 'ROLE_MANAGER',
                        'daysToComplete' => 1, // 4 hours rounded up to 1 day
                    ],
                    [
                        'name' => 'Post-Incident Review',
                        'description' => 'CISO-led comprehensive post-incident review to identify lessons learned and implement preventive measures.',
                        'order' => 4,
                        'type' => 'approval',
                        'role' => 'ROLE_CISO',
                        'daysToComplete' => 1, // 24 hours = 1 day
                    ],
                ],
            ],

            // 4. Data Breach Notification Workflow (GDPR)
            [
                'name' => 'Data Breach Notification',
                'description' => 'GDPR-compliant workflow for personal data breach incidents requiring DPO assessment and potential regulatory notification within 72 hours.',
                'entityType' => 'Incident',
                'steps' => [
                    [
                        'name' => 'DPO Assessment',
                        'description' => 'Data Protection Officer assesses the data breach for GDPR compliance, determining if notification to supervisory authority is required.',
                        'order' => 1,
                        'type' => 'approval',
                        'role' => 'ROLE_DPO',
                        'daysToComplete' => 1, // 1 hour rounded up to 1 day
                    ],
                    [
                        'name' => 'CISO Review',
                        'description' => 'CISO reviews the DPO assessment and coordinates technical investigation of the data breach scope and impact.',
                        'order' => 2,
                        'type' => 'approval',
                        'role' => 'ROLE_CISO',
                        'daysToComplete' => 1, // 2 hours rounded up to 1 day
                    ],
                    [
                        'name' => 'CEO Approval for Notification',
                        'description' => 'CEO approves notification strategy for supervisory authority and affected data subjects, including public communications.',
                        'order' => 3,
                        'type' => 'approval',
                        'role' => 'ROLE_CEO',
                        'daysToComplete' => 1, // 4 hours rounded up to 1 day
                    ],
                    [
                        'name' => 'Supervisory Authority Notification',
                        'description' => 'DPO submits formal notification to supervisory authority as required by GDPR Article 33 (within 72 hours of awareness).',
                        'order' => 4,
                        'type' => 'notification',
                        'role' => 'ROLE_DPO',
                        'daysToComplete' => 3, // 72 hours = 3 days
                    ],
                ],
            ],
        ];
    }
}
