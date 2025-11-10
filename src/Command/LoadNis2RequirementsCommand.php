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
    name: 'app:load-nis2-requirements',
    description: 'Load NIS2 Directive (EU 2022/2555) requirements with ISO 27001 control mappings'
)]
class LoadNis2RequirementsCommand extends Command
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Create or get NIS2 framework
        $framework = $this->entityManager->getRepository(ComplianceFramework::class)
            ->findOneBy(['code' => 'NIS2']);

        if (!$framework) {
            $framework = new ComplianceFramework();
            $framework->setCode('NIS2')
                ->setName('NIS2 Directive (EU 2022/2555)')
                ->setDescription('Directive on measures for a high common level of cybersecurity across the Union')
                ->setVersion('2022/2555')
                ->setApplicableIndustry('all')
                ->setRegulatoryBody('European Union')
                ->setMandatory(true)
                ->setScopeDescription('Applies to essential and important entities in critical sectors (energy, transport, banking, health, digital infrastructure, etc.)')
                ->setActive(true);

            $this->entityManager->persist($framework);
        }

        $requirements = $this->getNis2Requirements();

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

        $io->success(sprintf('Successfully loaded %d NIS2 requirements', count($requirements)));

        return Command::SUCCESS;
    }

    private function getNis2Requirements(): array
    {
        return [
            // Article 21: Cybersecurity Risk Management Measures
            [
                'id' => 'NIS2-21.1',
                'title' => 'Risk Analysis and Information System Security',
                'description' => 'Entities shall adopt appropriate and proportionate technical, operational and organisational measures to manage risks posed to the security of network and information systems.',
                'category' => 'Risk Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1', '5.2', '8.1', '8.2'],
                    'risk_management_required' => true,
                ],
            ],
            [
                'id' => 'NIS2-21.2.a',
                'title' => 'Policies on Risk Analysis',
                'description' => 'Policies on risk analysis and information system security shall be established.',
                'category' => 'Risk Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1', '5.2'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'NIS2-21.2.b',
                'title' => 'Multi-Factor Authentication (MFA)',
                'description' => 'Incident handling procedures and multi-factor authentication or continuous authentication solutions shall be implemented.',
                'category' => 'Access Control',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.17', '5.18'],
                    'mfa_required' => true,
                ],
            ],
            [
                'id' => 'NIS2-21.2.c',
                'title' => 'Business Continuity and Crisis Management',
                'description' => 'Business continuity, such as backup management and disaster recovery, and crisis management shall be ensured.',
                'category' => 'Business Continuity',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.29', '5.30'],
                    'bcm_required' => true,
                ],
            ],
            [
                'id' => 'NIS2-21.2.d',
                'title' => 'Vulnerability Handling and Disclosure',
                'description' => 'Supply chain security, including security-related aspects of relationships between entities and suppliers or service providers. Vulnerability handling and disclosure procedures shall be implemented.',
                'category' => 'Vulnerability Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19', '5.20', '5.21', '5.22', '8.8'],
                    'vulnerability_management_required' => true,
                ],
            ],
            [
                'id' => 'NIS2-21.2.e',
                'title' => 'Secure Development and Acquisition',
                'description' => 'Policies and procedures to assess the effectiveness of cybersecurity risk-management measures, including secure system development and acquisition.',
                'category' => 'Secure Development',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.25', '8.26', '8.27', '8.28', '8.29', '8.30', '8.31', '8.32'],
                ],
            ],
            [
                'id' => 'NIS2-21.2.f',
                'title' => 'Security in Acquisition, Development and Maintenance',
                'description' => 'Basic cyber hygiene practices and cybersecurity training shall be implemented.',
                'category' => 'Training & Awareness',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['6.3'],
                    'training_required' => true,
                ],
            ],
            [
                'id' => 'NIS2-21.2.g',
                'title' => 'Cryptography and Encryption',
                'description' => 'Policies and procedures regarding the use of cryptography and, where appropriate, encryption shall be established.',
                'category' => 'Cryptography',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                ],
            ],
            [
                'id' => 'NIS2-21.2.h',
                'title' => 'Human Resources Security',
                'description' => 'Human resources security, access control policies and asset management shall be implemented.',
                'category' => 'Human Resources',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.7', '6.1', '6.2', '6.3', '6.4', '6.5', '6.6', '6.7', '6.8'],
                ],
            ],
            [
                'id' => 'NIS2-21.2.i',
                'title' => 'Access Control and Asset Management',
                'description' => 'Policies and procedures for access control to network and information systems shall be established, including privileged access management.',
                'category' => 'Access Control',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.9', '5.10', '5.15', '5.16', '5.17', '5.18', '8.2', '8.3'],
                    'asset_management_required' => true,
                ],
            ],

            // Article 23: Reporting Obligations
            [
                'id' => 'NIS2-23.1',
                'title' => 'Early Warning (24 hours)',
                'description' => 'Entities shall notify, without undue delay and in any event within 24 hours of becoming aware of a significant incident, the CSIRT or competent authority of any significant incident having an impact on the provision of their services (Early Warning).',
                'category' => 'Incident Reporting',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.24', '5.26'],
                    'incident_management' => true,
                    'reporting_deadline' => '24h',
                ],
            ],
            [
                'id' => 'NIS2-23.2',
                'title' => 'Incident Notification (72 hours)',
                'description' => 'Entities shall submit, without undue delay and in any event within 72 hours of becoming aware, an incident notification to the CSIRT or competent authority.',
                'category' => 'Incident Reporting',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.24', '5.26'],
                    'incident_management' => true,
                    'reporting_deadline' => '72h',
                ],
            ],
            [
                'id' => 'NIS2-23.3',
                'title' => 'Final Report (1 month)',
                'description' => 'Entities shall submit a final report not later than one month after the incident notification.',
                'category' => 'Incident Reporting',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.24', '5.26', '5.28'],
                    'incident_management' => true,
                    'reporting_deadline' => '1_month',
                ],
            ],
            [
                'id' => 'NIS2-23.4',
                'title' => 'Significant Cyber Threat Notification',
                'description' => 'Entities shall notify, without undue delay, significant cyber threats to the CSIRT or competent authority.',
                'category' => 'Threat Reporting',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.7'],
                ],
            ],
            [
                'id' => 'NIS2-23.5',
                'title' => 'Recipients Notification',
                'description' => 'Where an entity becomes aware that a significant incident is likely to affect the provision of services by another entity, that entity shall inform the recipient entity without undue delay.',
                'category' => 'Incident Reporting',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.26'],
                    'incident_management' => true,
                ],
            ],

            // Article 20: Governance
            [
                'id' => 'NIS2-20.1',
                'title' => 'Management Body Approval',
                'description' => 'The management body of the entity shall approve the cybersecurity risk-management measures taken by that entity.',
                'category' => 'Governance',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1', '5.3'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'NIS2-20.2',
                'title' => 'Management Body Oversight',
                'description' => 'Members of management bodies shall be required to follow training to gain sufficient knowledge and skills to understand and assess cybersecurity risks.',
                'category' => 'Governance',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.3', '6.3'],
                    'training_required' => true,
                ],
            ],
            [
                'id' => 'NIS2-20.3',
                'title' => 'Management Body Accountability',
                'description' => 'The management body shall oversee the implementation of cybersecurity risk-management measures and can be held accountable for breaches.',
                'category' => 'Governance',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1', '5.3'],
                    'audit_evidence' => true,
                ],
            ],

            // Article 24: Use of European Cybersecurity Certification Schemes
            [
                'id' => 'NIS2-24.1',
                'title' => 'Cybersecurity Certification',
                'description' => 'Where ICT products, services or processes are covered by European cybersecurity certification schemes, entities shall use such schemes to demonstrate compliance.',
                'category' => 'Certification',
                'priority' => 'medium',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1'],
                    'audit_evidence' => true,
                ],
            ],

            // Article 25: Standardisation
            [
                'id' => 'NIS2-25.1',
                'title' => 'Use of Standards',
                'description' => 'Entities may demonstrate compliance through the use of relevant European and international standards (e.g., ISO/IEC 27001).',
                'category' => 'Standards Compliance',
                'priority' => 'medium',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1'],
                    'audit_evidence' => true,
                ],
            ],

            // Article 22: Cybersecurity Information Sharing
            [
                'id' => 'NIS2-22.1',
                'title' => 'Information Sharing',
                'description' => 'Entities are encouraged to exchange relevant information with other entities, public authorities and relevant stakeholders.',
                'category' => 'Information Sharing',
                'priority' => 'medium',
                'data_source_mapping' => [
                    'iso_controls' => ['5.7'],
                ],
            ],

            // Supply Chain Security (Article 21.2.d detailed)
            [
                'id' => 'NIS2-21.3.a',
                'title' => 'Supplier Security Assessment',
                'description' => 'Security requirements for suppliers and service providers shall be defined and documented.',
                'category' => 'Supply Chain',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19', '5.20', '5.21'],
                ],
            ],
            [
                'id' => 'NIS2-21.3.b',
                'title' => 'Third-Party Risk Management',
                'description' => 'Risks arising from supplier relationships shall be identified, assessed and managed.',
                'category' => 'Supply Chain',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19', '5.20', '5.21', '5.22'],
                ],
            ],
            [
                'id' => 'NIS2-21.3.c',
                'title' => 'Service Level Agreements',
                'description' => 'Agreements with suppliers shall address information security requirements.',
                'category' => 'Supply Chain',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19', '5.20'],
                ],
            ],

            // Network and Information Systems Security
            [
                'id' => 'NIS2-21.4.a',
                'title' => 'Network Segmentation',
                'description' => 'Networks shall be segmented to separate critical systems and limit the impact of security incidents.',
                'category' => 'Network Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.20', '8.21', '8.22'],
                ],
            ],
            [
                'id' => 'NIS2-21.4.b',
                'title' => 'Network Monitoring',
                'description' => 'Network activities shall be monitored to detect anomalous behavior and potential security incidents.',
                'category' => 'Network Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.15', '8.16'],
                ],
            ],
            [
                'id' => 'NIS2-21.4.c',
                'title' => 'Secure Configuration',
                'description' => 'Systems shall be securely configured and hardened to minimize vulnerabilities.',
                'category' => 'System Hardening',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.8', '8.9', '8.10'],
                ],
            ],

            // Incident Response
            [
                'id' => 'NIS2-21.5.a',
                'title' => 'Incident Response Plan',
                'description' => 'A documented incident response plan shall be established and maintained.',
                'category' => 'Incident Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.24', '5.25', '5.26'],
                    'incident_management' => true,
                ],
            ],
            [
                'id' => 'NIS2-21.5.b',
                'title' => 'Incident Response Team',
                'description' => 'An incident response team with defined roles and responsibilities shall be established.',
                'category' => 'Incident Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.24'],
                    'incident_management' => true,
                ],
            ],
            [
                'id' => 'NIS2-21.5.c',
                'title' => 'Incident Response Testing',
                'description' => 'Incident response capabilities shall be tested regularly through exercises and simulations.',
                'category' => 'Incident Management',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.27'],
                    'incident_management' => true,
                ],
            ],

            // Vulnerability Management (Article 21.2.d detailed)
            [
                'id' => 'NIS2-21.6.a',
                'title' => 'Vulnerability Scanning',
                'description' => 'Regular vulnerability scans shall be performed to identify security weaknesses.',
                'category' => 'Vulnerability Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.8'],
                    'vulnerability_management_required' => true,
                ],
            ],
            [
                'id' => 'NIS2-21.6.b',
                'title' => 'Patch Management',
                'description' => 'Security patches shall be applied in a timely manner based on risk assessment.',
                'category' => 'Vulnerability Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.8', '8.19'],
                    'vulnerability_management_required' => true,
                ],
            ],
            [
                'id' => 'NIS2-21.6.c',
                'title' => 'Vulnerability Disclosure',
                'description' => 'A process for receiving and handling vulnerability disclosures shall be established.',
                'category' => 'Vulnerability Management',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.7'],
                    'vulnerability_management_required' => true,
                ],
            ],

            // Backup and Recovery
            [
                'id' => 'NIS2-21.7.a',
                'title' => 'Data Backup',
                'description' => 'Regular backups of critical data and systems shall be performed.',
                'category' => 'Backup & Recovery',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.13'],
                    'bcm_required' => true,
                ],
            ],
            [
                'id' => 'NIS2-21.7.b',
                'title' => 'Backup Testing',
                'description' => 'Backup restoration procedures shall be tested regularly.',
                'category' => 'Backup & Recovery',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.13'],
                    'bcm_required' => true,
                ],
            ],
            [
                'id' => 'NIS2-21.7.c',
                'title' => 'Disaster Recovery',
                'description' => 'Disaster recovery plans shall be established and tested.',
                'category' => 'Backup & Recovery',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.30'],
                    'bcm_required' => true,
                ],
            ],

            // Physical Security
            [
                'id' => 'NIS2-21.8.a',
                'title' => 'Physical Access Control',
                'description' => 'Physical access to critical infrastructure shall be controlled and monitored.',
                'category' => 'Physical Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['7.1', '7.2', '7.3', '7.4'],
                ],
            ],
            [
                'id' => 'NIS2-21.8.b',
                'title' => 'Environmental Controls',
                'description' => 'Appropriate environmental controls shall protect against environmental threats.',
                'category' => 'Physical Security',
                'priority' => 'medium',
                'data_source_mapping' => [
                    'iso_controls' => ['7.8', '7.10'],
                ],
            ],

            // Logging and Monitoring
            [
                'id' => 'NIS2-21.9.a',
                'title' => 'Security Event Logging',
                'description' => 'Security-relevant events shall be logged for analysis and investigation.',
                'category' => 'Logging & Monitoring',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.15', '8.16'],
                ],
            ],
            [
                'id' => 'NIS2-21.9.b',
                'title' => 'Log Retention',
                'description' => 'Logs shall be retained for an appropriate period to support investigations.',
                'category' => 'Logging & Monitoring',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.15'],
                ],
            ],
            [
                'id' => 'NIS2-21.9.c',
                'title' => 'Log Analysis',
                'description' => 'Logs shall be regularly analyzed to detect security incidents and anomalies.',
                'category' => 'Logging & Monitoring',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.16'],
                ],
            ],

            // Security Testing
            [
                'id' => 'NIS2-21.10.a',
                'title' => 'Security Testing',
                'description' => 'Regular security testing shall be performed to validate the effectiveness of controls.',
                'category' => 'Security Testing',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.8', '8.29'],
                ],
            ],
            [
                'id' => 'NIS2-21.10.b',
                'title' => 'Penetration Testing',
                'description' => 'Penetration testing shall be conducted periodically to identify vulnerabilities.',
                'category' => 'Security Testing',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.8'],
                ],
            ],

            // Documentation and Records
            [
                'id' => 'NIS2-21.11.a',
                'title' => 'Security Documentation',
                'description' => 'Security policies, procedures and technical documentation shall be maintained.',
                'category' => 'Documentation',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'NIS2-21.11.b',
                'title' => 'Record Keeping',
                'description' => 'Records of security incidents, changes and other relevant events shall be maintained.',
                'category' => 'Documentation',
                'priority' => 'medium',
                'data_source_mapping' => [
                    'iso_controls' => ['5.24'],
                ],
            ],
        ];
    }
}
