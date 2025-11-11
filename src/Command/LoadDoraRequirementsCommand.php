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

            // RTS on ICT Risk Management Framework
            [
                'id' => 'DORA-RTS-RM-1.1',
                'title' => 'ICT Risk Assessment Methodology',
                'description' => 'Financial entities shall establish a comprehensive ICT risk assessment methodology covering identification, analysis, and evaluation of ICT risks.',
                'category' => 'RTS ICT Risk Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.7', '5.8'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'DORA-RTS-RM-1.2',
                'title' => 'ICT Asset Classification',
                'description' => 'Financial entities shall classify ICT assets based on criticality and implement appropriate protection measures.',
                'category' => 'RTS ICT Risk Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.9', '5.12'],
                    'asset_types' => ['hardware', 'software', 'data', 'services', 'cloud'],
                ],
            ],
            [
                'id' => 'DORA-RTS-RM-1.3',
                'title' => 'Security Baseline Configuration',
                'description' => 'Financial entities shall define and maintain security baseline configurations for ICT systems.',
                'category' => 'RTS ICT Risk Management',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.9', '8.19'],
                ],
            ],
            [
                'id' => 'DORA-RTS-RM-2.1',
                'title' => 'Network Security Architecture',
                'description' => 'Financial entities shall implement network segmentation and security zones based on criticality of systems and data.',
                'category' => 'RTS Network Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.20', '8.22'],
                ],
            ],
            [
                'id' => 'DORA-RTS-RM-2.2',
                'title' => 'Network Monitoring and Intrusion Detection',
                'description' => 'Financial entities shall implement continuous network monitoring and intrusion detection systems.',
                'category' => 'RTS Network Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.15', '8.16'],
                ],
            ],
            [
                'id' => 'DORA-RTS-RM-3.1',
                'title' => 'Patch Management',
                'description' => 'Financial entities shall establish and implement a comprehensive patch management process for all ICT systems.',
                'category' => 'RTS Vulnerability Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.8'],
                ],
            ],
            [
                'id' => 'DORA-RTS-RM-3.2',
                'title' => 'Vulnerability Scanning',
                'description' => 'Financial entities shall conduct regular vulnerability scans of all ICT systems and applications.',
                'category' => 'RTS Vulnerability Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.8'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'DORA-RTS-RM-4.1',
                'title' => 'Data Protection and Encryption',
                'description' => 'Financial entities shall implement encryption for data at rest and in transit, particularly for sensitive and critical data.',
                'category' => 'RTS Data Protection',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                    'asset_types' => ['data'],
                ],
            ],
            [
                'id' => 'DORA-RTS-RM-4.2',
                'title' => 'Data Loss Prevention',
                'description' => 'Financial entities shall implement data loss prevention mechanisms to protect against unauthorized data exfiltration.',
                'category' => 'RTS Data Protection',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.11', '8.12'],
                    'asset_types' => ['data'],
                ],
            ],
            [
                'id' => 'DORA-RTS-RM-5.1',
                'title' => 'Identity and Access Management',
                'description' => 'Financial entities shall implement centralized identity and access management systems with role-based access control.',
                'category' => 'RTS Access Control',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.15', '5.16', '5.18'],
                ],
            ],
            [
                'id' => 'DORA-RTS-RM-5.2',
                'title' => 'Multi-Factor Authentication',
                'description' => 'Financial entities shall implement multi-factor authentication for all remote access and privileged accounts.',
                'category' => 'RTS Access Control',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.17', '5.18'],
                ],
            ],
            [
                'id' => 'DORA-RTS-RM-5.3',
                'title' => 'Privileged Access Management',
                'description' => 'Financial entities shall implement privileged access management solutions with session recording and monitoring.',
                'category' => 'RTS Access Control',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.2', '8.3'],
                ],
            ],

            // RTS on Major Incident Reporting
            [
                'id' => 'DORA-RTS-IR-1.1',
                'title' => 'Incident Classification Criteria',
                'description' => 'Financial entities shall establish detailed criteria for classifying ICT incidents as major based on impact thresholds.',
                'category' => 'RTS Incident Reporting',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.24', '5.25'],
                    'incident_management' => true,
                ],
            ],
            [
                'id' => 'DORA-RTS-IR-1.2',
                'title' => 'Initial Notification Requirements',
                'description' => 'Financial entities shall report major incidents to competent authorities within 4 hours of classification.',
                'category' => 'RTS Incident Reporting',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.26'],
                    'incident_management' => true,
                ],
            ],
            [
                'id' => 'DORA-RTS-IR-1.3',
                'title' => 'Intermediate Incident Reports',
                'description' => 'Financial entities shall provide intermediate reports on major incidents within 72 hours with updated impact assessment.',
                'category' => 'RTS Incident Reporting',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.26'],
                    'incident_management' => true,
                ],
            ],
            [
                'id' => 'DORA-RTS-IR-1.4',
                'title' => 'Final Incident Reports',
                'description' => 'Financial entities shall submit final incident reports within one month including root cause analysis and remediation measures.',
                'category' => 'RTS Incident Reporting',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.27'],
                    'incident_management' => true,
                ],
            ],
            [
                'id' => 'DORA-RTS-IR-2.1',
                'title' => 'Significant Cyber Threats Reporting',
                'description' => 'Financial entities shall report significant cyber threats that could potentially impact financial stability.',
                'category' => 'RTS Cyber Threats',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.24', '5.26'],
                    'incident_management' => true,
                ],
            ],
            [
                'id' => 'DORA-RTS-IR-3.1',
                'title' => 'Voluntary Incident Information Sharing',
                'description' => 'Financial entities may voluntarily share information about incidents and cyber threats with other entities.',
                'category' => 'RTS Information Sharing',
                'priority' => 'medium',
                'data_source_mapping' => [
                    'iso_controls' => ['5.26'],
                    'incident_management' => true,
                ],
            ],

            // RTS on Digital Operational Resilience Testing
            [
                'id' => 'DORA-RTS-TEST-1.1',
                'title' => 'Testing Frequency and Scope',
                'description' => 'Financial entities shall conduct digital operational resilience testing at least annually, covering all critical ICT systems.',
                'category' => 'RTS Resilience Testing',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.8', '8.29'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'DORA-RTS-TEST-1.2',
                'title' => 'Vulnerability Assessments',
                'description' => 'Financial entities shall conduct vulnerability assessments covering networks, systems, and applications.',
                'category' => 'RTS Vulnerability Testing',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.8'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'DORA-RTS-TEST-1.3',
                'title' => 'Open Source and Proprietary Software Testing',
                'description' => 'Financial entities shall assess security vulnerabilities in both open source and proprietary software components.',
                'category' => 'RTS Software Testing',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.8', '8.28'],
                ],
            ],
            [
                'id' => 'DORA-RTS-TEST-2.1',
                'title' => 'Network Security Assessments',
                'description' => 'Financial entities shall conduct comprehensive network security assessments including configuration reviews.',
                'category' => 'RTS Network Testing',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.8', '8.20'],
                ],
            ],
            [
                'id' => 'DORA-RTS-TEST-2.2',
                'title' => 'Gap Analyses',
                'description' => 'Financial entities shall perform gap analyses against relevant standards and best practices.',
                'category' => 'RTS Compliance Testing',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.35', '5.36'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'DORA-RTS-TEST-3.1',
                'title' => 'Scenario-Based Testing',
                'description' => 'Financial entities shall conduct scenario-based testing simulating cyber attacks and system failures.',
                'category' => 'RTS Scenario Testing',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.8'],
                    'bcm_required' => true,
                ],
            ],
            [
                'id' => 'DORA-RTS-TEST-3.2',
                'title' => 'Red Team Testing',
                'description' => 'Financial entities shall conduct red team testing to simulate sophisticated attacks by threat actors.',
                'category' => 'RTS Advanced Testing',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.8'],
                ],
            ],
            [
                'id' => 'DORA-RTS-TEST-4.1',
                'title' => 'TLPT Scope Definition',
                'description' => 'Financial entities subject to TLPT shall define comprehensive scope covering critical functions and supporting assets.',
                'category' => 'RTS TLPT',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.8'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'DORA-RTS-TEST-4.2',
                'title' => 'TLPT Execution Framework',
                'description' => 'Financial entities shall follow the TIBER-EU framework or equivalent for executing threat-led penetration tests.',
                'category' => 'RTS TLPT',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.8'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'DORA-RTS-TEST-4.3',
                'title' => 'TLPT Remediation Planning',
                'description' => 'Financial entities shall develop and implement remediation plans for all issues identified during TLPT.',
                'category' => 'RTS TLPT',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.8'],
                ],
            ],
            [
                'id' => 'DORA-RTS-TEST-5.1',
                'title' => 'Testing Documentation',
                'description' => 'Financial entities shall maintain comprehensive documentation of all testing activities, findings, and remediation actions.',
                'category' => 'RTS Testing Documentation',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.8'],
                    'audit_evidence' => true,
                ],
            ],

            // RTS on ICT Third-Party Risk
            [
                'id' => 'DORA-RTS-TPR-1.1',
                'title' => 'Third-Party Risk Assessment',
                'description' => 'Financial entities shall conduct comprehensive risk assessments before entering into contractual arrangements with ICT third-party service providers.',
                'category' => 'RTS Third-Party Risk',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19', '5.20'],
                ],
            ],
            [
                'id' => 'DORA-RTS-TPR-1.2',
                'title' => 'Critical ICT Third-Party Service Providers',
                'description' => 'Financial entities shall identify critical ICT third-party service providers and apply enhanced due diligence.',
                'category' => 'RTS Third-Party Risk',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19', '5.20'],
                ],
            ],
            [
                'id' => 'DORA-RTS-TPR-2.1',
                'title' => 'Contractual Arrangements - Right to Audit',
                'description' => 'Financial entities shall ensure contracts include comprehensive audit rights and inspection capabilities.',
                'category' => 'RTS Contractual Requirements',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.20', '5.21'],
                ],
            ],
            [
                'id' => 'DORA-RTS-TPR-2.2',
                'title' => 'Contractual Arrangements - Data Access and Portability',
                'description' => 'Financial entities shall ensure contracts guarantee data access, data portability, and data deletion rights.',
                'category' => 'RTS Contractual Requirements',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.20'],
                    'asset_types' => ['data'],
                ],
            ],
            [
                'id' => 'DORA-RTS-TPR-2.3',
                'title' => 'Contractual Arrangements - Exit Strategy',
                'description' => 'Financial entities shall ensure contracts include comprehensive exit strategies and transition plans.',
                'category' => 'RTS Contractual Requirements',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.20', '5.23'],
                ],
            ],
            [
                'id' => 'DORA-RTS-TPR-2.4',
                'title' => 'Contractual Arrangements - Subcontracting',
                'description' => 'Financial entities shall ensure contracts specify requirements for subcontracting and notification obligations.',
                'category' => 'RTS Contractual Requirements',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19', '5.20'],
                ],
            ],
            [
                'id' => 'DORA-RTS-TPR-3.1',
                'title' => 'Ongoing Monitoring of Third-Parties',
                'description' => 'Financial entities shall continuously monitor ICT third-party service providers performance and risk profile.',
                'category' => 'RTS Third-Party Monitoring',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.22'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'DORA-RTS-TPR-3.2',
                'title' => 'Third-Party Security Assessments',
                'description' => 'Financial entities shall conduct regular security assessments of critical ICT third-party service providers.',
                'category' => 'RTS Third-Party Monitoring',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.22'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'DORA-RTS-TPR-4.1',
                'title' => 'Register of Information Requirements',
                'description' => 'Financial entities shall maintain a comprehensive register with detailed information on all ICT third-party arrangements.',
                'category' => 'RTS Third-Party Documentation',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19'],
                ],
            ],
            [
                'id' => 'DORA-RTS-TPR-4.2',
                'title' => 'Concentration Risk Assessment',
                'description' => 'Financial entities shall assess and address concentration risks arising from dependencies on ICT third-party service providers.',
                'category' => 'RTS Concentration Risk',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19', '5.20'],
                ],
            ],

            // RTS on Information Sharing Arrangements
            [
                'id' => 'DORA-RTS-IS-1.1',
                'title' => 'Information Sharing Arrangements',
                'description' => 'Financial entities may participate in information sharing arrangements to exchange cyber threat intelligence.',
                'category' => 'RTS Information Sharing',
                'priority' => 'medium',
                'data_source_mapping' => [
                    'iso_controls' => ['5.26'],
                ],
            ],
            [
                'id' => 'DORA-RTS-IS-1.2',
                'title' => 'Confidentiality in Information Sharing',
                'description' => 'Financial entities shall ensure appropriate confidentiality measures when participating in information sharing.',
                'category' => 'RTS Information Sharing',
                'priority' => 'medium',
                'data_source_mapping' => [
                    'iso_controls' => ['5.14'],
                ],
            ],

            // Additional DORA Requirements for Completeness
            [
                'id' => 'DORA-11.1',
                'title' => 'Simplified ICT Risk Management Framework',
                'description' => 'Small and non-interconnected financial entities may apply simplified ICT risk management requirements.',
                'category' => 'Simplified Requirements',
                'priority' => 'medium',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1', '5.2'],
                ],
            ],
            [
                'id' => 'DORA-14.1',
                'title' => 'Physical Security and Environmental Controls',
                'description' => 'Financial entities shall implement physical security and environmental controls for ICT infrastructure.',
                'category' => 'Physical Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['7.1', '7.2', '7.4'],
                ],
            ],
            [
                'id' => 'DORA-15.1',
                'title' => 'Relationship with Oversight Authorities',
                'description' => 'Financial entities shall cooperate with competent authorities for oversight and provide requested information.',
                'category' => 'Regulatory Compliance',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.31'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'DORA-16.1',
                'title' => 'Supervisory Expectations',
                'description' => 'Financial entities shall meet supervisory expectations for ICT risk management maturity and capabilities.',
                'category' => 'Regulatory Compliance',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1', '5.2', '5.3'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'DORA-20.1',
                'title' => 'Harmonization of Supervisory Reporting',
                'description' => 'Financial entities shall use standardized templates and procedures for supervisory reporting.',
                'category' => 'Regulatory Reporting',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.31'],
                ],
            ],
            [
                'id' => 'DORA-27.1',
                'title' => 'Use of Cloud Computing Services',
                'description' => 'Financial entities using cloud computing services shall address specific risks and ensure contractual provisions meet DORA requirements.',
                'category' => 'Cloud Services',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19', '5.20', '5.23'],
                    'asset_types' => ['cloud'],
                ],
            ],
            [
                'id' => 'DORA-29.1',
                'title' => 'Preliminary Assessment of ICT Concentration Risk',
                'description' => 'Financial entities shall conduct preliminary assessments of ICT concentration risk at entity and group level.',
                'category' => 'Concentration Risk',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19'],
                ],
            ],
            [
                'id' => 'DORA-31.1',
                'title' => 'Sub-contracting of Critical Functions',
                'description' => 'Financial entities shall ensure that sub-contracting arrangements do not impair the quality of ICT services.',
                'category' => 'Third-Party Risk',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19', '5.20'],
                ],
            ],
            [
                'id' => 'DORA-32.1',
                'title' => 'Assessment of ICT Risk at Group Level',
                'description' => 'Financial entities that are part of a group shall address ICT risks at group level.',
                'category' => 'Group Risk Management',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1', '5.7'],
                ],
            ],
            [
                'id' => 'DORA-33.1',
                'title' => 'Cooperation with Other Authorities',
                'description' => 'Competent authorities shall cooperate with each other and with ESAs for consistent application of DORA.',
                'category' => 'Regulatory Cooperation',
                'priority' => 'medium',
                'data_source_mapping' => [
                    'iso_controls' => ['5.31'],
                ],
            ],
        ];
    }
}
