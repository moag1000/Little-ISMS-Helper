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
    name: 'app:load-gxp-requirements',
    description: 'Load GxP (Good Practice) requirements for pharmaceutical and life sciences with ISMS data mappings'
)]
class LoadGxpRequirementsCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $symfonyStyle = new SymfonyStyle($input, $output);

        // Create or get GxP framework
        $framework = $this->entityManager->getRepository(ComplianceFramework::class)
            ->findOneBy(['code' => 'GXP']);

        if (!$framework instanceof ComplianceFramework) {
            $framework = new ComplianceFramework();
            $framework->setCode('GXP')
                ->setName('GxP - Good Practice für Pharma und Life Sciences')
                ->setDescription('IT security requirements for computerized systems in pharmaceutical and life sciences (GMP, GCP, GLP, GDP, GVP)')
                ->setVersion('2024')
                ->setApplicableIndustry('pharmaceutical')
                ->setRegulatoryBody('EMA / FDA / BfArM - EU GMP Annex 11, FDA 21 CFR Part 11')
                ->setMandatory(true)
                ->setScopeDescription('Mandatory for pharmaceutical manufacturers, clinical research, laboratories, and medical device companies')
                ->setActive(true);

            $this->entityManager->persist($framework);
        }

        $requirements = $this->getGxpRequirements();

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

        $symfonyStyle->success(sprintf('Successfully loaded %d GxP requirements', count($requirements)));

        return Command::SUCCESS;
    }

    private function getGxpRequirements(): array
    {
        return [
            // EU GMP Annex 11 - Computerized Systems
            [
                'id' => 'ANNEX11-1',
                'title' => 'Risk Management',
                'description' => 'Risk management shall be applied throughout the lifecycle of computerized systems.',
                'category' => 'Annex 11 General',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.7', '5.8'],
                    'legal_requirement' => 'EU GMP Annex 11 Clause 1',
                ],
            ],
            [
                'id' => 'ANNEX11-2',
                'title' => 'Personnel Qualification and Training',
                'description' => 'Personnel shall have appropriate qualifications and training for GxP computerized systems.',
                'category' => 'Annex 11 General',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['6.3'],
                    'legal_requirement' => 'EU GMP Annex 11 Clause 2',
                ],
            ],
            [
                'id' => 'ANNEX11-3',
                'title' => 'Supplier and Service Provider Management',
                'description' => 'Suppliers and service providers shall be assessed for GxP compliance.',
                'category' => 'Annex 11 Suppliers',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19', '5.20'],
                    'legal_requirement' => 'EU GMP Annex 11 Clause 3',
                ],
            ],
            [
                'id' => 'ANNEX11-4.1',
                'title' => 'Validation Documentation',
                'description' => 'Validation documentation shall be comprehensive and demonstrate system suitability.',
                'category' => 'Annex 11 Validation',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'legal_requirement' => 'EU GMP Annex 11 Clause 4',
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'ANNEX11-4.2',
                'title' => 'User Requirements Specification (URS)',
                'description' => 'User requirements shall be documented and form the basis for validation.',
                'category' => 'Annex 11 Validation',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'legal_requirement' => 'EU GMP Annex 11 Clause 4',
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'ANNEX11-4.3',
                'title' => 'Installation Qualification (IQ)',
                'description' => 'Installation qualification shall verify correct system installation.',
                'category' => 'Annex 11 Validation',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'legal_requirement' => 'EU GMP Annex 11 Clause 4',
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'ANNEX11-4.4',
                'title' => 'Operational Qualification (OQ)',
                'description' => 'Operational qualification shall verify system operates according to specifications.',
                'category' => 'Annex 11 Validation',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'legal_requirement' => 'EU GMP Annex 11 Clause 4',
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'ANNEX11-4.5',
                'title' => 'Performance Qualification (PQ)',
                'description' => 'Performance qualification shall demonstrate consistent system performance.',
                'category' => 'Annex 11 Validation',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'legal_requirement' => 'EU GMP Annex 11 Clause 4',
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'ANNEX11-5',
                'title' => 'Data Integrity (ALCOA+)',
                'description' => 'Data shall be Attributable, Legible, Contemporaneous, Original, Accurate, Complete, Consistent, Enduring, and Available.',
                'category' => 'Annex 11 Data',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.11'],
                    'legal_requirement' => 'EU GMP Annex 11 Clause 5',
                    'asset_types' => ['data'],
                ],
            ],
            [
                'id' => 'ANNEX11-6',
                'title' => 'Accuracy Checks',
                'description' => 'Data input and processing shall include accuracy checks.',
                'category' => 'Annex 11 Data',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'legal_requirement' => 'EU GMP Annex 11 Clause 6',
                ],
            ],
            [
                'id' => 'ANNEX11-7.1',
                'title' => 'Data Storage and Archiving',
                'description' => 'Data shall be stored securely and be readily retrievable.',
                'category' => 'Annex 11 Data Storage',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.11', '8.13'],
                    'legal_requirement' => 'EU GMP Annex 11 Clause 7',
                    'asset_types' => ['data'],
                ],
            ],
            [
                'id' => 'ANNEX11-7.2',
                'title' => 'Data Retention Period',
                'description' => 'Data shall be retained for specified periods as per regulatory requirements.',
                'category' => 'Annex 11 Data Storage',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.10'],
                    'legal_requirement' => 'EU GMP Annex 11 Clause 7',
                    'retention_period' => 'regulatory defined',
                ],
            ],
            [
                'id' => 'ANNEX11-7.3',
                'title' => 'Data Backup and Recovery',
                'description' => 'Regular backups shall be performed and recovery procedures tested.',
                'category' => 'Annex 11 Data Storage',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.13'],
                    'legal_requirement' => 'EU GMP Annex 11 Clause 7',
                ],
            ],
            [
                'id' => 'ANNEX11-8',
                'title' => 'Printouts',
                'description' => 'Where printouts are the official record, they shall be controlled.',
                'category' => 'Annex 11 Data',
                'priority' => 'high',
                'data_source_mapping' => [
                    'legal_requirement' => 'EU GMP Annex 11 Clause 8',
                ],
            ],
            [
                'id' => 'ANNEX11-9',
                'title' => 'Audit Trails',
                'description' => 'Audit trails shall track all GxP-relevant changes and deletions.',
                'category' => 'Annex 11 Audit Trails',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.15'],
                    'legal_requirement' => 'EU GMP Annex 11 Clause 9',
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'ANNEX11-9.1',
                'title' => 'Audit Trail Review',
                'description' => 'Audit trails shall be reviewed regularly.',
                'category' => 'Annex 11 Audit Trails',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.15'],
                    'legal_requirement' => 'EU GMP Annex 11 Clause 9',
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'ANNEX11-10',
                'title' => 'Change Control and Configuration Management',
                'description' => 'Changes to systems shall be controlled and documented.',
                'category' => 'Annex 11 Change Control',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.32'],
                    'legal_requirement' => 'EU GMP Annex 11 Clause 10',
                ],
            ],
            [
                'id' => 'ANNEX11-11',
                'title' => 'Periodic Evaluation',
                'description' => 'Systems shall be periodically evaluated to confirm continued suitability.',
                'category' => 'Annex 11 General',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.36'],
                    'legal_requirement' => 'EU GMP Annex 11 Clause 11',
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'ANNEX11-12.1',
                'title' => 'Security - Physical and Logical',
                'description' => 'Physical and logical security shall protect systems from unauthorized access.',
                'category' => 'Annex 11 Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['7.1', '7.2', '8.20'],
                    'legal_requirement' => 'EU GMP Annex 11 Clause 12.1',
                ],
            ],
            [
                'id' => 'ANNEX11-12.2',
                'title' => 'User Access Management',
                'description' => 'User access shall be controlled with unique IDs and passwords.',
                'category' => 'Annex 11 Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.16', '5.17', '5.18'],
                    'legal_requirement' => 'EU GMP Annex 11 Clause 12.2',
                ],
            ],
            [
                'id' => 'ANNEX11-12.3',
                'title' => 'Incident Management',
                'description' => 'Security incidents shall be reported and investigated.',
                'category' => 'Annex 11 Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.24', '5.26'],
                    'legal_requirement' => 'EU GMP Annex 11 Clause 12.3',
                    'incident_management' => true,
                ],
            ],
            [
                'id' => 'ANNEX11-13',
                'title' => 'Incident Management for Data Loss',
                'description' => 'Procedures shall address data loss and system failures.',
                'category' => 'Annex 11 Incident Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.24', '5.30'],
                    'legal_requirement' => 'EU GMP Annex 11 Clause 13',
                    'incident_management' => true,
                ],
            ],
            [
                'id' => 'ANNEX11-14',
                'title' => 'Electronic Signatures',
                'description' => 'Electronic signatures shall be equivalent to handwritten signatures.',
                'category' => 'Annex 11 Electronic Records',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'legal_requirement' => 'EU GMP Annex 11 Clause 14',
                ],
            ],
            [
                'id' => 'ANNEX11-15',
                'title' => 'Batch Release',
                'description' => 'Systems used for batch release shall be validated.',
                'category' => 'Annex 11 GMP-Specific',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'legal_requirement' => 'EU GMP Annex 11 Clause 15',
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'ANNEX11-16',
                'title' => 'Business Continuity',
                'description' => 'Business continuity plans shall address system failures.',
                'category' => 'Annex 11 Business Continuity',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.29', '5.30'],
                    'legal_requirement' => 'EU GMP Annex 11 Clause 16',
                    'bcm_required' => true,
                ],
            ],
            [
                'id' => 'ANNEX11-17',
                'title' => 'Archiving',
                'description' => 'Data archiving shall ensure long-term retrievability and integrity.',
                'category' => 'Annex 11 Data Storage',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.10'],
                    'legal_requirement' => 'EU GMP Annex 11 Clause 17',
                    'asset_types' => ['data'],
                ],
            ],

            // FDA 21 CFR Part 11 - Electronic Records and Signatures
            [
                'id' => '21CFR11-10A',
                'title' => 'Validation of Systems',
                'description' => 'Systems shall be validated to ensure accuracy, reliability, and performance.',
                'category' => '21 CFR Part 11 Requirements',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'legal_requirement' => '21 CFR Part 11.10(a)',
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => '21CFR11-10B',
                'title' => 'Ability to Generate Copies',
                'description' => 'Systems shall provide accurate and complete copies of records.',
                'category' => '21 CFR Part 11 Requirements',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'legal_requirement' => '21 CFR Part 11.10(b)',
                ],
            ],
            [
                'id' => '21CFR11-10C',
                'title' => 'Protection of Records',
                'description' => 'Records shall be protected to enable accurate retrieval.',
                'category' => '21 CFR Part 11 Requirements',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.11'],
                    'legal_requirement' => '21 CFR Part 11.10(c)',
                    'asset_types' => ['data'],
                ],
            ],
            [
                'id' => '21CFR11-10D',
                'title' => 'Limited System Access',
                'description' => 'System access shall be limited to authorized individuals.',
                'category' => '21 CFR Part 11 Requirements',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.15', '5.16', '5.18'],
                    'legal_requirement' => '21 CFR Part 11.10(d)',
                ],
            ],
            [
                'id' => '21CFR11-10E',
                'title' => 'Audit Trails for Changes',
                'description' => 'Audit trails shall document changes to records.',
                'category' => '21 CFR Part 11 Requirements',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.15'],
                    'legal_requirement' => '21 CFR Part 11.10(e)',
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => '21CFR11-10F',
                'title' => 'Operational System Checks',
                'description' => 'Operational system checks shall enforce sequence of steps.',
                'category' => '21 CFR Part 11 Requirements',
                'priority' => 'high',
                'data_source_mapping' => [
                    'legal_requirement' => '21 CFR Part 11.10(f)',
                ],
            ],
            [
                'id' => '21CFR11-10G',
                'title' => 'Authority Checks',
                'description' => 'Authority checks shall ensure only authorized individuals perform actions.',
                'category' => '21 CFR Part 11 Requirements',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.18'],
                    'legal_requirement' => '21 CFR Part 11.10(g)',
                ],
            ],
            [
                'id' => '21CFR11-10H',
                'title' => 'Device Checks',
                'description' => 'Device checks shall determine validity of data input sources.',
                'category' => '21 CFR Part 11 Requirements',
                'priority' => 'high',
                'data_source_mapping' => [
                    'legal_requirement' => '21 CFR Part 11.10(h)',
                ],
            ],
            [
                'id' => '21CFR11-10K1',
                'title' => 'Training Requirements',
                'description' => 'Personnel shall be trained on system use and data integrity importance.',
                'category' => '21 CFR Part 11 Requirements',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['6.3'],
                    'legal_requirement' => '21 CFR Part 11.10(i)',
                ],
            ],
            [
                'id' => '21CFR11-10K2',
                'title' => 'Written Policies and Procedures',
                'description' => 'Written policies shall ensure accountability and record integrity.',
                'category' => '21 CFR Part 11 Requirements',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1'],
                    'legal_requirement' => '21 CFR Part 11.10(k)',
                ],
            ],

            // GAMP 5 - Good Automated Manufacturing Practice
            [
                'id' => 'GAMP5-1',
                'title' => 'Risk-Based Approach',
                'description' => 'Validation shall use a risk-based approach appropriate to system complexity.',
                'category' => 'GAMP 5 Validation',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.7', '5.8'],
                    'certification' => 'GAMP 5',
                ],
            ],
            [
                'id' => 'GAMP5-2',
                'title' => 'Software Categories',
                'description' => 'Software shall be categorized (Category 1-5) to determine validation approach.',
                'category' => 'GAMP 5 Validation',
                'priority' => 'high',
                'data_source_mapping' => [
                    'certification' => 'GAMP 5',
                ],
            ],
            [
                'id' => 'GAMP5-3',
                'title' => 'Vendor Assessment',
                'description' => 'Software vendors shall be assessed for quality and GxP compliance.',
                'category' => 'GAMP 5 Suppliers',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19', '5.20'],
                    'certification' => 'GAMP 5',
                ],
            ],

            // Data Integrity (MHRA/FDA Guidelines)
            [
                'id' => 'DI-1',
                'title' => 'Data Governance',
                'description' => 'A data governance framework shall define roles and responsibilities.',
                'category' => 'Data Integrity',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.2'],
                    'asset_types' => ['data'],
                ],
            ],
            [
                'id' => 'DI-2',
                'title' => 'Audit Trail Immutability',
                'description' => 'Audit trails shall be immutable and cannot be disabled.',
                'category' => 'Data Integrity',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.15'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'DI-3',
                'title' => 'Data Migration Controls',
                'description' => 'Data migrations shall be validated to ensure data integrity.',
                'category' => 'Data Integrity',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'asset_types' => ['data'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'DI-4',
                'title' => 'Metadata Management',
                'description' => 'Metadata shall be captured and protected to support data interpretation.',
                'category' => 'Data Integrity',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.11'],
                    'asset_types' => ['data'],
                ],
            ],

            // Laboratory Information Management Systems (LIMS)
            [
                'id' => 'LIMS-1',
                'title' => 'LIMS Validation',
                'description' => 'LIMS shall be validated for analytical testing and results management.',
                'category' => 'LIMS',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'LIMS-2',
                'title' => 'Instrument Integration',
                'description' => 'Interfaces between LIMS and analytical instruments shall be validated.',
                'category' => 'LIMS',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'LIMS-3',
                'title' => 'Results Review and Approval',
                'description' => 'Electronic review and approval workflows shall be controlled.',
                'category' => 'LIMS',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.18'],
                ],
            ],

            // Manufacturing Execution Systems (MES)
            [
                'id' => 'MES-1',
                'title' => 'MES Validation',
                'description' => 'MES shall be validated for production control and batch management.',
                'category' => 'MES',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'MES-2',
                'title' => 'Electronic Batch Records',
                'description' => 'Electronic batch records shall ensure complete and accurate production documentation.',
                'category' => 'MES',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'asset_types' => ['data'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'MES-3',
                'title' => 'Process Control Integration',
                'description' => 'MES integration with process control systems shall be validated.',
                'category' => 'MES',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'audit_evidence' => true,
                ],
            ],

            // Cloud and SaaS in GxP
            [
                'id' => 'GXP-CLOUD-1',
                'title' => 'Cloud Provider Assessment',
                'description' => 'Cloud providers shall be assessed for GxP compliance and data integrity.',
                'category' => 'GxP Cloud',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19', '5.20'],
                    'asset_types' => ['cloud'],
                ],
            ],
            [
                'id' => 'GXP-CLOUD-2',
                'title' => 'Data Residency and Sovereignty',
                'description' => 'GxP data location and jurisdiction shall be controlled and documented.',
                'category' => 'GxP Cloud',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.20'],
                    'asset_types' => ['data', 'cloud'],
                ],
            ],
            [
                'id' => 'GXP-CLOUD-3',
                'title' => 'Cloud Service Validation',
                'description' => 'Cloud-based GxP systems shall be validated with vendor qualification.',
                'category' => 'GxP Cloud',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'asset_types' => ['cloud'],
                    'audit_evidence' => true,
                ],
            ],

            // Cybersecurity for GxP
            [
                'id' => 'GXP-CYBER-1',
                'title' => 'Cybersecurity Risk Assessment',
                'description' => 'Cybersecurity risks to GxP systems shall be assessed and mitigated.',
                'category' => 'GxP Cybersecurity',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.7', '5.8'],
                ],
            ],
            [
                'id' => 'GXP-CYBER-2',
                'title' => 'Vulnerability Management',
                'description' => 'Vulnerabilities in GxP systems shall be identified and remediated promptly.',
                'category' => 'GxP Cybersecurity',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.8'],
                ],
            ],
            [
                'id' => 'GXP-CYBER-3',
                'title' => 'Malware Protection',
                'description' => 'GxP systems shall be protected against malware with appropriate controls.',
                'category' => 'GxP Cybersecurity',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.7'],
                ],
            ],
        ];
    }
}
