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
    description: 'Load BSI IT-Grundschutz requirements including core building blocks and BCM with ISO mappings'
)]
class LoadBsiRequirementsCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $symfonyStyle = new SymfonyStyle($input, $output);

        // Create or get BSI framework
        $framework = $this->entityManager->getRepository(ComplianceFramework::class)
            ->findOneBy(['code' => 'BSI-Grundschutz']);

        if (!$framework instanceof ComplianceFramework) {
            $framework = new ComplianceFramework();
            $framework->setCode('BSI-Grundschutz')
                ->setName('BSI IT-Grundschutz')
                ->setDescription('BSI IT-Grundschutz: Comprehensive IT security standard with building blocks (Bausteine) for organization, infrastructure, systems, and applications')
                ->setVersion('2023')
                ->setApplicableIndustry('all')
                ->setRegulatoryBody('BSI (Bundesamt für Sicherheit in der Informationstechnik)')
                ->setMandatory(false)
                ->setScopeDescription('IT-Grundschutz methodology covering ISMS, BCM, infrastructure, networks, systems, applications, and security concepts')
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

        $symfonyStyle->success(sprintf('Successfully loaded %d BSI IT-Grundschutz requirements', count($requirements)));

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
                    'iso_controls' => ['5.19', '5.20', '5.21'],
                ],
            ],

            // ORP.1: Organization (Organisation)
            [
                'id' => 'ORP.1.A1',
                'title' => 'Definition of Security Roles and Responsibilities',
                'description' => 'Roles and responsibilities for information security shall be defined and assigned.',
                'category' => 'ORP - Organization',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.3'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'ORP.1.A2',
                'title' => 'Appointment of Information Security Officer',
                'description' => 'An Information Security Officer shall be appointed with appropriate authority and resources.',
                'category' => 'ORP - Organization',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.3'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'ORP.1.A3',
                'title' => 'Security Awareness and Training',
                'description' => 'Regular security awareness and training programs shall be conducted for all employees.',
                'category' => 'ORP - Organization',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['6.3'],
                    'training_required' => true,
                ],
            ],
            [
                'id' => 'ORP.2.A1',
                'title' => 'Personnel Screening',
                'description' => 'Background checks shall be performed on personnel according to legal and contractual requirements.',
                'category' => 'ORP - Personnel',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['6.1'],
                ],
            ],
            [
                'id' => 'ORP.2.A2',
                'title' => 'Confidentiality Agreements',
                'description' => 'All employees shall sign confidentiality agreements before accessing sensitive information.',
                'category' => 'ORP - Personnel',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['6.2'],
                ],
            ],
            [
                'id' => 'ORP.3.A1',
                'title' => 'Incident Response Process',
                'description' => 'A documented incident response process shall be established and maintained.',
                'category' => 'ORP - Incident Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.24', '5.25', '5.26'],
                    'incident_management' => true,
                ],
            ],

            // INF.1: Infrastructure - General Building
            [
                'id' => 'INF.1.A1',
                'title' => 'Physical Access Control',
                'description' => 'Physical access to buildings and rooms shall be controlled and monitored.',
                'category' => 'INF - Infrastructure',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['7.2'],
                ],
            ],
            [
                'id' => 'INF.1.A2',
                'title' => 'Fire Protection',
                'description' => 'Adequate fire detection and suppression systems shall be installed and maintained.',
                'category' => 'INF - Infrastructure',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['7.4'],
                ],
            ],
            [
                'id' => 'INF.1.A3',
                'title' => 'Water Damage Protection',
                'description' => 'Protection measures against water damage shall be implemented.',
                'category' => 'INF - Infrastructure',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['7.4'],
                ],
            ],
            [
                'id' => 'INF.2.A1',
                'title' => 'Data Center Access Control',
                'description' => 'Strict access control to data center facilities shall be enforced.',
                'category' => 'INF - Data Center',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['7.2'],
                ],
            ],
            [
                'id' => 'INF.2.A2',
                'title' => 'Environmental Monitoring',
                'description' => 'Temperature, humidity, and other environmental factors in data centers shall be monitored.',
                'category' => 'INF - Data Center',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['7.9'],
                ],
            ],
            [
                'id' => 'INF.2.A3',
                'title' => 'Power Supply',
                'description' => 'Redundant power supply and UPS systems shall be provided for data centers.',
                'category' => 'INF - Data Center',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['7.9'],
                ],
            ],
            [
                'id' => 'INF.3.A1',
                'title' => 'Room Security',
                'description' => 'Server rooms and technical rooms shall be secured against unauthorized access.',
                'category' => 'INF - Server Rooms',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['7.2', '7.3'],
                ],
            ],

            // NET.1: Networks
            [
                'id' => 'NET.1.1.A1',
                'title' => 'Network Architecture Documentation',
                'description' => 'The network architecture shall be documented comprehensively.',
                'category' => 'NET - Networks',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.20'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'NET.1.1.A2',
                'title' => 'Network Segmentation',
                'description' => 'Networks shall be segmented based on security requirements and business needs.',
                'category' => 'NET - Networks',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.22'],
                ],
            ],
            [
                'id' => 'NET.1.2.A1',
                'title' => 'Firewall Configuration',
                'description' => 'Firewalls shall be configured according to security policies with deny-all default rules.',
                'category' => 'NET - Firewall',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.20', '8.22'],
                ],
            ],
            [
                'id' => 'NET.1.2.A2',
                'title' => 'Firewall Rule Review',
                'description' => 'Firewall rules shall be reviewed regularly and updated as needed.',
                'category' => 'NET - Firewall',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.20'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'NET.2.1.A1',
                'title' => 'WLAN Security',
                'description' => 'WLAN networks shall be secured using WPA3 or equivalent encryption.',
                'category' => 'NET - WLAN',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.20', '8.24'],
                ],
            ],
            [
                'id' => 'NET.2.1.A2',
                'title' => 'WLAN Segmentation',
                'description' => 'Guest WLAN shall be separated from corporate networks.',
                'category' => 'NET - WLAN',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.22'],
                ],
            ],
            [
                'id' => 'NET.3.1.A1',
                'title' => 'Router Security',
                'description' => 'Routers shall be hardened and secured according to best practices.',
                'category' => 'NET - Router',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.9', '8.20'],
                ],
            ],
            [
                'id' => 'NET.3.2.A1',
                'title' => 'VPN Security',
                'description' => 'VPN connections shall use strong encryption and authentication.',
                'category' => 'NET - VPN',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                ],
            ],

            // SYS.1: IT Systems - General
            [
                'id' => 'SYS.1.1.A1',
                'title' => 'Server Hardening',
                'description' => 'Servers shall be hardened according to security baseline configurations.',
                'category' => 'SYS - Servers',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.9'],
                ],
            ],
            [
                'id' => 'SYS.1.1.A2',
                'title' => 'Server Patch Management',
                'description' => 'Security patches shall be applied to servers in a timely manner.',
                'category' => 'SYS - Servers',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.8'],
                ],
            ],
            [
                'id' => 'SYS.1.1.A3',
                'title' => 'Server Logging',
                'description' => 'Comprehensive logging shall be enabled on all servers.',
                'category' => 'SYS - Servers',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.15'],
                ],
            ],
            [
                'id' => 'SYS.2.1.A1',
                'title' => 'Client Hardening',
                'description' => 'Client systems shall be configured according to security baselines.',
                'category' => 'SYS - Clients',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.9'],
                ],
            ],
            [
                'id' => 'SYS.2.1.A2',
                'title' => 'Client Antivirus',
                'description' => 'Antivirus software shall be installed and kept up-to-date on all clients.',
                'category' => 'SYS - Clients',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.7'],
                ],
            ],
            [
                'id' => 'SYS.2.1.A3',
                'title' => 'Client Disk Encryption',
                'description' => 'Full disk encryption shall be enabled on all mobile and sensitive client systems.',
                'category' => 'SYS - Clients',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                ],
            ],
            [
                'id' => 'SYS.2.2.A1',
                'title' => 'Mobile Device Management',
                'description' => 'Mobile devices shall be managed through a central MDM solution.',
                'category' => 'SYS - Mobile Devices',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['6.7', '8.9'],
                ],
            ],
            [
                'id' => 'SYS.2.2.A2',
                'title' => 'Mobile Device Encryption',
                'description' => 'Data on mobile devices shall be encrypted.',
                'category' => 'SYS - Mobile Devices',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                ],
            ],
            [
                'id' => 'SYS.2.3.A1',
                'title' => 'Virtual Machine Security',
                'description' => 'Virtual machines shall be secured and isolated appropriately.',
                'category' => 'SYS - Virtualization',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.9'],
                ],
            ],
            [
                'id' => 'SYS.3.1.A1',
                'title' => 'Directory Service Security',
                'description' => 'Directory services (Active Directory, LDAP) shall be secured and monitored.',
                'category' => 'SYS - Directory Services',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.16', '5.18'],
                ],
            ],

            // APP.1: Applications
            [
                'id' => 'APP.1.1.A1',
                'title' => 'Secure Software Development',
                'description' => 'Applications shall be developed following secure coding practices.',
                'category' => 'APP - Development',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.25', '8.28'],
                ],
            ],
            [
                'id' => 'APP.1.1.A2',
                'title' => 'Security Testing',
                'description' => 'Applications shall undergo security testing before deployment.',
                'category' => 'APP - Development',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.29'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'APP.2.1.A1',
                'title' => 'Web Application Security',
                'description' => 'Web applications shall be protected against common vulnerabilities (OWASP Top 10).',
                'category' => 'APP - Web Applications',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.28'],
                ],
            ],
            [
                'id' => 'APP.2.1.A2',
                'title' => 'Web Application Firewall',
                'description' => 'A Web Application Firewall shall be deployed for critical web applications.',
                'category' => 'APP - Web Applications',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.20'],
                ],
            ],
            [
                'id' => 'APP.3.1.A1',
                'title' => 'Database Security',
                'description' => 'Databases shall be hardened and access shall be restricted to authorized users only.',
                'category' => 'APP - Databases',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.9'],
                ],
            ],
            [
                'id' => 'APP.3.1.A2',
                'title' => 'Database Encryption',
                'description' => 'Sensitive data in databases shall be encrypted at rest.',
                'category' => 'APP - Databases',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                    'asset_types' => ['data'],
                ],
            ],
            [
                'id' => 'APP.3.1.A3',
                'title' => 'Database Backup',
                'description' => 'Regular database backups shall be performed and tested.',
                'category' => 'APP - Databases',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.13'],
                    'bcm_required' => true,
                ],
            ],
            [
                'id' => 'APP.4.1.A1',
                'title' => 'Email Security',
                'description' => 'Email systems shall be protected against spam, phishing, and malware.',
                'category' => 'APP - Email',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.7', '8.23'],
                ],
            ],
            [
                'id' => 'APP.5.1.A1',
                'title' => 'Cloud Application Security',
                'description' => 'Security requirements shall be defined for cloud applications.',
                'category' => 'APP - Cloud',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19', '5.20', '5.23'],
                    'asset_types' => ['cloud'],
                ],
            ],

            // CON.1: Concepts
            [
                'id' => 'CON.1.A1',
                'title' => 'Cryptographic Concept',
                'description' => 'A comprehensive cryptographic concept shall be developed and maintained.',
                'category' => 'CON - Cryptography',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                ],
            ],
            [
                'id' => 'CON.1.A2',
                'title' => 'Key Management',
                'description' => 'Cryptographic keys shall be managed securely throughout their lifecycle.',
                'category' => 'CON - Cryptography',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                ],
            ],
            [
                'id' => 'CON.2.A1',
                'title' => 'Data Protection Concept',
                'description' => 'A data protection concept compliant with GDPR shall be implemented.',
                'category' => 'CON - Data Protection',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                    'asset_types' => ['data'],
                ],
            ],
            [
                'id' => 'CON.3.A1',
                'title' => 'Data Backup Concept',
                'description' => 'A comprehensive data backup concept shall be established and tested regularly.',
                'category' => 'CON - Backup',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.13'],
                    'bcm_required' => true,
                ],
            ],
            [
                'id' => 'CON.4.A1',
                'title' => 'Logging Concept',
                'description' => 'A centralized logging concept shall be implemented for security monitoring.',
                'category' => 'CON - Logging',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.15', '8.16'],
                ],
            ],
            [
                'id' => 'CON.5.A1',
                'title' => 'Secure Deletion Concept',
                'description' => 'A concept for secure deletion of data and media shall be established.',
                'category' => 'CON - Data Deletion',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.10'],
                ],
            ],
            [
                'id' => 'CON.6.A1',
                'title' => 'Security Incident Management Concept',
                'description' => 'A comprehensive security incident management concept shall be implemented.',
                'category' => 'CON - Incident Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.24', '5.25', '5.26'],
                    'incident_management' => true,
                ],
            ],
            [
                'id' => 'CON.7.A1',
                'title' => 'Secure Software Development Lifecycle',
                'description' => 'A secure SDLC process shall be established for all software development.',
                'category' => 'CON - Development',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.25', '8.28', '8.29'],
                ],
            ],
            [
                'id' => 'CON.8.A1',
                'title' => 'Penetration Testing Concept',
                'description' => 'Regular penetration tests shall be conducted and documented.',
                'category' => 'CON - Testing',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.8'],
                    'audit_evidence' => true,
                ],
            ],
        ];
    }
}
