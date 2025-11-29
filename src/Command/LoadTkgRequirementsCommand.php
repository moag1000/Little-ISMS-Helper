<?php

namespace App\Command;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:load-tkg-requirements',
    description: 'Load TKG 2024 (Telekommunikationsgesetz) and TK-Sicherheitsverordnung requirements with ISMS data mappings'
)]
class LoadTkgRequirementsCommand
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function __invoke(SymfonyStyle $symfonyStyle): int
    {
        // Create or get TKG framework
        $framework = $this->entityManager->getRepository(ComplianceFramework::class)
            ->findOneBy(['code' => 'TKG-2024']);
        if (!$framework instanceof ComplianceFramework) {
            $framework = new ComplianceFramework();
            $framework->setCode('TKG-2024')
                ->setName('TKG 2024 - Telekommunikationsgesetz mit TK-Sicherheitsverordnung')
                ->setDescription('German Telecommunications Act 2024 with security requirements for telecom providers')
                ->setVersion('2024')
                ->setApplicableIndustry('telecommunications')
                ->setRegulatoryBody('BNetzA - Bundesnetzagentur / BSI')
                ->setMandatory(true)
                ->setScopeDescription('Mandatory for telecommunications service providers in Germany')
                ->setActive(true);

            $this->entityManager->persist($framework);
        }
        $requirements = $this->getTkgRequirements();
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
        $symfonyStyle->success(sprintf('Successfully loaded %d TKG 2024 requirements', count($requirements)));
        return Command::SUCCESS;
    }

    private function getTkgRequirements(): array
    {
        return [
            // § 164 TKG - IT Security Concept
            [
                'id' => 'TKG-164.1',
                'title' => 'IT Security Concept Obligation',
                'description' => 'Telecom providers shall establish and maintain an IT security concept.',
                'category' => '§ 164 TKG Security Concept',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1', '5.2'],
                    'legal_requirement' => '§ 164 TKG',
                    'bsi_grundschutz' => true,
                ],
            ],
            [
                'id' => 'TKG-164.2',
                'title' => 'State of the Art Security',
                'description' => 'Security measures shall correspond to the state of the art (Stand der Technik).',
                'category' => '§ 164 TKG Security Concept',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1'],
                    'legal_requirement' => '§ 164 Abs. 1 TKG',
                ],
            ],
            [
                'id' => 'TKG-164.3',
                'title' => 'Security Concept Documentation',
                'description' => 'The security concept shall be documented and regularly updated.',
                'category' => '§ 164 TKG Security Concept',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1'],
                    'audit_evidence' => true,
                ],
            ],

            // § 165 TKG - Incident Notification
            [
                'id' => 'TKG-165.1',
                'title' => 'Security Incident Notification to BNetzA',
                'description' => 'Security incidents affecting network integrity or services shall be reported to BNetzA.',
                'category' => '§ 165 TKG Incident Reporting',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.26'],
                    'legal_requirement' => '§ 165 TKG',
                    'incident_management' => true,
                ],
            ],
            [
                'id' => 'TKG-165.2',
                'title' => 'Customer Notification',
                'description' => 'Affected customers shall be informed about security incidents affecting their services.',
                'category' => '§ 165 TKG Incident Reporting',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.26'],
                    'legal_requirement' => '§ 165 Abs. 2 TKG',
                ],
            ],
            [
                'id' => 'TKG-165.3',
                'title' => 'Incident Documentation',
                'description' => 'Security incidents shall be documented with details on impact and remediation.',
                'category' => '§ 165 TKG Incident Reporting',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.24', '5.27'],
                    'incident_management' => true,
                ],
            ],

            // § 166 TKG - Catalog of Security Requirements
            [
                'id' => 'TKG-166.1',
                'title' => 'Compliance with BSI Catalog',
                'description' => 'Security measures shall comply with the BSI catalog of security requirements.',
                'category' => '§ 166 TKG BSI Catalog',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.31'],
                    'legal_requirement' => '§ 166 TKG',
                    'bsi_grundschutz' => true,
                ],
            ],

            // § 167 TKG - Security Audit
            [
                'id' => 'TKG-167.1',
                'title' => 'Biennial Security Audit',
                'description' => 'Security measures shall be audited by qualified auditors at least every two years.',
                'category' => '§ 167 TKG Security Audit',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.35', '5.36'],
                    'legal_requirement' => '§ 167 TKG',
                    'audit_evidence' => true,
                    'audit_interval' => 'every 2 years',
                ],
            ],
            [
                'id' => 'TKG-167.2',
                'title' => 'Audit Certificate Submission',
                'description' => 'Audit certificates shall be submitted to BNetzA within specified timeframe.',
                'category' => '§ 167 TKG Security Audit',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'legal_requirement' => '§ 167 Abs. 2 TKG',
                    'audit_evidence' => true,
                ],
            ],

            // TK-SiV (TK-Sicherheitsverordnung)
            [
                'id' => 'TKSIV-1.1',
                'title' => 'Risk Analysis',
                'description' => 'A comprehensive risk analysis covering all network components and services shall be conducted.',
                'category' => 'TK-SiV Risk Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.7', '5.8'],
                    'legal_requirement' => 'TK-SiV',
                ],
            ],
            [
                'id' => 'TKSIV-1.2',
                'title' => 'Threat Scenarios',
                'description' => 'Specific threat scenarios relevant to telecommunications shall be identified and assessed.',
                'category' => 'TK-SiV Risk Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.7'],
                ],
            ],

            // Network Security
            [
                'id' => 'TKSIV-2.1',
                'title' => 'Network Segmentation',
                'description' => 'Telecommunications networks shall be segmented to limit attack propagation.',
                'category' => 'TK-SiV Network Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.20', '8.22'],
                ],
            ],
            [
                'id' => 'TKSIV-2.2',
                'title' => 'Core Network Protection',
                'description' => 'Core network elements shall be protected with defense-in-depth strategies.',
                'category' => 'TK-SiV Network Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.20'],
                ],
            ],
            [
                'id' => 'TKSIV-2.3',
                'title' => 'DDoS Protection',
                'description' => 'Distributed denial-of-service protection mechanisms shall be implemented.',
                'category' => 'TK-SiV Network Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.16', '8.20'],
                ],
            ],
            [
                'id' => 'TKSIV-2.4',
                'title' => 'Signaling Security',
                'description' => 'SS7, Diameter, and 5G signaling protocols shall be protected against attacks.',
                'category' => 'TK-SiV Network Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.20'],
                ],
            ],

            // Access Control
            [
                'id' => 'TKSIV-3.1',
                'title' => 'Administrative Access Control',
                'description' => 'Administrative access to network elements shall be strictly controlled and monitored.',
                'category' => 'TK-SiV Access Control',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.2', '8.3'],
                ],
            ],
            [
                'id' => 'TKSIV-3.2',
                'title' => 'Multi-Factor Authentication',
                'description' => 'Multi-factor authentication shall be required for all administrative access.',
                'category' => 'TK-SiV Access Control',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.17', '5.18'],
                ],
            ],
            [
                'id' => 'TKSIV-3.3',
                'title' => 'Remote Access Security',
                'description' => 'Remote access to telecommunications systems shall use encrypted VPN connections.',
                'category' => 'TK-SiV Access Control',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.20', '8.24'],
                ],
            ],

            // Monitoring and Detection
            [
                'id' => 'TKSIV-4.1',
                'title' => 'Security Monitoring',
                'description' => 'Continuous security monitoring of networks and systems shall be implemented.',
                'category' => 'TK-SiV Monitoring',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.15', '8.16'],
                ],
            ],
            [
                'id' => 'TKSIV-4.2',
                'title' => 'Intrusion Detection Systems',
                'description' => 'IDS/IPS systems shall be deployed to detect and prevent network attacks.',
                'category' => 'TK-SiV Monitoring',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.16'],
                ],
            ],
            [
                'id' => 'TKSIV-4.3',
                'title' => 'Log Management',
                'description' => 'Security logs shall be collected, protected, and retained for analysis.',
                'category' => 'TK-SiV Monitoring',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.15'],
                ],
            ],
            [
                'id' => 'TKSIV-4.4',
                'title' => 'Anomaly Detection',
                'description' => 'Automated anomaly detection shall identify unusual traffic patterns and behaviors.',
                'category' => 'TK-SiV Monitoring',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.16'],
                ],
            ],

            // Business Continuity
            [
                'id' => 'TKSIV-5.1',
                'title' => 'Service Availability Requirements',
                'description' => 'Critical telecommunications services shall meet high availability requirements (99.9%+).',
                'category' => 'TK-SiV Business Continuity',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.6', '8.14'],
                    'bcm_required' => true,
                ],
            ],
            [
                'id' => 'TKSIV-5.2',
                'title' => 'Network Redundancy',
                'description' => 'Critical network components shall be redundantly designed and geographically distributed.',
                'category' => 'TK-SiV Business Continuity',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.14'],
                    'bcm_required' => true,
                ],
            ],
            [
                'id' => 'TKSIV-5.3',
                'title' => 'Disaster Recovery Plans',
                'description' => 'Disaster recovery plans for telecommunications services shall be established and tested.',
                'category' => 'TK-SiV Business Continuity',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.30'],
                    'bcm_required' => true,
                ],
            ],
            [
                'id' => 'TKSIV-5.4',
                'title' => 'Emergency Communication',
                'description' => 'Emergency communication capabilities (110, 112) shall remain operational during incidents.',
                'category' => 'TK-SiV Business Continuity',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.29', '5.30'],
                    'bcm_required' => true,
                ],
            ],

            // Customer Data Protection
            [
                'id' => 'TKSIV-6.1',
                'title' => 'Communications Secrecy',
                'description' => 'Telecommunications secrecy (Fernmeldegeheimnis) shall be ensured.',
                'category' => 'TK-SiV Data Protection',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34', '8.24'],
                    'legal_requirement' => '§ 3 TKG',
                    'gdpr_relevant' => true,
                ],
            ],
            [
                'id' => 'TKSIV-6.2',
                'title' => 'Traffic Data Protection',
                'description' => 'Customer traffic data shall be protected and only used for specified purposes.',
                'category' => 'TK-SiV Data Protection',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.11'],
                    'asset_types' => ['data'],
                    'gdpr_relevant' => true,
                ],
            ],
            [
                'id' => 'TKSIV-6.3',
                'title' => 'Location Data Security',
                'description' => 'Mobile location data shall be protected with appropriate security measures.',
                'category' => 'TK-SiV Data Protection',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.11', '8.24'],
                    'asset_types' => ['data'],
                    'gdpr_relevant' => true,
                ],
            ],

            // Supply Chain Security
            [
                'id' => 'TKSIV-7.1',
                'title' => 'Equipment Security Assessment',
                'description' => 'Telecommunications equipment from suppliers shall undergo security assessment.',
                'category' => 'TK-SiV Supply Chain',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19', '5.20'],
                ],
            ],
            [
                'id' => 'TKSIV-7.2',
                'title' => 'Critical Component Sourcing',
                'description' => 'Critical network components shall be sourced from trusted suppliers.',
                'category' => 'TK-SiV Supply Chain',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19'],
                ],
            ],
            [
                'id' => 'TKSIV-7.3',
                'title' => 'Software Supply Chain Security',
                'description' => 'Network software and firmware shall come from verified sources with integrity checks.',
                'category' => 'TK-SiV Supply Chain',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.19', '8.28'],
                ],
            ],

            // 5G Specific Requirements
            [
                'id' => 'TKG-5G-1',
                'title' => '5G Network Slicing Security',
                'description' => '5G network slices shall be isolated and secured independently.',
                'category' => '5G Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.20', '8.22'],
                ],
            ],
            [
                'id' => 'TKG-5G-2',
                'title' => '5G Core Security',
                'description' => '5G core network shall implement security controls defined in 3GPP standards.',
                'category' => '5G Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.20'],
                ],
            ],
            [
                'id' => 'TKG-5G-3',
                'title' => 'Edge Computing Security',
                'description' => 'Multi-access edge computing (MEC) nodes shall be secured appropriately.',
                'category' => '5G Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.20'],
                ],
            ],

            // IoT and M2M Security
            [
                'id' => 'TKG-IOT-1',
                'title' => 'IoT Device Authentication',
                'description' => 'IoT and M2M devices shall authenticate securely to the network.',
                'category' => 'IoT/M2M Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.18'],
                ],
            ],
            [
                'id' => 'TKG-IOT-2',
                'title' => 'IoT Traffic Monitoring',
                'description' => 'IoT and M2M traffic shall be monitored for anomalies and attacks.',
                'category' => 'IoT/M2M Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.16'],
                ],
            ],

            // Roaming Security
            [
                'id' => 'TKG-ROAM-1',
                'title' => 'Roaming Agreement Security',
                'description' => 'Roaming agreements shall include security requirements and controls.',
                'category' => 'Roaming Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.20'],
                ],
            ],
            [
                'id' => 'TKG-ROAM-2',
                'title' => 'International Roaming Fraud Prevention',
                'description' => 'Mechanisms to detect and prevent roaming fraud shall be implemented.',
                'category' => 'Roaming Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.16'],
                ],
            ],

            // VoIP and Messaging Security
            [
                'id' => 'TKG-VOIP-1',
                'title' => 'VoIP Service Security',
                'description' => 'Voice over IP services shall implement encryption and authentication.',
                'category' => 'VoIP/Messaging Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                ],
            ],
            [
                'id' => 'TKG-MSG-1',
                'title' => 'SMS/Messaging Security',
                'description' => 'SMS and messaging services shall be protected against interception and spoofing.',
                'category' => 'VoIP/Messaging Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                ],
            ],

            // Compliance and Reporting
            [
                'id' => 'TKG-COMP-1',
                'title' => 'Lawful Interception Compliance',
                'description' => 'Lawful interception capabilities shall be implemented per legal requirements.',
                'category' => 'Compliance',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'legal_requirement' => '§ 170 TKG',
                ],
            ],
            [
                'id' => 'TKG-COMP-2',
                'title' => 'Data Retention Compliance',
                'description' => 'Traffic data retention shall comply with applicable legal requirements.',
                'category' => 'Compliance',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'legal_requirement' => 'Data Retention Act',
                    'asset_types' => ['data'],
                ],
            ],
        ];
    }
}
