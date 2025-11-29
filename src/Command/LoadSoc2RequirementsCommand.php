<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\Option;
use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:load-soc2-requirements',
    description: 'Load SOC 2 Trust Services Criteria with ISO 27001 control mappings'
)]
class LoadSoc2RequirementsCommand
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function __invoke(#[Option(name: 'update', shortcut: 'u', mode: InputOption::VALUE_NONE, description: 'Update existing requirements instead of skipping them')]
    bool $update = false, ?SymfonyStyle $symfonyStyle = null): int
    {
        $updateMode = $update;

        $symfonyStyle->title('Loading SOC 2 Trust Services Criteria');
        $symfonyStyle->text(sprintf('Mode: %s', $updateMode ? 'UPDATE existing' : 'CREATE new (skip existing)'));

        // Create or get SOC 2 framework
        $framework = $this->entityManager->getRepository(ComplianceFramework::class)
            ->findOneBy(['code' => 'SOC2']);

        if (!$framework instanceof ComplianceFramework) {
            $framework = new ComplianceFramework();
            $framework->setCode('SOC2')
                ->setName('SOC 2 Type II')
                ->setDescription('Service Organization Control 2 - Trust Services Criteria')
                ->setVersion('2017')
                ->setApplicableIndustry('service providers')
                ->setRegulatoryBody('AICPA (American Institute of CPAs)')
                ->setMandatory(false)
                ->setScopeDescription('Audit standard for service organizations to demonstrate security, availability, processing integrity, confidentiality, and privacy controls')
                ->setActive(true);

            $this->entityManager->persist($framework);
            $symfonyStyle->text('✓ Created framework');
        } else {
            $symfonyStyle->text('✓ Framework exists');
        }

        $requirements = $this->getSoc2Requirements();
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        foreach ($requirements as $reqData) {
            $existing = $this->entityManager->getRepository(ComplianceRequirement::class)
                ->findOneBy([
                    'complianceFramework' => $framework,
                    'requirementId' => $reqData['id']
                ]);

            if ($existing instanceof ComplianceRequirement) {
                if ($updateMode) {
                    $existing->setTitle($reqData['title'])
                        ->setDescription($reqData['description'])
                        ->setCategory($reqData['category'])
                        ->setPriority($reqData['priority'])
                        ->setDataSourceMapping($reqData['data_source_mapping']);
                    $stats['updated']++;
                } else {
                    $stats['skipped']++;
                }
            } else {
                $requirement = new ComplianceRequirement();
                $requirement->setFramework($framework)
                    ->setRequirementId($reqData['id'])
                    ->setTitle($reqData['title'])
                    ->setDescription($reqData['description'])
                    ->setCategory($reqData['category'])
                    ->setPriority($reqData['priority'])
                    ->setDataSourceMapping($reqData['data_source_mapping']);

                $this->entityManager->persist($requirement);
                $stats['created']++;
            }

            // Batch flush
            if (($stats['created'] + $stats['updated']) % 50 === 0) {
                $this->entityManager->flush();
            }
        }

        $this->entityManager->flush();

        $symfonyStyle->success('SOC 2 requirements loaded!');
        $symfonyStyle->table(
            ['Action', 'Count'],
            [
                ['Created', $stats['created']],
                ['Updated', $stats['updated']],
                ['Skipped', $stats['skipped']],
                ['Total', count($requirements)],
            ]
        );

        return Command::SUCCESS;
    }

    private function getSoc2Requirements(): array
    {
        return [
            // Common Criteria (CC) - Apply to all Trust Services Categories

            // CC1: Control Environment
            [
                'id' => 'CC1.1',
                'title' => 'COSO Principles and Organizational Structure',
                'description' => 'The entity demonstrates a commitment to integrity and ethical values, exercises oversight responsibility, establishes structure, authority, and responsibility.',
                'category' => 'Common Criteria - Control Environment',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['5.1', '5.2', '5.4']],
            ],
            [
                'id' => 'CC1.2',
                'title' => 'Board Independence and Oversight',
                'description' => 'The board of directors demonstrates independence from management and exercises oversight of the development and performance of internal control.',
                'category' => 'Common Criteria - Control Environment',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['5.4', '5.35']],
            ],
            [
                'id' => 'CC1.3',
                'title' => 'Organizational Structure and Responsibility',
                'description' => 'Management establishes, with board oversight, structures, reporting lines, and appropriate authorities and responsibilities.',
                'category' => 'Common Criteria - Control Environment',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['5.2', '5.3']],
            ],
            [
                'id' => 'CC1.4',
                'title' => 'Commitment to Competence',
                'description' => 'The entity demonstrates a commitment to attract, develop, and retain competent individuals in alignment with objectives.',
                'category' => 'Common Criteria - Control Environment',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['6.3']],
            ],
            [
                'id' => 'CC1.5',
                'title' => 'Accountability and Performance Measures',
                'description' => 'The entity holds individuals accountable for their internal control responsibilities.',
                'category' => 'Common Criteria - Control Environment',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['5.2', '6.4']],
            ],

            // CC2: Communication and Information
            [
                'id' => 'CC2.1',
                'title' => 'Information Quality',
                'description' => 'The entity obtains or generates and uses relevant, quality information to support the functioning of internal control.',
                'category' => 'Common Criteria - Communication and Information',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['5.37', '8.15']],
            ],
            [
                'id' => 'CC2.2',
                'title' => 'Internal Communication',
                'description' => 'The entity internally communicates information, including objectives and responsibilities for internal control.',
                'category' => 'Common Criteria - Communication and Information',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['5.1', '6.8']],
            ],
            [
                'id' => 'CC2.3',
                'title' => 'External Communication',
                'description' => 'The entity communicates with external parties regarding matters affecting the functioning of internal control.',
                'category' => 'Common Criteria - Communication and Information',
                'priority' => 'medium',
                'data_source_mapping' => ['iso_controls' => ['5.5', '5.6']],
            ],

            // CC3: Risk Assessment
            [
                'id' => 'CC3.1',
                'title' => 'Specification of Objectives',
                'description' => 'The entity specifies objectives with sufficient clarity to enable the identification and assessment of risks.',
                'category' => 'Common Criteria - Risk Assessment',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['5.1']],
            ],
            [
                'id' => 'CC3.2',
                'title' => 'Risk Identification',
                'description' => 'The entity identifies risks to the achievement of its objectives and analyzes risks as a basis for determining how to manage them.',
                'category' => 'Common Criteria - Risk Assessment',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['5.7']],
            ],
            [
                'id' => 'CC3.3',
                'title' => 'Fraud Risk Assessment',
                'description' => 'The entity considers the potential for fraud in assessing risks to the achievement of objectives.',
                'category' => 'Common Criteria - Risk Assessment',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['5.7']],
            ],
            [
                'id' => 'CC3.4',
                'title' => 'Change Impact Assessment',
                'description' => 'The entity identifies and assesses changes that could significantly impact the system of internal control.',
                'category' => 'Common Criteria - Risk Assessment',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['8.32']],
            ],

            // CC4: Monitoring Activities
            [
                'id' => 'CC4.1',
                'title' => 'Ongoing and Separate Evaluations',
                'description' => 'The entity selects, develops, and performs ongoing and/or separate evaluations to ascertain whether components of internal control are present and functioning.',
                'category' => 'Common Criteria - Monitoring',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['5.35', '5.36']],
            ],
            [
                'id' => 'CC4.2',
                'title' => 'Evaluation and Communication of Deficiencies',
                'description' => 'The entity evaluates and communicates internal control deficiencies in a timely manner to those responsible for taking corrective action.',
                'category' => 'Common Criteria - Monitoring',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['5.27', '6.8']],
            ],

            // CC5: Control Activities
            [
                'id' => 'CC5.1',
                'title' => 'Control Activities to Achieve Objectives',
                'description' => 'The entity selects and develops control activities that contribute to the mitigation of risks to acceptable levels.',
                'category' => 'Common Criteria - Control Activities',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['5.1']],
            ],
            [
                'id' => 'CC5.2',
                'title' => 'Technology Controls',
                'description' => 'The entity selects and develops general control activities over technology to support the achievement of objectives.',
                'category' => 'Common Criteria - Control Activities',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['8.1', '8.9', '8.20']],
            ],
            [
                'id' => 'CC5.3',
                'title' => 'Deployment of Control Activities',
                'description' => 'The entity deploys control activities through policies and procedures.',
                'category' => 'Common Criteria - Control Activities',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['5.1', '5.37']],
            ],

            // CC6: Logical and Physical Access Controls
            [
                'id' => 'CC6.1',
                'title' => 'Logical Access - Authentication',
                'description' => 'The entity implements logical access security software, infrastructure, and architectures over protected information assets.',
                'category' => 'Common Criteria - Logical and Physical Access',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['5.15', '5.16', '5.17', '8.5']],
            ],
            [
                'id' => 'CC6.2',
                'title' => 'Access Authorization',
                'description' => 'Prior to issuing system credentials and granting access, the entity registers and authorizes new users.',
                'category' => 'Common Criteria - Logical and Physical Access',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['5.16', '5.18']],
            ],
            [
                'id' => 'CC6.3',
                'title' => 'User Access Removal',
                'description' => 'The entity removes access when appropriate in a timely manner.',
                'category' => 'Common Criteria - Logical and Physical Access',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['5.11', '5.18', '6.5']],
            ],
            [
                'id' => 'CC6.4',
                'title' => 'Physical Access',
                'description' => 'The entity restricts physical access to facilities and protected information assets.',
                'category' => 'Common Criteria - Logical and Physical Access',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['7.1', '7.2', '7.3']],
            ],
            [
                'id' => 'CC6.5',
                'title' => 'Logical Access Removal or Modification',
                'description' => 'The entity discontinues logical and physical protections over physical assets only after the ability to read or recover data has been diminished.',
                'category' => 'Common Criteria - Logical and Physical Access',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['7.14', '8.10']],
            ],
            [
                'id' => 'CC6.6',
                'title' => 'Multi-Factor Authentication',
                'description' => 'The entity implements multi-factor authentication for access to critical systems and data.',
                'category' => 'Common Criteria - Logical and Physical Access',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['8.5']],
            ],
            [
                'id' => 'CC6.7',
                'title' => 'Access Restriction to Data and Resources',
                'description' => 'The entity restricts the transmission, movement, and removal of information to authorized users.',
                'category' => 'Common Criteria - Logical and Physical Access',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['5.14', '8.3', '8.12']],
            ],
            [
                'id' => 'CC6.8',
                'title' => 'Access Review and Recertification',
                'description' => 'The entity implements controls to prevent or detect and act upon the introduction of unauthorized or malicious software.',
                'category' => 'Common Criteria - Logical and Physical Access',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['5.18', '8.7']],
            ],

            // CC7: System Operations
            [
                'id' => 'CC7.1',
                'title' => 'System Operations Management',
                'description' => 'To meet its objectives, the entity uses detection and monitoring procedures to identify anomalies.',
                'category' => 'Common Criteria - System Operations',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['8.15', '8.16']],
            ],
            [
                'id' => 'CC7.2',
                'title' => 'Incident Response and Management',
                'description' => 'The entity monitors system components and the operation of those components for anomalies that are indicative of malicious acts, natural disasters, and errors.',
                'category' => 'Common Criteria - System Operations',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['5.24', '5.25', '5.26', '8.16']],
            ],
            [
                'id' => 'CC7.3',
                'title' => 'Detection and Response to Security Incidents',
                'description' => 'The entity evaluates security events to determine whether they could impact the achievement of objectives.',
                'category' => 'Common Criteria - System Operations',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['5.25', '5.26']],
            ],
            [
                'id' => 'CC7.4',
                'title' => 'Incident Containment and Recovery',
                'description' => 'The entity responds to identified security incidents by executing a defined incident response program.',
                'category' => 'Common Criteria - System Operations',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['5.26', '5.27']],
            ],
            [
                'id' => 'CC7.5',
                'title' => 'Evidence Collection and Forensics',
                'description' => 'The entity identifies, develops, and implements activities to recover from identified security incidents.',
                'category' => 'Common Criteria - System Operations',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['5.28']],
            ],

            // CC8: Change Management
            [
                'id' => 'CC8.1',
                'title' => 'Change Management Process',
                'description' => 'The entity authorizes, designs, develops or acquires, configures, documents, tests, approves, and implements changes to infrastructure, data, software, and procedures.',
                'category' => 'Common Criteria - Change Management',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['8.32']],
            ],

            // CC9: Risk Mitigation
            [
                'id' => 'CC9.1',
                'title' => 'Vendor and Business Partner Risk',
                'description' => 'The entity identifies, selects, and manages vendors and business partners based on established criteria.',
                'category' => 'Common Criteria - Risk Mitigation',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['5.19', '5.20', '5.21', '5.22']],
            ],
            [
                'id' => 'CC9.2',
                'title' => 'Third-Party Agreements and Monitoring',
                'description' => 'The entity assesses and monitors the risks associated with vendors and business partners.',
                'category' => 'Common Criteria - Risk Mitigation',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['5.20', '5.22']],
            ],

            // A1: Availability
            [
                'id' => 'A1.1',
                'title' => 'Capacity and Performance Management',
                'description' => 'The entity maintains, monitors, and evaluates current processing capacity and use of system components.',
                'category' => 'Availability',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['8.6', '8.14']],
            ],
            [
                'id' => 'A1.2',
                'title' => 'Backup and Recovery',
                'description' => 'The entity authorizes, designs, develops, implements, operates, approves, maintains, and monitors environmental protections, software, data backup processes, and recovery infrastructure.',
                'category' => 'Availability',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['7.5', '7.11', '8.13', '8.14']],
            ],
            [
                'id' => 'A1.3',
                'title' => 'Recovery and Business Continuity',
                'description' => 'The entity tests recovery plan procedures supporting system recovery.',
                'category' => 'Availability',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['5.29', '5.30']],
            ],

            // C1: Confidentiality
            [
                'id' => 'C1.1',
                'title' => 'Confidential Information Protection',
                'description' => 'The entity identifies and maintains confidential information to meet the entity\'s objectives.',
                'category' => 'Confidentiality',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['5.12', '5.13']],
            ],
            [
                'id' => 'C1.2',
                'title' => 'Data Classification and Encryption',
                'description' => 'The entity disposes of confidential information to meet the entity\'s objectives.',
                'category' => 'Confidentiality',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['7.14', '8.10', '8.24']],
            ],

            // P1: Privacy (subset of most relevant)
            [
                'id' => 'P1.1',
                'title' => 'Privacy Notice',
                'description' => 'The entity provides notice to data subjects about its privacy practices.',
                'category' => 'Privacy',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['5.34']],
            ],
            [
                'id' => 'P2.1',
                'title' => 'Choice and Consent',
                'description' => 'The entity communicates choices available regarding collection, use, retention, disclosure, and disposal of personal information.',
                'category' => 'Privacy',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['5.34']],
            ],
            [
                'id' => 'P3.1',
                'title' => 'Data Collection',
                'description' => 'The entity collects personal information only for purposes identified in the notice.',
                'category' => 'Privacy',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['5.34']],
            ],
            [
                'id' => 'P4.1',
                'title' => 'Access and Accuracy',
                'description' => 'The entity provides data subjects with access to their personal information for review and update.',
                'category' => 'Privacy',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['5.34']],
            ],
            [
                'id' => 'P5.1',
                'title' => 'Data Disclosure to Third Parties',
                'description' => 'The entity discloses personal information to third parties with the explicit consent of data subjects.',
                'category' => 'Privacy',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['5.14', '5.34']],
            ],
            [
                'id' => 'P6.1',
                'title' => 'Data Quality and Retention',
                'description' => 'The entity retains personal information consistent with its objectives.',
                'category' => 'Privacy',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['5.33', '5.34', '8.10']],
            ],
            [
                'id' => 'P7.1',
                'title' => 'Data Disposal',
                'description' => 'The entity securely disposes of personal information to meet its objectives.',
                'category' => 'Privacy',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['7.14', '8.10']],
            ],

            // PI1: Processing Integrity
            [
                'id' => 'PI1.1',
                'title' => 'Data Input Completeness and Accuracy',
                'description' => 'The entity obtains or generates, uses, and communicates relevant, quality information regarding the objectives related to processing.',
                'category' => 'Processing Integrity',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['8.26', '8.27', '8.29']],
            ],
            [
                'id' => 'PI1.2',
                'title' => 'Processing Completeness and Accuracy',
                'description' => 'The entity processes data completely, accurately, and in a timely manner authorized by the system objectives.',
                'category' => 'Processing Integrity',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['8.25', '8.29']],
            ],
            [
                'id' => 'PI1.3',
                'title' => 'Data Output Completeness and Accuracy',
                'description' => 'The entity produces output that is complete, accurate, and timely to meet the entity\'s objectives.',
                'category' => 'Processing Integrity',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['8.29']],
            ],
            [
                'id' => 'PI1.4',
                'title' => 'Error Detection and Correction',
                'description' => 'The entity implements policies and procedures over system inputs, processing, and outputs to identify and address processing deviations.',
                'category' => 'Processing Integrity',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['8.15', '8.16']],
            ],
            [
                'id' => 'PI1.5',
                'title' => 'Storage and Retention',
                'description' => 'The entity implements policies and procedures to store inputs, items in processing, and outputs completely and accurately.',
                'category' => 'Processing Integrity',
                'priority' => 'medium',
                'data_source_mapping' => ['iso_controls' => ['5.33', '7.10', '8.13']],
            ],
        ];
    }
}
