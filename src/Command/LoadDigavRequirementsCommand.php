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
    name: 'app:load-digav-requirements',
    description: 'Load DiGAV (Digitale-Gesundheitsanwendungen-Verordnung) requirements for digital health apps with ISMS data mappings'
)]
class LoadDigavRequirementsCommand extends Command
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Create or get DiGAV framework
        $framework = $this->entityManager->getRepository(ComplianceFramework::class)
            ->findOneBy(['code' => 'DIGAV']);

        if (!$framework) {
            $framework = new ComplianceFramework();
            $framework->setCode('DIGAV')
                ->setName('DiGAV - Digitale-Gesundheitsanwendungen-Verordnung')
                ->setDescription('German regulation for digital health applications (DiGA) that can be prescribed by physicians')
                ->setVersion('2020')
                ->setApplicableIndustry('digital_health')
                ->setRegulatoryBody('BfArM - Bundesinstitut für Arzneimittel und Medizinprodukte')
                ->setMandatory(true)
                ->setScopeDescription('Mandatory for manufacturers of digital health applications (DiGA) seeking reimbursement in the German healthcare system')
                ->setActive(true);

            $this->entityManager->persist($framework);
        }

        $requirements = $this->getDigavRequirements();

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

        $io->success(sprintf('Successfully loaded %d DiGAV requirements', count($requirements)));

        return Command::SUCCESS;
    }

    private function getDigavRequirements(): array
    {
        return [
            // § 1 DiGAV - Scope and Definitions
            [
                'id' => 'DIGAV-1.1',
                'title' => 'Medical Device Classification',
                'description' => 'DiGA shall be certified as medical device of risk class I or IIa according to MDR.',
                'category' => 'Regulatory Classification',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'legal_requirement' => '§ 1 DiGAV',
                    'certification' => 'MDR Class I/IIa',
                ],
            ],
            [
                'id' => 'DIGAV-1.2',
                'title' => 'CE Marking',
                'description' => 'DiGA shall bear CE marking in accordance with medical device regulations.',
                'category' => 'Regulatory Classification',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'legal_requirement' => '§ 1 DiGAV',
                    'certification' => 'CE MDR',
                ],
            ],

            // § 2 DiGAV - Safety Requirements
            [
                'id' => 'DIGAV-2.1',
                'title' => 'Patient Safety',
                'description' => 'DiGA shall not pose risks to patient health or safety.',
                'category' => 'Safety Requirements',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'legal_requirement' => '§ 2 DiGAV',
                ],
            ],
            [
                'id' => 'DIGAV-2.2',
                'title' => 'Clinical Risk Management',
                'description' => 'A clinical risk management system shall be established following ISO 14971.',
                'category' => 'Safety Requirements',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'legal_requirement' => '§ 2 DiGAV',
                    'iso_controls' => ['5.7', '5.8'],
                    'certification' => 'ISO 14971',
                ],
            ],
            [
                'id' => 'DIGAV-2.3',
                'title' => 'Adverse Event Reporting',
                'description' => 'Mechanisms for reporting and tracking adverse events shall be implemented.',
                'category' => 'Safety Requirements',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'legal_requirement' => '§ 2 DiGAV',
                    'iso_controls' => ['5.26'],
                    'incident_management' => true,
                ],
            ],

            // § 3 DiGAV - Data Protection and Information Security
            [
                'id' => 'DIGAV-3.1',
                'title' => 'GDPR Compliance',
                'description' => 'DiGA shall comply with GDPR requirements for personal health data processing.',
                'category' => 'Data Protection',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'legal_requirement' => '§ 3 DiGAV',
                    'iso_controls' => ['5.34'],
                    'gdpr_relevant' => true,
                ],
            ],
            [
                'id' => 'DIGAV-3.2',
                'title' => 'Data Minimization',
                'description' => 'Only data necessary for the medical purpose shall be collected and processed.',
                'category' => 'Data Protection',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'legal_requirement' => '§ 3 DiGAV',
                    'gdpr_relevant' => true,
                ],
            ],
            [
                'id' => 'DIGAV-3.3',
                'title' => 'End-to-End Encryption',
                'description' => 'Patient health data shall be encrypted end-to-end during transmission.',
                'category' => 'Information Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'legal_requirement' => '§ 3 DiGAV',
                    'iso_controls' => ['8.24'],
                    'asset_types' => ['data'],
                ],
            ],
            [
                'id' => 'DIGAV-3.4',
                'title' => 'Data at Rest Encryption',
                'description' => 'Stored patient health data shall be encrypted according to state of the art.',
                'category' => 'Information Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'legal_requirement' => '§ 3 DiGAV',
                    'iso_controls' => ['8.24'],
                    'asset_types' => ['data'],
                ],
            ],
            [
                'id' => 'DIGAV-3.5',
                'title' => 'Data Processing Agreement',
                'description' => 'Processors of patient data shall sign data processing agreements per GDPR Art. 28.',
                'category' => 'Data Protection',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'legal_requirement' => '§ 3 DiGAV',
                    'iso_controls' => ['5.20'],
                    'gdpr_relevant' => true,
                ],
            ],

            // § 4 DiGAV - Interoperability
            [
                'id' => 'DIGAV-4.1',
                'title' => 'Data Export Functionality',
                'description' => 'DiGA shall provide functionality to export patient data in interoperable formats.',
                'category' => 'Interoperability',
                'priority' => 'high',
                'data_source_mapping' => [
                    'legal_requirement' => '§ 4 DiGAV',
                    'asset_types' => ['data'],
                ],
            ],
            [
                'id' => 'DIGAV-4.2',
                'title' => 'Standardized Data Formats',
                'description' => 'Health data shall be exportable in standardized formats (e.g., PDF, HL7 FHIR).',
                'category' => 'Interoperability',
                'priority' => 'high',
                'data_source_mapping' => [
                    'legal_requirement' => '§ 4 DiGAV',
                ],
            ],

            // § 5 DiGAV - Quality Requirements
            [
                'id' => 'DIGAV-5.1',
                'title' => 'Quality Management System',
                'description' => 'A quality management system according to ISO 13485 or ISO 9001 shall be established.',
                'category' => 'Quality Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'legal_requirement' => '§ 5 DiGAV',
                    'certification' => 'ISO 13485 or ISO 9001',
                ],
            ],
            [
                'id' => 'DIGAV-5.2',
                'title' => 'Usability and Accessibility',
                'description' => 'DiGA shall meet usability and accessibility standards including barrier-free design.',
                'category' => 'Quality Management',
                'priority' => 'high',
                'data_source_mapping' => [
                    'legal_requirement' => '§ 5 DiGAV',
                ],
            ],
            [
                'id' => 'DIGAV-5.3',
                'title' => 'User Support',
                'description' => 'Technical support and user assistance shall be provided in German language.',
                'category' => 'Quality Management',
                'priority' => 'high',
                'data_source_mapping' => [
                    'legal_requirement' => '§ 5 DiGAV',
                ],
            ],

            // § 6 DiGAV - Transparency Requirements
            [
                'id' => 'DIGAV-6.1',
                'title' => 'Privacy Policy',
                'description' => 'A clear and comprehensive privacy policy shall be provided to users.',
                'category' => 'Transparency',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'legal_requirement' => '§ 6 DiGAV',
                    'gdpr_relevant' => true,
                ],
            ],
            [
                'id' => 'DIGAV-6.2',
                'title' => 'Terms of Use',
                'description' => 'Clear terms of use including intended medical purpose shall be provided.',
                'category' => 'Transparency',
                'priority' => 'high',
                'data_source_mapping' => [
                    'legal_requirement' => '§ 6 DiGAV',
                ],
            ],
            [
                'id' => 'DIGAV-6.3',
                'title' => 'Algorithm Transparency',
                'description' => 'Medical algorithms and AI models used shall be documented and explainable.',
                'category' => 'Transparency',
                'priority' => 'high',
                'data_source_mapping' => [
                    'legal_requirement' => '§ 6 DiGAV',
                ],
            ],

            // BfArM Fast-Track Process Requirements
            [
                'id' => 'DIGAV-FT-1',
                'title' => 'Provisional Listing',
                'description' => 'DiGA may be provisionally listed if positive care effects are plausibly demonstrated.',
                'category' => 'Fast-Track Process',
                'priority' => 'medium',
                'data_source_mapping' => [
                    'legal_requirement' => 'DiGAV Fast-Track',
                ],
            ],
            [
                'id' => 'DIGAV-FT-2',
                'title' => 'Clinical Evidence',
                'description' => 'Clinical evidence of positive care effects shall be provided within 12-24 months.',
                'category' => 'Fast-Track Process',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'legal_requirement' => 'DiGAV Fast-Track',
                    'audit_evidence' => true,
                ],
            ],

            // Technical Requirements
            [
                'id' => 'DIGAV-TECH-1',
                'title' => 'Platform Support',
                'description' => 'DiGA shall be available for common platforms (iOS, Android, Web).',
                'category' => 'Technical Requirements',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.28'],
                ],
            ],
            [
                'id' => 'DIGAV-TECH-2',
                'title' => 'Offline Functionality',
                'description' => 'Core functionality shall be available offline where medically appropriate.',
                'category' => 'Technical Requirements',
                'priority' => 'medium',
                'data_source_mapping' => [
                    'iso_controls' => ['8.6'],
                ],
            ],
            [
                'id' => 'DIGAV-TECH-3',
                'title' => 'Update Management',
                'description' => 'A secure update mechanism shall be implemented for app and content updates.',
                'category' => 'Technical Requirements',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.19', '8.32'],
                ],
            ],
            [
                'id' => 'DIGAV-TECH-4',
                'title' => 'Authentication Mechanisms',
                'description' => 'Strong user authentication shall be implemented for access to health data.',
                'category' => 'Technical Requirements',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.17', '5.18'],
                ],
            ],
            [
                'id' => 'DIGAV-TECH-5',
                'title' => 'Session Management',
                'description' => 'Secure session management with automatic timeout shall be implemented.',
                'category' => 'Technical Requirements',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.5'],
                ],
            ],

            // Security Testing Requirements
            [
                'id' => 'DIGAV-SEC-1',
                'title' => 'Penetration Testing',
                'description' => 'Regular penetration testing shall be conducted by qualified security testers.',
                'category' => 'Security Testing',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.8'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'DIGAV-SEC-2',
                'title' => 'Vulnerability Management',
                'description' => 'A process for identifying and remediating security vulnerabilities shall be established.',
                'category' => 'Security Testing',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.8'],
                ],
            ],
            [
                'id' => 'DIGAV-SEC-3',
                'title' => 'Security Incident Response',
                'description' => 'A security incident response plan shall be established and tested.',
                'category' => 'Security Testing',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.24', '5.26'],
                    'incident_management' => true,
                ],
            ],

            // Mobile App Security
            [
                'id' => 'DIGAV-MOB-1',
                'title' => 'Secure Code Practices',
                'description' => 'Mobile app shall be developed following secure coding standards (OWASP Mobile).',
                'category' => 'Mobile Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.28'],
                ],
            ],
            [
                'id' => 'DIGAV-MOB-2',
                'title' => 'Local Data Protection',
                'description' => 'Data stored locally on devices shall be encrypted and protected.',
                'category' => 'Mobile Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.11', '8.24'],
                    'asset_types' => ['data'],
                ],
            ],
            [
                'id' => 'DIGAV-MOB-3',
                'title' => 'Certificate Pinning',
                'description' => 'Certificate pinning shall be implemented for API communications.',
                'category' => 'Mobile Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                ],
            ],
            [
                'id' => 'DIGAV-MOB-4',
                'title' => 'Jailbreak/Root Detection',
                'description' => 'App shall detect and warn about compromised devices (jailbreak/root).',
                'category' => 'Mobile Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.7'],
                ],
            ],

            // Backend Security
            [
                'id' => 'DIGAV-BE-1',
                'title' => 'API Security',
                'description' => 'Backend APIs shall implement authentication, authorization, and rate limiting.',
                'category' => 'Backend Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.29'],
                ],
            ],
            [
                'id' => 'DIGAV-BE-2',
                'title' => 'Server Hardening',
                'description' => 'Backend servers shall be hardened according to security best practices.',
                'category' => 'Backend Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.9'],
                ],
            ],
            [
                'id' => 'DIGAV-BE-3',
                'title' => 'Database Security',
                'description' => 'Databases shall implement encryption, access controls, and audit logging.',
                'category' => 'Backend Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.11', '8.15', '8.24'],
                ],
            ],

            // Cloud Infrastructure (if applicable)
            [
                'id' => 'DIGAV-CLOUD-1',
                'title' => 'Cloud Provider Selection',
                'description' => 'Cloud providers shall meet German/EU data protection requirements.',
                'category' => 'Cloud Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19', '5.20'],
                    'asset_types' => ['cloud'],
                    'gdpr_relevant' => true,
                ],
            ],
            [
                'id' => 'DIGAV-CLOUD-2',
                'title' => 'Data Residency',
                'description' => 'Patient data shall be stored within Germany or EU unless consent is obtained.',
                'category' => 'Cloud Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.20'],
                    'asset_types' => ['data', 'cloud'],
                    'gdpr_relevant' => true,
                ],
            ],
            [
                'id' => 'DIGAV-CLOUD-3',
                'title' => 'Backup and Recovery',
                'description' => 'Regular backups shall be performed and recovery procedures tested.',
                'category' => 'Cloud Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.13'],
                ],
            ],
        ];
    }
}
