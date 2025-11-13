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
    name: 'app:load-kritis-health-requirements',
    description: 'Load KRITIS Health / KHPatSiG requirements for hospitals and healthcare facilities with ISMS data mappings'
)]
class LoadKritisHealthRequirementsCommand extends Command
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Create or get KRITIS Health framework
        $framework = $this->entityManager->getRepository(ComplianceFramework::class)
            ->findOneBy(['code' => 'KRITIS-HEALTH']);

        if (!$framework) {
            $framework = new ComplianceFramework();
            $framework->setCode('KRITIS-HEALTH')
                ->setName('KRITIS Health / KHPatSiG - Krankenhaus IT-Sicherheit')
                ->setDescription('Patientendaten-Schutz-Gesetz and hospital-specific KRITIS requirements for IT security in healthcare')
                ->setVersion('2025')
                ->setApplicableIndustry('healthcare')
                ->setRegulatoryBody('BSI / Bundesministerium für Gesundheit')
                ->setMandatory(true)
                ->setScopeDescription('Mandatory for hospitals, medical care centers, and healthcare facilities providing critical healthcare services')
                ->setActive(true);

            $this->entityManager->persist($framework);
        }

        $requirements = $this->getKritisHealthRequirements();

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

        $io->success(sprintf('Successfully loaded %d KRITIS Health / KHPatSiG requirements', count($requirements)));

        return Command::SUCCESS;
    }

    private function getKritisHealthRequirements(): array
    {
        return [
            // KHPatSiG - Krankenhaus-Patientendaten-Sicherheitsgesetz
            [
                'id' => 'KHPATSIG-1.1',
                'title' => 'Information Security Officer (CISO)',
                'description' => 'Hospitals shall appoint a qualified information security officer responsible for IT security.',
                'category' => 'KHPatSiG Organization',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.2'],
                    'legal_requirement' => 'KHPatSiG',
                ],
            ],
            [
                'id' => 'KHPATSIG-1.2',
                'title' => 'IT Security Concept',
                'description' => 'A comprehensive IT security concept shall be established and regularly updated.',
                'category' => 'KHPatSiG Organization',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1', '5.2'],
                    'bsi_grundschutz' => true,
                ],
            ],
            [
                'id' => 'KHPATSIG-2.1',
                'title' => 'Patient Data Protection',
                'description' => 'Special protection measures shall be implemented for patient data including electronic health records.',
                'category' => 'Patient Data Protection',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.11', '8.24'],
                    'asset_types' => ['data'],
                    'gdpr_relevant' => true,
                ],
            ],
            [
                'id' => 'KHPATSIG-2.2',
                'title' => 'Medical Device Security',
                'description' => 'Security measures for connected medical devices shall be implemented and maintained.',
                'category' => 'Medical Device Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.19', '8.20'],
                    'asset_types' => ['hardware'],
                ],
            ],
            [
                'id' => 'KHPATSIG-3.1',
                'title' => 'Emergency Access to Patient Data',
                'description' => 'Mechanisms for emergency access to patient data shall be implemented with logging.',
                'category' => 'Patient Data Access',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.18', '8.15'],
                ],
            ],
            [
                'id' => 'KHPATSIG-3.2',
                'title' => 'Role-Based Access for Medical Staff',
                'description' => 'Access to patient data shall be granted based on roles and medical responsibilities.',
                'category' => 'Patient Data Access',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.18'],
                ],
            ],
            [
                'id' => 'KHPATSIG-4.1',
                'title' => 'Incident Response for Patient Safety',
                'description' => 'Incident response procedures shall prioritize patient safety and care continuity.',
                'category' => 'Healthcare Incident Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.24', '5.26'],
                    'incident_management' => true,
                ],
            ],
            [
                'id' => 'KHPATSIG-4.2',
                'title' => 'Ransomware Protection',
                'description' => 'Specific measures against ransomware attacks shall be implemented to protect critical healthcare systems.',
                'category' => 'Healthcare Incident Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.7', '8.13'],
                ],
            ],
            [
                'id' => 'KHPATSIG-5.1',
                'title' => 'Medical Systems Availability',
                'description' => 'High availability and redundancy shall be ensured for life-critical medical systems.',
                'category' => 'Healthcare Business Continuity',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.6', '8.14'],
                    'bcm_required' => true,
                ],
            ],
            [
                'id' => 'KHPATSIG-5.2',
                'title' => 'Emergency Operation Capability',
                'description' => 'Hospitals shall maintain capability to operate critical functions during IT failures.',
                'category' => 'Healthcare Business Continuity',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.29', '5.30'],
                    'bcm_required' => true,
                ],
            ],

            // § 75c SGB V - Digital Health Applications
            [
                'id' => 'SGB5-75c-1',
                'title' => 'Electronic Patient Record (ePA) Security',
                'description' => 'Security measures for electronic patient records shall meet federal data protection requirements.',
                'category' => 'Digital Health Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.11', '8.24'],
                    'asset_types' => ['data'],
                    'gdpr_relevant' => true,
                ],
            ],
            [
                'id' => 'SGB5-75c-2',
                'title' => 'Telematic Infrastructure (TI) Integration',
                'description' => 'Integration with German telematic infrastructure shall meet gematik specifications.',
                'category' => 'Digital Health Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.20', '8.24'],
                ],
            ],
            [
                'id' => 'SGB5-75c-3',
                'title' => 'E-Prescription Security',
                'description' => 'Electronic prescription systems shall implement strong authentication and encryption.',
                'category' => 'Digital Health Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.18', '8.24'],
                ],
            ],

            // Medical Device Regulation (MDR) IT Security Aspects
            [
                'id' => 'MDR-IT-1',
                'title' => 'Medical Device Risk Assessment',
                'description' => 'IT security risks of medical devices shall be assessed and documented.',
                'category' => 'Medical Device IT Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.7', '5.8'],
                    'asset_types' => ['hardware'],
                ],
            ],
            [
                'id' => 'MDR-IT-2',
                'title' => 'Medical Device Network Isolation',
                'description' => 'Critical medical devices shall be isolated in dedicated network segments.',
                'category' => 'Medical Device IT Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.20', '8.22'],
                ],
            ],
            [
                'id' => 'MDR-IT-3',
                'title' => 'Medical Device Patch Management',
                'description' => 'Security updates for medical devices shall be applied following manufacturer guidelines and risk assessment.',
                'category' => 'Medical Device IT Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.8'],
                ],
            ],
            [
                'id' => 'MDR-IT-4',
                'title' => 'Medical Device End-of-Life Management',
                'description' => 'Legacy medical devices no longer receiving security updates shall be isolated or replaced.',
                'category' => 'Medical Device IT Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.9', '8.20'],
                ],
            ],

            // KHZG - Krankenhauszukunftsgesetz (Hospital Future Act)
            [
                'id' => 'KHZG-1.1',
                'title' => 'Digital Infrastructure Modernization',
                'description' => 'Hospital IT infrastructure shall be modernized to meet current security standards.',
                'category' => 'KHZG Infrastructure',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.1'],
                    'legal_requirement' => 'KHZG',
                ],
            ],
            [
                'id' => 'KHZG-1.2',
                'title' => 'Cloud Services for Healthcare',
                'description' => 'Cloud services used in healthcare shall meet German data protection and security requirements.',
                'category' => 'KHZG Infrastructure',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19', '5.20'],
                    'asset_types' => ['cloud'],
                    'gdpr_relevant' => true,
                ],
            ],
            [
                'id' => 'KHZG-2.1',
                'title' => 'Digital Health Services Security',
                'description' => 'New digital health services shall implement security by design principles.',
                'category' => 'KHZG Digital Services',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.27', '8.29'],
                ],
            ],
            [
                'id' => 'KHZG-2.2',
                'title' => 'Interoperability Standards',
                'description' => 'Digital health services shall use standardized interfaces (HL7 FHIR, etc.) securely.',
                'category' => 'KHZG Digital Services',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.29'],
                ],
            ],

            // Healthcare-Specific Attack Surface
            [
                'id' => 'HEALTH-AS-1',
                'title' => 'PACS Security (Picture Archiving)',
                'description' => 'Picture Archiving and Communication Systems shall be secured against unauthorized access.',
                'category' => 'Healthcare Systems Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.11', '8.20'],
                    'asset_types' => ['data'],
                ],
            ],
            [
                'id' => 'HEALTH-AS-2',
                'title' => 'Laboratory Information System Security',
                'description' => 'Laboratory information systems shall ensure integrity and confidentiality of lab results.',
                'category' => 'Healthcare Systems Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.11'],
                ],
            ],
            [
                'id' => 'HEALTH-AS-3',
                'title' => 'Radiology Information System Security',
                'description' => 'RIS systems shall implement access controls and audit logging.',
                'category' => 'Healthcare Systems Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.18', '8.15'],
                ],
            ],
            [
                'id' => 'HEALTH-AS-4',
                'title' => 'Operating Room IT Security',
                'description' => 'IT systems in operating rooms shall be isolated and monitored for security incidents.',
                'category' => 'Healthcare Systems Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.20', '8.16'],
                ],
            ],

            // Healthcare Personnel Security
            [
                'id' => 'HEALTH-HR-1',
                'title' => 'Medical Staff Security Training',
                'description' => 'Medical staff shall receive specialized security awareness training on patient data protection.',
                'category' => 'Healthcare Personnel Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['6.3'],
                ],
            ],
            [
                'id' => 'HEALTH-HR-2',
                'title' => 'External Medical Staff Access',
                'description' => 'Visiting physicians and external medical staff shall have time-limited access rights.',
                'category' => 'Healthcare Personnel Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.16', '5.18'],
                ],
            ],
            [
                'id' => 'HEALTH-HR-3',
                'title' => 'Physician Rotation Access Management',
                'description' => 'Access rights shall be adjusted when physicians rotate between departments.',
                'category' => 'Healthcare Personnel Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.18'],
                ],
            ],

            // Healthcare Audit and Compliance
            [
                'id' => 'HEALTH-AUD-1',
                'title' => 'Patient Data Access Logging',
                'description' => 'All access to patient data shall be logged with medical justification.',
                'category' => 'Healthcare Audit',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.15'],
                    'audit_evidence' => true,
                    'gdpr_relevant' => true,
                ],
            ],
            [
                'id' => 'HEALTH-AUD-2',
                'title' => 'Medical Data Breach Notification',
                'description' => 'Patient data breaches shall be reported to data protection authority within 72 hours.',
                'category' => 'Healthcare Audit',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.26'],
                    'incident_management' => true,
                    'gdpr_relevant' => true,
                    'reporting_deadline' => '72 hours',
                ],
            ],
            [
                'id' => 'HEALTH-AUD-3',
                'title' => 'Medical Records Retention',
                'description' => 'Medical records shall be retained according to legal requirements (minimum 10 years).',
                'category' => 'Healthcare Audit',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.10'],
                    'asset_types' => ['data'],
                    'retention_period' => '10+ years',
                ],
            ],

            // Healthcare Third-Party Management
            [
                'id' => 'HEALTH-TPM-1',
                'title' => 'Medical Equipment Vendor Security',
                'description' => 'Medical equipment vendors requiring remote access shall meet security requirements.',
                'category' => 'Healthcare Third-Party',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19', '5.20'],
                ],
            ],
            [
                'id' => 'HEALTH-TPM-2',
                'title' => 'Pharmaceutical System Integration',
                'description' => 'Integration with pharmaceutical systems and pharmacies shall be secured.',
                'category' => 'Healthcare Third-Party',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.20', '8.29'],
                ],
            ],
            [
                'id' => 'HEALTH-TPM-3',
                'title' => 'Insurance Provider Data Exchange',
                'description' => 'Data exchange with health insurance providers shall use encrypted channels.',
                'category' => 'Healthcare Third-Party',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                ],
            ],

            // Telemedicine Security
            [
                'id' => 'TELE-1',
                'title' => 'Telemedicine Platform Security',
                'description' => 'Telemedicine platforms shall implement end-to-end encryption for consultations.',
                'category' => 'Telemedicine Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                ],
            ],
            [
                'id' => 'TELE-2',
                'title' => 'Remote Patient Monitoring',
                'description' => 'Remote patient monitoring devices shall transmit data securely.',
                'category' => 'Telemedicine Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                    'asset_types' => ['hardware', 'data'],
                ],
            ],
            [
                'id' => 'TELE-3',
                'title' => 'Video Consultation Security',
                'description' => 'Video consultation platforms shall ensure patient privacy and data protection.',
                'category' => 'Telemedicine Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                    'gdpr_relevant' => true,
                ],
            ],
        ];
    }
}
