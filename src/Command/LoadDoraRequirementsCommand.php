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
    name: 'app:load-dora-requirements',
    description: 'Load EU-DORA (Digital Operational Resilience Act) requirements with ISMS data mappings'
)]
class LoadDoraRequirementsCommand extends Command
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Create or get DORA framework
        $framework = $this->entityManager->getRepository(ComplianceFramework::class)
            ->findOneBy(['code' => 'DORA']);

        if (!$framework) {
            $framework = new ComplianceFramework();
            $framework->setCode('DORA')
                ->setName('EU-DORA (Digital Operational Resilience Act)')
                ->setDescription('Regulation on digital operational resilience for the financial sector')
                ->setVersion('2022/2554')
                ->setApplicableIndustry('financial_services')
                ->setRegulatoryBody('European Union')
                ->setMandatory(true)
                ->setScopeDescription('Applies to financial entities including banks, insurance companies, investment firms, and critical ICT third-party service providers')
                ->setActive(true);

            $this->entityManager->persist($framework);
        }

        $requirements = $this->getDoraRequirements();

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

        $io->success(sprintf('Successfully loaded %d EU-DORA requirements', count($requirements)));

        return Command::SUCCESS;
    }

    private function getDoraRequirements(): array
    {
        return [
            // Chapter II: ICT Risk Management
            [
                'id' => 'DORA-6.1',
                'title' => 'ICT Risk Management Framework',
                'description' => 'Financial entities shall have in place an internal governance and control framework that ensures an effective and prudent management of ICT risk.',
                'category' => 'ICT Risk Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1', '5.2', '5.3'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'DORA-6.2',
                'title' => 'Business Continuity Policy',
                'description' => 'The ICT risk management framework shall include strategies, policies, procedures and ICT protocols to maintain business continuity, including for all legacy systems.',
                'category' => 'ICT Risk Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.29', '5.30'],
                    'bcm_required' => true,
                ],
            ],
            [
                'id' => 'DORA-8.1',
                'title' => 'Identification of ICT Risk',
                'description' => 'Financial entities shall identify all information and ICT assets, including remote access thereto, and shall map those considered critical.',
                'category' => 'ICT Risk Identification',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.9', '8.11'],
                    'asset_types' => ['hardware', 'software', 'data', 'services', 'cloud'],
                ],
            ],
            [
                'id' => 'DORA-8.2',
                'title' => 'ICT Assets Inventory',
                'description' => 'Financial entities shall have in place and maintain relevant ICT asset inventories, including third-party provided assets.',
                'category' => 'ICT Risk Identification',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.9'],
                    'asset_types' => ['hardware', 'software', 'data', 'services', 'cloud'],
                ],
            ],
            [
                'id' => 'DORA-8.3',
                'title' => 'Continuous Monitoring of ICT Risk',
                'description' => 'Financial entities shall continuously monitor and assess ICT risks, adapting the risk management framework as needed.',
                'category' => 'ICT Risk Identification',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.8', '8.15'],
                ],
            ],
            [
                'id' => 'DORA-9.1',
                'title' => 'ICT Business Continuity Plans',
                'description' => 'Financial entities shall develop and document ICT business continuity plans and ICT disaster recovery plans.',
                'category' => 'ICT Business Continuity',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.30'],
                    'bcm_required' => true,
                ],
            ],
            [
                'id' => 'DORA-9.2',
                'title' => 'Testing of Business Continuity',
                'description' => 'Financial entities shall test the ICT business continuity plans and ICT disaster recovery plans at least annually.',
                'category' => 'ICT Business Continuity',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.30'],
                    'bcm_required' => true,
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'DORA-9.3',
                'title' => 'Recovery Time Objectives',
                'description' => 'Financial entities shall set recovery time objectives (RTO) and recovery point objectives (RPO) for each critical function.',
                'category' => 'ICT Business Continuity',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.30'],
                    'bcm_required' => true,
                ],
            ],
            [
                'id' => 'DORA-9.4',
                'title' => 'Communication Plans',
                'description' => 'Financial entities shall have crisis communication plans enabling responsible disclosure of ICT-related incidents.',
                'category' => 'ICT Business Continuity',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.24', '5.26'],
                    'incident_management' => true,
                ],
            ],
            [
                'id' => 'DORA-10.1',
                'title' => 'Response and Recovery Procedures',
                'description' => 'Financial entities shall establish, maintain and test appropriate ICT response and recovery plans.',
                'category' => 'Response and Recovery',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.30'],
                    'bcm_required' => true,
                    'incident_management' => true,
                ],
            ],

            // Chapter III: ICT-Related Incident Management
            [
                'id' => 'DORA-17.1',
                'title' => 'Incident Management Process',
                'description' => 'Financial entities shall define, establish and implement an ICT-related incident management process to detect, manage and notify ICT-related incidents.',
                'category' => 'Incident Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.24', '5.25', '5.26'],
                    'incident_management' => true,
                ],
            ],
            [
                'id' => 'DORA-17.2',
                'title' => 'Classification of Incidents',
                'description' => 'Financial entities shall classify ICT-related incidents and determine their impact.',
                'category' => 'Incident Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.24', '5.25'],
                    'incident_management' => true,
                ],
            ],
            [
                'id' => 'DORA-17.3',
                'title' => 'Incident Register',
                'description' => 'Financial entities shall maintain ICT-related incident registers.',
                'category' => 'Incident Management',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.24'],
                    'incident_management' => true,
                ],
            ],
            [
                'id' => 'DORA-19.1',
                'title' => 'Notification of Major Incidents',
                'description' => 'Financial entities shall report major ICT-related incidents to competent authorities.',
                'category' => 'Incident Reporting',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.26'],
                    'incident_management' => true,
                ],
            ],

            // Chapter IV: Digital Operational Resilience Testing
            [
                'id' => 'DORA-24.1',
                'title' => 'Testing of ICT Tools and Systems',
                'description' => 'Financial entities shall, on a regular basis, conduct and document testing appropriate to the size and overall risk profile.',
                'category' => 'Resilience Testing',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.8', '8.29'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'DORA-24.2',
                'title' => 'Testing Programme',
                'description' => 'Financial entities shall develop and implement testing programmes that include assessments and scans.',
                'category' => 'Resilience Testing',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.8'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'DORA-26.1',
                'title' => 'Advanced Testing (TLPT)',
                'description' => 'Financial entities identified as significant shall carry out advanced testing through threat-led penetration testing (TLPT).',
                'category' => 'Advanced Testing',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.8'],
                    'audit_evidence' => true,
                ],
            ],

            // Chapter V: ICT Third-Party Risk Management
            [
                'id' => 'DORA-28.1',
                'title' => 'Third-Party Risk Management',
                'description' => 'Financial entities shall manage ICT third-party risk as an integral component of ICT risk.',
                'category' => 'Third-Party Risk',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19', '5.20', '5.21', '5.22'],
                ],
            ],
            [
                'id' => 'DORA-28.2',
                'title' => 'Contractual Arrangements',
                'description' => 'Financial entities shall ensure that contractual arrangements on the use of ICT services include all elements specified in this Regulation.',
                'category' => 'Third-Party Risk',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19', '5.20'],
                ],
            ],
            [
                'id' => 'DORA-28.3',
                'title' => 'Register of Information',
                'description' => 'Financial entities shall maintain a register of information on all contractual arrangements on the use of ICT services.',
                'category' => 'Third-Party Risk',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19'],
                ],
            ],
            [
                'id' => 'DORA-30.1',
                'title' => 'Key Contractual Provisions',
                'description' => 'Contractual arrangements shall include provisions on audit rights, security measures, data location, and exit strategies.',
                'category' => 'Third-Party Contracts',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19', '5.20', '5.21'],
                ],
            ],

            // Governance and Organization
            [
                'id' => 'DORA-5.1',
                'title' => 'Management Body Responsibility',
                'description' => 'The management body shall bear ultimate responsibility for managing ICT risk.',
                'category' => 'Governance',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1', '5.3'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'DORA-5.2',
                'title' => 'ICT Risk Oversight',
                'description' => 'The management body shall maintain overall oversight of the ICT risk management framework.',
                'category' => 'Governance',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1', '5.2', '5.3'],
                    'audit_evidence' => true,
                ],
            ],

            // Training and Awareness
            [
                'id' => 'DORA-13.6',
                'title' => 'ICT Training',
                'description' => 'Financial entities shall implement ICT security awareness programmes and training for staff.',
                'category' => 'Training and Awareness',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['6.3'],
                ],
            ],

            // Access Control and Authentication
            [
                'id' => 'DORA-13.1',
                'title' => 'ICT Systems Access Control',
                'description' => 'Financial entities shall implement access control policies ensuring appropriate authentication mechanisms.',
                'category' => 'Access Control',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.15', '5.16', '5.17', '5.18'],
                ],
            ],
            [
                'id' => 'DORA-13.2',
                'title' => 'Strong Authentication',
                'description' => 'Financial entities shall use strong authentication mechanisms, including multi-factor authentication where appropriate.',
                'category' => 'Access Control',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.17', '5.18'],
                ],
            ],

            // Data Protection and Cryptography
            [
                'id' => 'DORA-13.3',
                'title' => 'Data Protection',
                'description' => 'Financial entities shall protect the confidentiality and integrity of data.',
                'category' => 'Data Protection',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.11', '8.24'],
                    'asset_types' => ['data'],
                ],
            ],
            [
                'id' => 'DORA-13.4',
                'title' => 'Cryptographic Controls',
                'description' => 'Financial entities shall implement appropriate cryptographic controls.',
                'category' => 'Cryptography',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                ],
            ],

            // Logging and Monitoring
            [
                'id' => 'DORA-13.5',
                'title' => 'Security Event Logging',
                'description' => 'Financial entities shall set up mechanisms for prompt detection of anomalous activities.',
                'category' => 'Logging and Monitoring',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.15', '8.16'],
                ],
            ],
        ];
    }
}
