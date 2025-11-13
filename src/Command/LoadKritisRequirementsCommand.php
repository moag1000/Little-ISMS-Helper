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
    name: 'app:load-kritis-requirements',
    description: 'Load KRITIS (§ 8a BSIG) requirements for operators of critical infrastructures with ISMS data mappings'
)]
class LoadKritisRequirementsCommand extends Command
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Create or get KRITIS framework
        $framework = $this->entityManager->getRepository(ComplianceFramework::class)
            ->findOneBy(['code' => 'KRITIS']);

        if (!$framework) {
            $framework = new ComplianceFramework();
            $framework->setCode('KRITIS')
                ->setName('KRITIS § 8a BSIG - Kritische Infrastrukturen')
                ->setDescription('German Critical Infrastructure regulations according to § 8a BSI Act (BSIG) with IT Security Act 2.0 requirements')
                ->setVersion('2024')
                ->setApplicableIndustry('critical_infrastructure')
                ->setRegulatoryBody('BSI - Bundesamt für Sicherheit in der Informationstechnik')
                ->setMandatory(true)
                ->setScopeDescription('Mandatory for operators of critical infrastructures in sectors: Energy, IT/Telecom, Transport, Health, Water, Food, Finance/Insurance, Government, Media, Waste Management')
                ->setActive(true);

            $this->entityManager->persist($framework);
        }

        $requirements = $this->getKritisRequirements();

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

        $io->success(sprintf('Successfully loaded %d KRITIS § 8a BSIG requirements', count($requirements)));

        return Command::SUCCESS;
    }

    private function getKritisRequirements(): array
    {
        return [
            // § 8a Abs. 1 BSIG - General Security Requirements
            [
                'id' => 'KRITIS-8a-1.1',
                'title' => 'Appropriate Organizational and Technical Precautions',
                'description' => 'KRITIS operators shall implement appropriate organizational and technical precautions to prevent disruptions to their IT systems.',
                'category' => '§ 8a Abs. 1 BSIG',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1', '5.2'],
                    'bsi_grundschutz' => true,
                    'legal_requirement' => '§ 8a Abs. 1 BSIG',
                ],
            ],
            [
                'id' => 'KRITIS-8a-1.2',
                'title' => 'State of the Art Security',
                'description' => 'Security measures shall correspond to the state of the art (Stand der Technik) and be continuously updated.',
                'category' => '§ 8a Abs. 1 BSIG',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1', '8.8'],
                    'bsi_grundschutz' => true,
                    'legal_requirement' => '§ 8a Abs. 1 Satz 2 BSIG',
                ],
            ],
            [
                'id' => 'KRITIS-8a-1.3',
                'title' => 'IT Security Concept',
                'description' => 'A comprehensive IT security concept shall be established based on BSI IT-Grundschutz or equivalent standards.',
                'category' => '§ 8a Abs. 1 BSIG',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1', '5.2'],
                    'bsi_grundschutz' => true,
                    'audit_evidence' => true,
                ],
            ],

            // § 8a Abs. 1a BSIG - Attack Detection Systems (IT Security Act 2.0)
            [
                'id' => 'KRITIS-8a-1a.1',
                'title' => 'Attack Detection Systems Implementation',
                'description' => 'KRITIS operators shall deploy systems for the detection of attacks on their IT systems (Angriffserkennung).',
                'category' => '§ 8a Abs. 1a BSIG Attack Detection',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.15', '8.16'],
                    'legal_requirement' => '§ 8a Abs. 1a BSIG (IT-SiG 2.0)',
                    'mandatory_since' => '2021-05-28',
                ],
            ],
            [
                'id' => 'KRITIS-8a-1a.2',
                'title' => 'Real-Time Attack Detection',
                'description' => 'Attack detection systems shall operate in real-time or near real-time to enable timely response.',
                'category' => '§ 8a Abs. 1a BSIG Attack Detection',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.16'],
                    'legal_requirement' => '§ 8a Abs. 1a BSIG',
                ],
            ],
            [
                'id' => 'KRITIS-8a-1a.3',
                'title' => 'Attack Pattern Recognition',
                'description' => 'Systems shall be capable of recognizing known attack patterns and anomalous behavior.',
                'category' => '§ 8a Abs. 1a BSIG Attack Detection',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.16'],
                    'legal_requirement' => '§ 8a Abs. 1a BSIG',
                ],
            ],
            [
                'id' => 'KRITIS-8a-1a.4',
                'title' => 'Attack Detection Coverage',
                'description' => 'Attack detection shall cover all critical IT systems and network segments.',
                'category' => '§ 8a Abs. 1a BSIG Attack Detection',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.15', '8.16'],
                    'legal_requirement' => '§ 8a Abs. 1a BSIG',
                ],
            ],
            [
                'id' => 'KRITIS-8a-1a.5',
                'title' => 'Automated Alerting',
                'description' => 'Automated alerting mechanisms shall notify responsible personnel of detected attacks.',
                'category' => '§ 8a Abs. 1a BSIG Attack Detection',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.24', '8.16'],
                    'incident_management' => true,
                ],
            ],

            // § 8a Abs. 3 BSIG - Audit and Certification Requirements
            [
                'id' => 'KRITIS-8a-3.1',
                'title' => 'Biennial Security Audit',
                'description' => 'KRITIS operators shall have security measures audited at least every two years.',
                'category' => '§ 8a Abs. 3 BSIG Audit Requirements',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.35', '5.36'],
                    'audit_evidence' => true,
                    'legal_requirement' => '§ 8a Abs. 3 Satz 1 BSIG',
                    'audit_interval' => 'every 2 years',
                ],
            ],
            [
                'id' => 'KRITIS-8a-3.2',
                'title' => 'Qualified Auditor',
                'description' => 'Audits shall be conducted by qualified and BSI-certified auditors or recognized certification bodies.',
                'category' => '§ 8a Abs. 3 BSIG Audit Requirements',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.36'],
                    'audit_evidence' => true,
                    'legal_requirement' => '§ 8a Abs. 3 BSIG',
                ],
            ],
            [
                'id' => 'KRITIS-8a-3.3',
                'title' => 'Audit Documentation Submission',
                'description' => 'Audit documentation shall be submitted to BSI within the specified timeframe.',
                'category' => '§ 8a Abs. 3 BSIG Audit Requirements',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.36'],
                    'audit_evidence' => true,
                    'legal_requirement' => '§ 8a Abs. 3 Satz 2 BSIG',
                ],
            ],
            [
                'id' => 'KRITIS-8a-3.4',
                'title' => 'ISO 27001 Certification Alternative',
                'description' => 'A valid ISO 27001 certification on the basis of IT-Grundschutz may fulfill audit requirements.',
                'category' => '§ 8a Abs. 3 BSIG Audit Requirements',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['all'],
                    'bsi_grundschutz' => true,
                    'audit_evidence' => true,
                    'certification' => 'ISO 27001',
                ],
            ],

            // § 8b BSIG - Incident Reporting Obligations
            [
                'id' => 'KRITIS-8b-4.1',
                'title' => 'Reporting Significant IT Security Incidents',
                'description' => 'KRITIS operators shall immediately report significant disruptions to IT security to BSI.',
                'category' => '§ 8b BSIG Incident Reporting',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.24', '5.26'],
                    'incident_management' => true,
                    'legal_requirement' => '§ 8b Abs. 4 BSIG',
                ],
            ],
            [
                'id' => 'KRITIS-8b-4.2',
                'title' => 'Incident Classification Criteria',
                'description' => 'Incidents shall be classified according to impact on availability, integrity, authenticity, or confidentiality.',
                'category' => '§ 8b BSIG Incident Reporting',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.24', '5.25'],
                    'incident_management' => true,
                ],
            ],
            [
                'id' => 'KRITIS-8b-4.3',
                'title' => 'Immediate Incident Notification',
                'description' => 'Significant incidents shall be reported to BSI without undue delay, typically within 24 hours.',
                'category' => '§ 8b BSIG Incident Reporting',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.26'],
                    'incident_management' => true,
                    'legal_requirement' => '§ 8b Abs. 4 Satz 1 BSIG',
                    'reporting_deadline' => '24 hours',
                ],
            ],
            [
                'id' => 'KRITIS-8b-4.4',
                'title' => 'Detailed Incident Information',
                'description' => 'Incident reports shall include technical details, impact assessment, affected systems, and remediation measures.',
                'category' => '§ 8b BSIG Incident Reporting',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.26', '5.27'],
                    'incident_management' => true,
                ],
            ],
            [
                'id' => 'KRITIS-8b-5.1',
                'title' => 'BSI Incident Information Sharing',
                'description' => 'BSI may inform other KRITIS operators anonymously about relevant IT security incidents.',
                'category' => '§ 8b BSIG Incident Reporting',
                'priority' => 'medium',
                'data_source_mapping' => [
                    'iso_controls' => ['5.26'],
                    'legal_requirement' => '§ 8b Abs. 5 BSIG',
                ],
            ],

            // BSI Requirements Catalogue - Core Categories
            // Category: Organization (ISMS.1)
            [
                'id' => 'KRITIS-ISMS-1.1',
                'title' => 'Information Security Management System',
                'description' => 'An ISMS shall be established, implemented, maintained, and continuously improved.',
                'category' => 'ISMS Organization',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1', '5.2'],
                    'bsi_grundschutz' => true,
                ],
            ],
            [
                'id' => 'KRITIS-ISMS-1.2',
                'title' => 'Information Security Policy',
                'description' => 'Top management shall establish and communicate an information security policy.',
                'category' => 'ISMS Organization',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1'],
                    'bsi_grundschutz' => true,
                ],
            ],
            [
                'id' => 'KRITIS-ISMS-1.3',
                'title' => 'Security Organization Structure',
                'description' => 'Roles and responsibilities for information security shall be defined and assigned.',
                'category' => 'ISMS Organization',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.2', '5.3'],
                ],
            ],
            [
                'id' => 'KRITIS-ISMS-1.4',
                'title' => 'Information Security Officer',
                'description' => 'A qualified information security officer (CISO) shall be appointed.',
                'category' => 'ISMS Organization',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.2'],
                    'bsi_grundschutz' => true,
                ],
            ],

            // Category: Asset Management (ISMS.2)
            [
                'id' => 'KRITIS-ISMS-2.1',
                'title' => 'Critical Asset Identification',
                'description' => 'All assets critical to the provision of critical services shall be identified and documented.',
                'category' => 'Asset Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.9'],
                    'asset_types' => ['hardware', 'software', 'data', 'services', 'cloud'],
                ],
            ],
            [
                'id' => 'KRITIS-ISMS-2.2',
                'title' => 'Asset Inventory',
                'description' => 'A comprehensive and current inventory of all IT assets shall be maintained.',
                'category' => 'Asset Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.9'],
                    'asset_types' => ['hardware', 'software', 'data'],
                ],
            ],
            [
                'id' => 'KRITIS-ISMS-2.3',
                'title' => 'Asset Ownership',
                'description' => 'Each asset shall have an identified owner responsible for its security.',
                'category' => 'Asset Management',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.9'],
                ],
            ],
            [
                'id' => 'KRITIS-ISMS-2.4',
                'title' => 'Asset Classification',
                'description' => 'Assets shall be classified according to their criticality and protection requirements.',
                'category' => 'Asset Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.12'],
                ],
            ],

            // Category: Risk Management (ISMS.3)
            [
                'id' => 'KRITIS-ISMS-3.1',
                'title' => 'Risk Assessment Methodology',
                'description' => 'A systematic risk assessment methodology shall be established and applied.',
                'category' => 'Risk Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.7', '5.8'],
                    'bsi_grundschutz' => true,
                ],
            ],
            [
                'id' => 'KRITIS-ISMS-3.2',
                'title' => 'Risk Identification',
                'description' => 'Risks to critical IT systems and services shall be systematically identified.',
                'category' => 'Risk Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.7'],
                ],
            ],
            [
                'id' => 'KRITIS-ISMS-3.3',
                'title' => 'Risk Analysis and Evaluation',
                'description' => 'Identified risks shall be analyzed and evaluated to determine priority.',
                'category' => 'Risk Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.8'],
                ],
            ],
            [
                'id' => 'KRITIS-ISMS-3.4',
                'title' => 'Risk Treatment',
                'description' => 'Appropriate risk treatment measures shall be selected and implemented.',
                'category' => 'Risk Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.8'],
                ],
            ],
            [
                'id' => 'KRITIS-ISMS-3.5',
                'title' => 'Regular Risk Review',
                'description' => 'Risk assessments shall be reviewed and updated at regular intervals.',
                'category' => 'Risk Management',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.7'],
                    'audit_evidence' => true,
                ],
            ],

            // Category: Access Control (ISMS.4)
            [
                'id' => 'KRITIS-ISMS-4.1',
                'title' => 'Access Control Policy',
                'description' => 'An access control policy based on business and security requirements shall be established.',
                'category' => 'Access Control',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.15'],
                ],
            ],
            [
                'id' => 'KRITIS-ISMS-4.2',
                'title' => 'User Access Management',
                'description' => 'User access rights shall be provisioned, reviewed, and revoked through formal processes.',
                'category' => 'Access Control',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.16', '5.18'],
                ],
            ],
            [
                'id' => 'KRITIS-ISMS-4.3',
                'title' => 'Privileged Access Management',
                'description' => 'Privileged access rights shall be strictly controlled and monitored.',
                'category' => 'Access Control',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.2', '8.3'],
                ],
            ],
            [
                'id' => 'KRITIS-ISMS-4.4',
                'title' => 'Multi-Factor Authentication',
                'description' => 'Multi-factor authentication shall be implemented for remote and privileged access.',
                'category' => 'Access Control',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.17', '5.18'],
                ],
            ],
            [
                'id' => 'KRITIS-ISMS-4.5',
                'title' => 'Access Rights Review',
                'description' => 'User access rights shall be reviewed regularly and adjusted as necessary.',
                'category' => 'Access Control',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.18'],
                    'audit_evidence' => true,
                ],
            ],

            // Category: Cryptography (ISMS.5)
            [
                'id' => 'KRITIS-ISMS-5.1',
                'title' => 'Cryptographic Controls Policy',
                'description' => 'A policy on the use of cryptographic controls shall be developed and implemented.',
                'category' => 'Cryptography',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                ],
            ],
            [
                'id' => 'KRITIS-ISMS-5.2',
                'title' => 'Data Encryption',
                'description' => 'Sensitive data shall be encrypted at rest and in transit using approved algorithms.',
                'category' => 'Cryptography',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                    'asset_types' => ['data'],
                ],
            ],
            [
                'id' => 'KRITIS-ISMS-5.3',
                'title' => 'Key Management',
                'description' => 'Cryptographic keys shall be managed securely throughout their lifecycle.',
                'category' => 'Cryptography',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                ],
            ],

            // Category: Physical Security (ISMS.6)
            [
                'id' => 'KRITIS-ISMS-6.1',
                'title' => 'Security Perimeters',
                'description' => 'Physical security perimeters shall be defined and protected with appropriate controls.',
                'category' => 'Physical Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['7.1', '7.2'],
                ],
            ],
            [
                'id' => 'KRITIS-ISMS-6.2',
                'title' => 'Physical Access Control',
                'description' => 'Access to facilities housing critical IT systems shall be controlled and monitored.',
                'category' => 'Physical Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['7.2'],
                ],
            ],
            [
                'id' => 'KRITIS-ISMS-6.3',
                'title' => 'Environmental Protection',
                'description' => 'Protection against environmental threats shall be designed and implemented.',
                'category' => 'Physical Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['7.4'],
                ],
            ],

            // Category: Operations Security (ISMS.7)
            [
                'id' => 'KRITIS-ISMS-7.1',
                'title' => 'Change Management',
                'description' => 'Changes to IT systems shall be controlled through a formal change management process.',
                'category' => 'Operations Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.32'],
                ],
            ],
            [
                'id' => 'KRITIS-ISMS-7.2',
                'title' => 'Vulnerability Management',
                'description' => 'Technical vulnerabilities shall be identified, assessed, and remediated in a timely manner.',
                'category' => 'Operations Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.8'],
                ],
            ],
            [
                'id' => 'KRITIS-ISMS-7.3',
                'title' => 'Patch Management',
                'description' => 'Security patches shall be tested and deployed according to risk and criticality.',
                'category' => 'Operations Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.8'],
                ],
            ],
            [
                'id' => 'KRITIS-ISMS-7.4',
                'title' => 'Malware Protection',
                'description' => 'Protection against malware shall be implemented and maintained.',
                'category' => 'Operations Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.7'],
                ],
            ],
            [
                'id' => 'KRITIS-ISMS-7.5',
                'title' => 'Backup Management',
                'description' => 'Regular backups shall be performed and tested to ensure recoverability.',
                'category' => 'Operations Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.13'],
                ],
            ],
            [
                'id' => 'KRITIS-ISMS-7.6',
                'title' => 'Logging and Monitoring',
                'description' => 'Security-relevant events shall be logged and monitored continuously.',
                'category' => 'Operations Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.15', '8.16'],
                ],
            ],

            // Category: Network Security (ISMS.8)
            [
                'id' => 'KRITIS-ISMS-8.1',
                'title' => 'Network Segmentation',
                'description' => 'Networks shall be segmented based on criticality and security requirements.',
                'category' => 'Network Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.20', '8.22'],
                ],
            ],
            [
                'id' => 'KRITIS-ISMS-8.2',
                'title' => 'Network Access Control',
                'description' => 'Access to network services shall be controlled through authentication and authorization.',
                'category' => 'Network Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.20', '8.21'],
                ],
            ],
            [
                'id' => 'KRITIS-ISMS-8.3',
                'title' => 'Firewall and Filtering',
                'description' => 'Network boundaries shall be protected with firewalls and traffic filtering.',
                'category' => 'Network Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.20'],
                ],
            ],
            [
                'id' => 'KRITIS-ISMS-8.4',
                'title' => 'Secure Remote Access',
                'description' => 'Remote access to critical systems shall be secured with VPN and MFA.',
                'category' => 'Network Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.20', '5.18'],
                ],
            ],

            // Category: Incident Management (ISMS.9)
            [
                'id' => 'KRITIS-ISMS-9.1',
                'title' => 'Incident Response Plan',
                'description' => 'An incident response plan shall be established, documented, and regularly tested.',
                'category' => 'Incident Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.24', '5.26'],
                    'incident_management' => true,
                ],
            ],
            [
                'id' => 'KRITIS-ISMS-9.2',
                'title' => 'Incident Detection',
                'description' => 'Mechanisms for detecting security incidents shall be implemented and monitored.',
                'category' => 'Incident Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.24', '8.16'],
                    'incident_management' => true,
                ],
            ],
            [
                'id' => 'KRITIS-ISMS-9.3',
                'title' => 'Incident Response Team',
                'description' => 'A qualified incident response team shall be established with defined responsibilities.',
                'category' => 'Incident Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.24'],
                    'incident_management' => true,
                ],
            ],
            [
                'id' => 'KRITIS-ISMS-9.4',
                'title' => 'Incident Documentation',
                'description' => 'All security incidents shall be documented with details for analysis and improvement.',
                'category' => 'Incident Management',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.24', '5.27'],
                    'incident_management' => true,
                ],
            ],

            // Category: Business Continuity (ISMS.10)
            [
                'id' => 'KRITIS-ISMS-10.1',
                'title' => 'Business Continuity Planning',
                'description' => 'Business continuity plans shall be developed for all critical services.',
                'category' => 'Business Continuity',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.29', '5.30'],
                    'bcm_required' => true,
                ],
            ],
            [
                'id' => 'KRITIS-ISMS-10.2',
                'title' => 'Disaster Recovery Plans',
                'description' => 'IT disaster recovery plans shall be established and regularly tested.',
                'category' => 'Business Continuity',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.30'],
                    'bcm_required' => true,
                ],
            ],
            [
                'id' => 'KRITIS-ISMS-10.3',
                'title' => 'Recovery Time Objectives',
                'description' => 'RTO and RPO shall be defined for all critical systems and services.',
                'category' => 'Business Continuity',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.30'],
                    'bcm_required' => true,
                ],
            ],
            [
                'id' => 'KRITIS-ISMS-10.4',
                'title' => 'BCM Testing',
                'description' => 'Business continuity and disaster recovery plans shall be tested at least annually.',
                'category' => 'Business Continuity',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.30'],
                    'bcm_required' => true,
                    'audit_evidence' => true,
                ],
            ],

            // Category: Supplier Management (ISMS.11)
            [
                'id' => 'KRITIS-ISMS-11.1',
                'title' => 'Supplier Security Assessment',
                'description' => 'Security requirements shall be assessed before engaging suppliers of critical services.',
                'category' => 'Supplier Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19', '5.20'],
                ],
            ],
            [
                'id' => 'KRITIS-ISMS-11.2',
                'title' => 'Supplier Agreements',
                'description' => 'Security requirements shall be documented in supplier agreements.',
                'category' => 'Supplier Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.20'],
                ],
            ],
            [
                'id' => 'KRITIS-ISMS-11.3',
                'title' => 'Supplier Monitoring',
                'description' => 'Suppliers shall be monitored for compliance with security requirements.',
                'category' => 'Supplier Management',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.22'],
                ],
            ],

            // Category: Compliance (ISMS.12)
            [
                'id' => 'KRITIS-ISMS-12.1',
                'title' => 'Legal and Regulatory Compliance',
                'description' => 'Compliance with applicable legal and regulatory requirements shall be ensured.',
                'category' => 'Compliance',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.31'],
                ],
            ],
            [
                'id' => 'KRITIS-ISMS-12.2',
                'title' => 'Internal Audits',
                'description' => 'Internal audits shall be conducted regularly to assess compliance and effectiveness.',
                'category' => 'Compliance',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.35'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'KRITIS-ISMS-12.3',
                'title' => 'Management Review',
                'description' => 'Top management shall review the ISMS at planned intervals.',
                'category' => 'Compliance',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.36'],
                    'audit_evidence' => true,
                ],
            ],

            // Category: Communications Security (COS)
            [
                'id' => 'KRITIS-COS-1.1',
                'title' => 'Network Controls',
                'description' => 'Networks shall be controlled and protected with appropriate security mechanisms.',
                'category' => 'Communications Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.20'],
                ],
            ],
            [
                'id' => 'KRITIS-COS-1.2',
                'title' => 'Transmission Security',
                'description' => 'Information in transit shall be protected through appropriate encryption mechanisms.',
                'category' => 'Communications Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                ],
            ],
            [
                'id' => 'KRITIS-COS-1.3',
                'title' => 'Electronic Messaging',
                'description' => 'Electronic messaging shall be secured with appropriate controls.',
                'category' => 'Communications Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                ],
            ],
            [
                'id' => 'KRITIS-COS-1.4',
                'title' => 'Network Segregation',
                'description' => 'Information services, users and information systems shall be segregated on networks.',
                'category' => 'Communications Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.22'],
                ],
            ],
            [
                'id' => 'KRITIS-COS-1.5',
                'title' => 'Confidentiality Agreements',
                'description' => 'Requirements for confidentiality or non-disclosure agreements shall be identified and documented.',
                'category' => 'Communications Security',
                'priority' => 'medium',
                'data_source_mapping' => [
                    'iso_controls' => ['5.14'],
                ],
            ],
            [
                'id' => 'KRITIS-COS-1.6',
                'title' => 'Remote Diagnostic and Configuration',
                'description' => 'Remote diagnostic and configuration ports shall be secured.',
                'category' => 'Communications Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.20'],
                ],
            ],
            [
                'id' => 'KRITIS-COS-1.7',
                'title' => 'Wireless Network Security',
                'description' => 'Wireless networks shall be secured with appropriate authentication and encryption.',
                'category' => 'Communications Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.20', '8.24'],
                ],
            ],
            [
                'id' => 'KRITIS-COS-1.8',
                'title' => 'VPN Security',
                'description' => 'Virtual Private Networks shall use strong encryption and authentication.',
                'category' => 'Communications Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                ],
            ],
            [
                'id' => 'KRITIS-COS-1.9',
                'title' => 'Network Connection Control',
                'description' => 'Connections to networks shall be controlled and restricted.',
                'category' => 'Communications Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.20', '8.21'],
                ],
            ],
            [
                'id' => 'KRITIS-COS-1.10',
                'title' => 'Information Transfer Policies',
                'description' => 'Policies and procedures for information transfer shall be established.',
                'category' => 'Communications Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.14'],
                ],
            ],

            // Category: System Development (DEV)
            [
                'id' => 'KRITIS-DEV-1.1',
                'title' => 'Security Requirements Analysis',
                'description' => 'Security requirements shall be identified and documented in the development lifecycle.',
                'category' => 'System Development',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.27'],
                ],
            ],
            [
                'id' => 'KRITIS-DEV-1.2',
                'title' => 'Secure Development Lifecycle',
                'description' => 'A secure development lifecycle shall be established and followed.',
                'category' => 'System Development',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.25', '8.27'],
                ],
            ],
            [
                'id' => 'KRITIS-DEV-1.3',
                'title' => 'Application Security',
                'description' => 'Applications shall be designed and developed with security controls.',
                'category' => 'System Development',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.28', '8.29'],
                ],
            ],
            [
                'id' => 'KRITIS-DEV-1.4',
                'title' => 'Secure Coding Standards',
                'description' => 'Secure coding standards shall be established and enforced.',
                'category' => 'System Development',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.28'],
                ],
            ],
            [
                'id' => 'KRITIS-DEV-1.5',
                'title' => 'Development Testing',
                'description' => 'Security testing shall be performed during development.',
                'category' => 'System Development',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.29'],
                ],
            ],
            [
                'id' => 'KRITIS-DEV-1.6',
                'title' => 'Test Data Protection',
                'description' => 'Test data shall be selected carefully and protected.',
                'category' => 'System Development',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.33'],
                ],
            ],
            [
                'id' => 'KRITIS-DEV-1.7',
                'title' => 'Acceptance Testing',
                'description' => 'Acceptance testing programs shall include security testing.',
                'category' => 'System Development',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.29'],
                ],
            ],
            [
                'id' => 'KRITIS-DEV-1.8',
                'title' => 'System Engineering Principles',
                'description' => 'Principles for engineering secure systems shall be established and applied.',
                'category' => 'System Development',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.27'],
                ],
            ],
            [
                'id' => 'KRITIS-DEV-1.9',
                'title' => 'Outsourced Development',
                'description' => 'Outsourced development activities shall be supervised and monitored.',
                'category' => 'System Development',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.20', '8.27'],
                ],
            ],
            [
                'id' => 'KRITIS-DEV-1.10',
                'title' => 'Technical Vulnerability Scanning',
                'description' => 'Systems shall be scanned for technical vulnerabilities regularly.',
                'category' => 'System Development',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.8'],
                ],
            ],

            // Category: Identity Management Extended (IDM)
            [
                'id' => 'KRITIS-IDM-1.1',
                'title' => 'User Registration Process',
                'description' => 'A formal user registration process shall control user identity assignment.',
                'category' => 'Identity Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.16'],
                ],
            ],
            [
                'id' => 'KRITIS-IDM-1.2',
                'title' => 'User De-registration',
                'description' => 'User de-registration shall be performed promptly when access is no longer required.',
                'category' => 'Identity Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.16'],
                ],
            ],
            [
                'id' => 'KRITIS-IDM-1.3',
                'title' => 'Information Access Restriction',
                'description' => 'Access to information and application system functions shall be restricted.',
                'category' => 'Identity Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.15', '5.18'],
                ],
            ],
            [
                'id' => 'KRITIS-IDM-1.4',
                'title' => 'Secure Authentication',
                'description' => 'Secure authentication methods shall be implemented for all users.',
                'category' => 'Identity Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.17', '5.18'],
                ],
            ],
            [
                'id' => 'KRITIS-IDM-1.5',
                'title' => 'Password Management System',
                'description' => 'A password management system shall enforce password quality requirements.',
                'category' => 'Identity Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.17', '8.5'],
                ],
            ],
            [
                'id' => 'KRITIS-IDM-1.6',
                'title' => 'Use of Secret Authentication',
                'description' => 'Users shall be required to follow good security practices for secret authentication information.',
                'category' => 'Identity Management',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.17'],
                ],
            ],
            [
                'id' => 'KRITIS-IDM-1.7',
                'title' => 'User Account Management',
                'description' => 'User accounts shall be managed through a formal process.',
                'category' => 'Identity Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.16', '5.18'],
                ],
            ],
            [
                'id' => 'KRITIS-IDM-1.8',
                'title' => 'Privileged Utility Programs',
                'description' => 'Use of privileged utility programs shall be restricted and controlled.',
                'category' => 'Identity Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.4'],
                ],
            ],

            // Category: Advanced Cryptography (CRY)
            [
                'id' => 'KRITIS-CRY-1.1',
                'title' => 'Cryptographic Policy',
                'description' => 'A policy on the use of cryptographic controls shall be developed.',
                'category' => 'Advanced Cryptography',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                ],
            ],
            [
                'id' => 'KRITIS-CRY-1.2',
                'title' => 'Encryption Key Management',
                'description' => 'Key management processes shall support the use of cryptographic techniques.',
                'category' => 'Advanced Cryptography',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                ],
            ],
            [
                'id' => 'KRITIS-CRY-1.3',
                'title' => 'Digital Signatures',
                'description' => 'Digital signatures shall be used where appropriate for authentication and integrity.',
                'category' => 'Advanced Cryptography',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                ],
            ],
            [
                'id' => 'KRITIS-CRY-1.4',
                'title' => 'Certificate Management',
                'description' => 'Digital certificates shall be managed securely throughout their lifecycle.',
                'category' => 'Advanced Cryptography',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                ],
            ],
            [
                'id' => 'KRITIS-CRY-1.5',
                'title' => 'Cryptographic Algorithm Selection',
                'description' => 'Cryptographic algorithms shall be selected based on industry standards and best practices.',
                'category' => 'Advanced Cryptography',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                ],
            ],
            [
                'id' => 'KRITIS-CRY-1.6',
                'title' => 'Key Generation',
                'description' => 'Cryptographic keys shall be generated using approved methods.',
                'category' => 'Advanced Cryptography',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                ],
            ],
            [
                'id' => 'KRITIS-CRY-1.7',
                'title' => 'Key Storage and Protection',
                'description' => 'Cryptographic keys shall be protected against unauthorized disclosure and modification.',
                'category' => 'Advanced Cryptography',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                ],
            ],
            [
                'id' => 'KRITIS-CRY-1.8',
                'title' => 'Key Destruction',
                'description' => 'Cryptographic keys shall be destroyed securely when no longer needed.',
                'category' => 'Advanced Cryptography',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                ],
            ],

            // Category: Enhanced Physical Security (PHY)
            [
                'id' => 'KRITIS-PHY-1.1',
                'title' => 'Delivery and Loading Areas',
                'description' => 'Delivery and loading areas shall be controlled and isolated from information processing facilities.',
                'category' => 'Enhanced Physical Security',
                'priority' => 'medium',
                'data_source_mapping' => [
                    'iso_controls' => ['7.5'],
                ],
            ],
            [
                'id' => 'KRITIS-PHY-1.2',
                'title' => 'Clear Desk and Clear Screen',
                'description' => 'Clear desk and clear screen policies shall be adopted.',
                'category' => 'Enhanced Physical Security',
                'priority' => 'medium',
                'data_source_mapping' => [
                    'iso_controls' => ['7.7'],
                ],
            ],
            [
                'id' => 'KRITIS-PHY-1.3',
                'title' => 'Equipment Removal',
                'description' => 'Equipment shall not be removed from the site without authorization.',
                'category' => 'Enhanced Physical Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['7.12'],
                ],
            ],
            [
                'id' => 'KRITIS-PHY-1.4',
                'title' => 'Asset Returns',
                'description' => 'Employees and external party users shall return all organizational assets upon termination.',
                'category' => 'Enhanced Physical Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['7.13'],
                ],
            ],
            [
                'id' => 'KRITIS-PHY-1.5',
                'title' => 'Unattended User Equipment',
                'description' => 'Users shall ensure that unattended equipment has appropriate protection.',
                'category' => 'Enhanced Physical Security',
                'priority' => 'medium',
                'data_source_mapping' => [
                    'iso_controls' => ['7.7'],
                ],
            ],

            // Category: Advanced Operations Security (OPS)
            [
                'id' => 'KRITIS-OPS-EXT-1.1',
                'title' => 'Separation of Development and Production',
                'description' => 'Development, testing and operational environments shall be separated.',
                'category' => 'Advanced Operations',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.31'],
                ],
            ],
            [
                'id' => 'KRITIS-OPS-EXT-1.2',
                'title' => 'Information Classification',
                'description' => 'Information shall be classified in terms of legal requirements, value, criticality and sensitivity.',
                'category' => 'Advanced Operations',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.12'],
                ],
            ],
            [
                'id' => 'KRITIS-OPS-EXT-1.3',
                'title' => 'Information Labeling',
                'description' => 'Information shall be labeled according to its classification.',
                'category' => 'Advanced Operations',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.13'],
                ],
            ],
            [
                'id' => 'KRITIS-OPS-EXT-1.4',
                'title' => 'Information Handling',
                'description' => 'Procedures for information handling shall be established.',
                'category' => 'Advanced Operations',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.12', '5.13'],
                ],
            ],
            [
                'id' => 'KRITIS-OPS-EXT-1.5',
                'title' => 'Media Disposal',
                'description' => 'Media shall be disposed of securely when no longer required.',
                'category' => 'Advanced Operations',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.10'],
                ],
            ],
            [
                'id' => 'KRITIS-OPS-EXT-1.6',
                'title' => 'Media Transportation',
                'description' => 'Media containing information shall be protected against unauthorized access during transport.',
                'category' => 'Advanced Operations',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.11'],
                ],
            ],
            [
                'id' => 'KRITIS-OPS-EXT-1.7',
                'title' => 'Secure Disposal of Equipment',
                'description' => 'Equipment containing storage media shall be verified to ensure data removal before disposal.',
                'category' => 'Advanced Operations',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.10'],
                ],
            ],
            [
                'id' => 'KRITIS-OPS-EXT-1.8',
                'title' => 'Mobile Device Policy',
                'description' => 'A policy for mobile devices shall be adopted and implemented.',
                'category' => 'Advanced Operations',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['6.7'],
                ],
            ],
            [
                'id' => 'KRITIS-OPS-EXT-1.9',
                'title' => 'Teleworking Security',
                'description' => 'Security measures for teleworking shall be implemented.',
                'category' => 'Advanced Operations',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['6.7'],
                ],
            ],
            [
                'id' => 'KRITIS-OPS-EXT-1.10',
                'title' => 'Information Backup Testing',
                'description' => 'Backup information and software shall be regularly tested.',
                'category' => 'Advanced Operations',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.13'],
                    'bcm_required' => true,
                ],
            ],

            // Category: Network Security Extended (NET)
            [
                'id' => 'KRITIS-NET-1.1',
                'title' => 'Network Security Services',
                'description' => 'Security features and service levels of network services shall be identified.',
                'category' => 'Network Security Extended',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.20', '8.21'],
                ],
            ],
            [
                'id' => 'KRITIS-NET-1.2',
                'title' => 'Security in Network Services',
                'description' => 'Security mechanisms and service levels shall be included in network service agreements.',
                'category' => 'Network Security Extended',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.21'],
                ],
            ],
            [
                'id' => 'KRITIS-NET-1.3',
                'title' => 'Network Routing Control',
                'description' => 'Routing controls shall be implemented for networks to ensure secure routes.',
                'category' => 'Network Security Extended',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.20'],
                ],
            ],
            [
                'id' => 'KRITIS-NET-1.4',
                'title' => 'Network Traffic Filtering',
                'description' => 'Network traffic shall be filtered using firewalls and similar devices.',
                'category' => 'Network Security Extended',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.20'],
                ],
            ],
            [
                'id' => 'KRITIS-NET-1.5',
                'title' => 'DMZ Implementation',
                'description' => 'Demilitarized zones shall be implemented to separate external and internal networks.',
                'category' => 'Network Security Extended',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.20', '8.22'],
                ],
            ],
            [
                'id' => 'KRITIS-NET-1.6',
                'title' => 'Protocol Security',
                'description' => 'Network protocols shall be configured securely.',
                'category' => 'Network Security Extended',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.20'],
                ],
            ],
            [
                'id' => 'KRITIS-NET-1.7',
                'title' => 'Network Monitoring',
                'description' => 'Networks shall be monitored for security events and anomalies.',
                'category' => 'Network Security Extended',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.16'],
                ],
            ],
            [
                'id' => 'KRITIS-NET-1.8',
                'title' => 'Network Documentation',
                'description' => 'Network architecture and configurations shall be documented.',
                'category' => 'Network Security Extended',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.20'],
                ],
            ],

            // Category: Application Security (APP)
            [
                'id' => 'KRITIS-APP-1.1',
                'title' => 'Input Data Validation',
                'description' => 'Input data validation shall be implemented in applications.',
                'category' => 'Application Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.28'],
                ],
            ],
            [
                'id' => 'KRITIS-APP-1.2',
                'title' => 'Output Data Validation',
                'description' => 'Output data validation shall be implemented in applications.',
                'category' => 'Application Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.28'],
                ],
            ],
            [
                'id' => 'KRITIS-APP-1.3',
                'title' => 'Authenticity of Transactions',
                'description' => 'Controls shall protect authenticity of transactions.',
                'category' => 'Application Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.28'],
                ],
            ],
            [
                'id' => 'KRITIS-APP-1.4',
                'title' => 'Session Management',
                'description' => 'Application session management shall be implemented securely.',
                'category' => 'Application Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.5'],
                ],
            ],
            [
                'id' => 'KRITIS-APP-1.5',
                'title' => 'Secure Application Interfaces',
                'description' => 'Application programming interfaces (APIs) shall be secured.',
                'category' => 'Application Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.29'],
                ],
            ],

            // Category: Data Protection and Privacy (DPP)
            [
                'id' => 'KRITIS-DPP-1.1',
                'title' => 'Privacy and Protection of PII',
                'description' => 'Privacy and protection of personally identifiable information shall be ensured.',
                'category' => 'Data Protection',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                    'gdpr_relevant' => true,
                ],
            ],
            [
                'id' => 'KRITIS-DPP-1.2',
                'title' => 'Data Minimization',
                'description' => 'Only necessary personal data shall be collected and processed.',
                'category' => 'Data Protection',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                    'gdpr_relevant' => true,
                ],
            ],
            [
                'id' => 'KRITIS-DPP-1.3',
                'title' => 'Purpose Limitation',
                'description' => 'Personal data shall be processed only for specified purposes.',
                'category' => 'Data Protection',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                    'gdpr_relevant' => true,
                ],
            ],
            [
                'id' => 'KRITIS-DPP-1.4',
                'title' => 'Data Subject Rights',
                'description' => 'Processes shall support data subject rights (access, correction, deletion).',
                'category' => 'Data Protection',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                    'gdpr_relevant' => true,
                ],
            ],
            [
                'id' => 'KRITIS-DPP-1.5',
                'title' => 'Data Breach Notification',
                'description' => 'Procedures for data breach notification shall be established.',
                'category' => 'Data Protection',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.26'],
                    'gdpr_relevant' => true,
                    'incident_management' => true,
                ],
            ],

            // § 14 BSIG - Penalties
            [
                'id' => 'KRITIS-14.1',
                'title' => 'Penalty Framework',
                'description' => 'Violations of § 8a or § 8b BSIG constitute administrative offenses with fines up to 2 million euros.',
                'category' => 'Legal Consequences',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'legal_requirement' => '§ 14 Abs. 1 BSIG',
                    'penalty_amount' => 'up to 2 million EUR',
                ],
            ],
        ];
    }
}
