<?php

namespace App\Command;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:load-bsi-requirements',
    description: 'Load BSI IT-Grundschutz 200-4 (BCM) requirements with ISO 22301 mappings'
)]
class LoadBsiRequirementsCommand extends Command
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Create or get BSI framework
        $framework = $this->entityManager->getRepository(ComplianceFramework::class)
            ->findOneBy(['code' => 'BSI-200-4']);

        if (!$framework) {
            $framework = new ComplianceFramework();
            $framework->setCode('BSI-200-4')
                ->setName('BSI IT-Grundschutz 200-4 (BCM)')
                ->setDescription('BSI-Standard 200-4: Business Continuity Management')
                ->setVersion('200-4')
                ->setApplicableIndustry('all')
                ->setRegulatoryBody('BSI (Bundesamt für Sicherheit in der Informationstechnik)')
                ->setMandatory(false)
                ->setScopeDescription('Business Continuity Management methodology for IT-Grundschutz')
                ->setActive(true);

            $this->entityManager->persist($framework);
        }

        $requirements = $this->getBsiRequirements();

        foreach ($requirements as $reqData) {
            $requirement = new ComplianceRequirement();
            $requirement->setFramework($framework)
                ->setRequirementId($reqData['id'])
                ->setTitle($reqData['title'])
                ->setDescription($reqData['description'])
                ->setCategory($reqData['category'])
                ->setPriority($reqData['priority'])
                ->setDataSourceMapping($reqData['data_source_mapping']);

            $this->entityManager->persist($requirement);
        }

        $this->entityManager->flush();

        $io->success(sprintf('Successfully loaded %d BSI 200-4 requirements', count($requirements)));

        return Command::SUCCESS;
    }

    private function getBsiRequirements(): array
    {
        return [
            // Chapter 3: BCM Strategy
            [
                'id' => 'BSI-3.1',
                'title' => 'BCM Policy',
                'description' => 'A BCM policy shall be established, documented and communicated throughout the organization.',
                'category' => 'BCM Strategy',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_22301_controls' => ['4.1', '5.2'],
                    'iso_27001_controls' => ['5.1'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'BSI-3.2',
                'title' => 'BCM Objectives',
                'description' => 'BCM objectives shall be defined and aligned with organizational objectives.',
                'category' => 'BCM Strategy',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_22301_controls' => ['5.2'],
                    'iso_27001_controls' => ['6.2'],
                ],
            ],
            [
                'id' => 'BSI-3.3',
                'title' => 'BCM Scope',
                'description' => 'The scope of the BCM system shall be determined and documented.',
                'category' => 'BCM Strategy',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_22301_controls' => ['4.3'],
                ],
            ],

            // Chapter 4: BCM Organization
            [
                'id' => 'BSI-4.1',
                'title' => 'Management Responsibility',
                'description' => 'Top management shall demonstrate leadership and commitment to the BCM system.',
                'category' => 'BCM Organization',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_22301_controls' => ['5.1'],
                    'iso_27001_controls' => ['5.1', '5.3'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'BSI-4.2',
                'title' => 'BCM Coordinator',
                'description' => 'A BCM coordinator shall be appointed with defined responsibilities and authorities.',
                'category' => 'BCM Organization',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_22301_controls' => ['5.3'],
                ],
            ],
            [
                'id' => 'BSI-4.3',
                'title' => 'Crisis Management Team',
                'description' => 'A crisis management team (Krisenstab) shall be established with defined roles, responsibilities and communication procedures.',
                'category' => 'BCM Organization',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_22301_controls' => ['5.3', '8.4'],
                    'crisis_team_required' => true,
                ],
            ],
            [
                'id' => 'BSI-4.4',
                'title' => 'BCM Roles and Responsibilities',
                'description' => 'Roles and responsibilities for BCM shall be assigned throughout the organization.',
                'category' => 'BCM Organization',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_22301_controls' => ['5.3'],
                ],
            ],

            // Chapter 5: Business Impact Analysis
            [
                'id' => 'BSI-5.1',
                'title' => 'Business Impact Analysis Process',
                'description' => 'A systematic process for conducting business impact analysis shall be established.',
                'category' => 'Business Impact Analysis',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_22301_controls' => ['8.2'],
                    'bcm_required' => true,
                ],
            ],
            [
                'id' => 'BSI-5.2',
                'title' => 'Critical Business Processes',
                'description' => 'Critical business processes shall be identified and prioritized.',
                'category' => 'Business Impact Analysis',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_22301_controls' => ['8.2.2'],
                    'bcm_required' => true,
                ],
            ],
            [
                'id' => 'BSI-5.3',
                'title' => 'Recovery Time Objectives (RTO)',
                'description' => 'Recovery time objectives shall be determined for critical processes.',
                'category' => 'Business Impact Analysis',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_22301_controls' => ['8.2.3'],
                    'bcm_required' => true,
                ],
            ],
            [
                'id' => 'BSI-5.4',
                'title' => 'Recovery Point Objectives (RPO)',
                'description' => 'Recovery point objectives shall be determined for critical data.',
                'category' => 'Business Impact Analysis',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_22301_controls' => ['8.2.3'],
                    'bcm_required' => true,
                ],
            ],
            [
                'id' => 'BSI-5.5',
                'title' => 'Maximum Tolerable Period of Disruption (MTPD)',
                'description' => 'The maximum tolerable period of disruption shall be determined.',
                'category' => 'Business Impact Analysis',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_22301_controls' => ['8.2.3'],
                    'bcm_required' => true,
                ],
            ],

            // Chapter 6: Risk Assessment
            [
                'id' => 'BSI-6.1',
                'title' => 'BCM Risk Assessment',
                'description' => 'Risks that could disrupt critical business processes shall be identified and assessed.',
                'category' => 'Risk Assessment',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_22301_controls' => ['8.2.3'],
                    'iso_27001_controls' => ['6.1.2'],
                ],
            ],
            [
                'id' => 'BSI-6.2',
                'title' => 'Threat and Vulnerability Analysis',
                'description' => 'Threats and vulnerabilities relevant to business continuity shall be analyzed.',
                'category' => 'Risk Assessment',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_22301_controls' => ['8.2.3'],
                ],
            ],

            // Chapter 7: BC Strategy
            [
                'id' => 'BSI-7.1',
                'title' => 'BC Strategy Development',
                'description' => 'Business continuity strategies shall be developed based on BIA and risk assessment results.',
                'category' => 'BC Strategy',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_22301_controls' => ['8.3'],
                    'bcm_required' => true,
                ],
            ],
            [
                'id' => 'BSI-7.2',
                'title' => 'Selection of BC Solutions',
                'description' => 'Appropriate business continuity solutions shall be selected and justified.',
                'category' => 'BC Strategy',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_22301_controls' => ['8.3'],
                ],
            ],

            // Chapter 8: Business Continuity Plans
            [
                'id' => 'BSI-8.1',
                'title' => 'BC Plan Structure',
                'description' => 'Business continuity plans shall be developed with a clear structure and content.',
                'category' => 'BC Planning',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_22301_controls' => ['8.4'],
                    'bcm_required' => true,
                ],
            ],
            [
                'id' => 'BSI-8.2',
                'title' => 'Emergency Response Procedures',
                'description' => 'Procedures for initial emergency response shall be documented.',
                'category' => 'BC Planning',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_22301_controls' => ['8.4.2'],
                    'bcm_required' => true,
                ],
            ],
            [
                'id' => 'BSI-8.3',
                'title' => 'Recovery Procedures',
                'description' => 'Detailed recovery procedures shall be documented for critical processes.',
                'category' => 'BC Planning',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_22301_controls' => ['8.4.3'],
                    'bcm_required' => true,
                ],
            ],
            [
                'id' => 'BSI-8.4',
                'title' => 'Communication Plans',
                'description' => 'Communication procedures for emergencies shall be established.',
                'category' => 'BC Planning',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_22301_controls' => ['8.4.2'],
                    'iso_27001_controls' => ['5.26'],
                ],
            ],

            // Chapter 9: Testing and Exercises
            [
                'id' => 'BSI-9.1',
                'title' => 'Test and Exercise Programme',
                'description' => 'A programme for testing and exercising BC plans shall be established.',
                'category' => 'Testing',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_22301_controls' => ['8.5'],
                    'bcm_required' => true,
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'BSI-9.2',
                'title' => 'Test Scenarios',
                'description' => 'Realistic test scenarios shall be developed and executed.',
                'category' => 'Testing',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_22301_controls' => ['8.5'],
                ],
            ],
            [
                'id' => 'BSI-9.3',
                'title' => 'Test Documentation',
                'description' => 'Test results shall be documented and analyzed.',
                'category' => 'Testing',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_22301_controls' => ['8.5'],
                    'audit_evidence' => true,
                ],
            ],

            // Chapter 10: Awareness and Training
            [
                'id' => 'BSI-10.1',
                'title' => 'BCM Awareness Programme',
                'description' => 'An awareness programme for business continuity shall be implemented.',
                'category' => 'Training & Awareness',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_22301_controls' => ['7.3'],
                    'iso_27001_controls' => ['6.3'],
                    'training_required' => true,
                ],
            ],
            [
                'id' => 'BSI-10.2',
                'title' => 'BCM Training',
                'description' => 'Training shall be provided to all personnel involved in BC activities.',
                'category' => 'Training & Awareness',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_22301_controls' => ['7.3'],
                    'training_required' => true,
                ],
            ],

            // Chapter 11: Maintenance and Review
            [
                'id' => 'BSI-11.1',
                'title' => 'BCM Monitoring',
                'description' => 'The BCM system shall be monitored and measured.',
                'category' => 'Maintenance',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_22301_controls' => ['9.1'],
                ],
            ],
            [
                'id' => 'BSI-11.2',
                'title' => 'BCM Review',
                'description' => 'Regular reviews of the BCM system shall be conducted.',
                'category' => 'Maintenance',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_22301_controls' => ['9.3'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'BSI-11.3',
                'title' => 'Plan Maintenance',
                'description' => 'BC plans shall be kept up-to-date through regular reviews and updates.',
                'category' => 'Maintenance',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_22301_controls' => ['8.4', '8.5'],
                ],
            ],

            // Chapter 12: Integration with ISMS
            [
                'id' => 'BSI-12.1',
                'title' => 'ISMS Integration',
                'description' => 'BCM shall be integrated with the information security management system.',
                'category' => 'Integration',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_27001_controls' => ['5.29', '5.30'],
                ],
            ],
            [
                'id' => 'BSI-12.2',
                'title' => 'IT Security in BCM',
                'description' => 'IT security aspects shall be considered in business continuity planning.',
                'category' => 'Integration',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_27001_controls' => ['5.29', '5.30'],
                ],
            ],

            // Additional Requirements
            [
                'id' => 'BSI-13.1',
                'title' => 'Resource Requirements',
                'description' => 'Resources required for BC activities shall be determined and provided.',
                'category' => 'Resources',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_22301_controls' => ['7.1'],
                ],
            ],
            [
                'id' => 'BSI-13.2',
                'title' => 'Documented Information',
                'description' => 'Documented information required by BCM shall be controlled.',
                'category' => 'Documentation',
                'priority' => 'medium',
                'data_source_mapping' => [
                    'iso_22301_controls' => ['7.5'],
                ],
            ],
            [
                'id' => 'BSI-13.3',
                'title' => 'Supplier Continuity',
                'description' => 'Continuity arrangements with suppliers shall be established.',
                'category' => 'Supply Chain',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_22301_controls' => ['8.4.4'],
                    'iso_27001_controls' => ['5.19', '5.20', '5.21'],
                ],
            ],
        ];
    }
}
