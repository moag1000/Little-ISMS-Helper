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
    name: 'app:load-iso27701-requirements',
    description: 'Load ISO 27701:2019 Privacy Information Management System (PIMS) requirements with ISMS data mappings'
)]
class LoadIso27701RequirementsCommand extends Command
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Create or get ISO 27701 framework
        $framework = $this->entityManager->getRepository(ComplianceFramework::class)
            ->findOneBy(['code' => 'ISO27701']);

        if (!$framework) {
            $framework = new ComplianceFramework();
            $framework->setCode('ISO27701')
                ->setName('ISO/IEC 27701:2019 - Privacy Information Management System (PIMS)')
                ->setDescription('Extension to ISO/IEC 27001 and ISO/IEC 27002 for privacy information management')
                ->setVersion('2019')
                ->setApplicableIndustry('all_sectors')
                ->setRegulatoryBody('ISO/IEC')
                ->setMandatory(false)
                ->setScopeDescription('Provides guidance for establishing, implementing, maintaining and continually improving a Privacy Information Management System (PIMS)')
                ->setActive(true);

            $this->entityManager->persist($framework);
        } else {
            // Framework exists - check if requirements are already loaded
            $existingRequirements = $this->entityManager
                ->getRepository(ComplianceRequirement::class)
                ->findBy(['framework' => $framework]);

            if (!empty($existingRequirements)) {
                $io->warning(sprintf(
                    'Framework ISO 27701 already has %d requirements loaded. Skipping to avoid duplicates.',
                    count($existingRequirements)
                ));
                return Command::SUCCESS;
            }

            // Framework exists but has no requirements - update timestamp
            $framework->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->persist($framework);
        }

        try {
            $this->entityManager->beginTransaction();

            $requirements = $this->getIso27701Requirements();

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
            $this->entityManager->commit();

            $io->success(sprintf('Successfully loaded %d ISO 27701 requirements', count($requirements)));
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            $io->error('Failed to load ISO 27701 requirements: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function getIso27701Requirements(): array
    {
        return [
            // Section 5: PIMS-specific requirements related to ISO/IEC 27001
            [
                'id' => '27701-5.2.1',
                'title' => 'Understanding the organization and its context (Privacy)',
                'description' => 'The organization shall determine external and internal issues relevant to its purpose and that affect its ability to achieve the intended outcomes of its PIMS.',
                'category' => 'PIMS Context',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1', '5.2'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => '27701-5.2.2',
                'title' => 'Understanding the needs and expectations of interested parties (Privacy)',
                'description' => 'The organization shall determine interested parties relevant to the PIMS and their privacy requirements.',
                'category' => 'PIMS Context',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.2'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => '27701-5.3',
                'title' => 'Determining the scope of the PIMS',
                'description' => 'The organization shall determine the boundaries and applicability of the PIMS to establish its scope, considering PII processing activities.',
                'category' => 'PIMS Scope',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => '27701-5.4.1',
                'title' => 'Privacy Information Management System',
                'description' => 'The organization shall establish, implement, maintain and continually improve a PIMS in accordance with the requirements of this document.',
                'category' => 'PIMS Implementation',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1'],
                    'audit_evidence' => true,
                ],
            ],

            // Section 6: Planning for the PIMS
            [
                'id' => '27701-6.1.1',
                'title' => 'Actions to address risks and opportunities (Privacy)',
                'description' => 'When planning for the PIMS, the organization shall consider privacy-related risks and opportunities.',
                'category' => 'PIMS Planning',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.7', '8.2'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => '27701-6.1.2',
                'title' => 'PII protection impact assessment',
                'description' => 'The organization shall establish and maintain a process for conducting PII protection impact assessments (PPIA).',
                'category' => 'Privacy Impact Assessment',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.7'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => '27701-6.2',
                'title' => 'Privacy objectives and planning to achieve them',
                'description' => 'The organization shall establish privacy objectives at relevant functions and levels.',
                'category' => 'PIMS Planning',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.2'],
                    'audit_evidence' => true,
                ],
            ],

            // Section 7: Support for the PIMS
            [
                'id' => '27701-7.2.2',
                'title' => 'Competence (Privacy-specific)',
                'description' => 'The organization shall ensure persons with PII processing responsibilities have appropriate competence.',
                'category' => 'PIMS Support',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['6.3'],
                ],
            ],
            [
                'id' => '27701-7.3',
                'title' => 'Awareness (Privacy)',
                'description' => 'The organization shall ensure persons doing work under its control are aware of privacy policies and their contribution to the PIMS.',
                'category' => 'Awareness',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['6.3'],
                ],
            ],
            [
                'id' => '27701-7.4.1',
                'title' => 'Communication (Privacy)',
                'description' => 'The organization shall determine the need for internal and external communications relevant to the PIMS including privacy notices.',
                'category' => 'Communication',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => '27701-7.5.1',
                'title' => 'Documented information (Privacy-specific)',
                'description' => 'The PIMS shall include documented information for privacy-specific requirements including records of PII processing.',
                'category' => 'Documentation',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                    'audit_evidence' => true,
                ],
            ],

            // Section 8: Operation of the PIMS
            [
                'id' => '27701-8.2',
                'title' => 'PII protection impact assessment',
                'description' => 'The organization shall carry out PII protection impact assessments for PII processing that could lead to high privacy risks.',
                'category' => 'Operations',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.7'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => '27701-8.3',
                'title' => 'Working with PII processors',
                'description' => 'The organization shall ensure PII processors provide sufficient guarantees to implement appropriate technical and organizational measures.',
                'category' => 'Third Party Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19', '5.20', '5.21'],
                ],
            ],
            [
                'id' => '27701-8.4',
                'title' => 'Third country transfers',
                'description' => 'The organization shall identify where PII is transferred to other jurisdictions and ensure appropriate safeguards.',
                'category' => 'Data Transfers',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.14'],
                ],
            ],

            // Section 9: Performance evaluation
            [
                'id' => '27701-9.1',
                'title' => 'Monitoring, measurement, analysis and evaluation (Privacy)',
                'description' => 'The organization shall evaluate privacy performance and the effectiveness of the PIMS.',
                'category' => 'Performance Evaluation',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => '27701-9.2',
                'title' => 'Internal audit (PIMS)',
                'description' => 'The organization shall conduct internal audits at planned intervals for the PIMS.',
                'category' => 'Audit',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => '27701-9.3',
                'title' => 'Management review (PIMS)',
                'description' => 'Top management shall review the PIMS at planned intervals.',
                'category' => 'Management Review',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1'],
                    'audit_evidence' => true,
                ],
            ],

            // Section 10: Improvement
            [
                'id' => '27701-10.1',
                'title' => 'Nonconformity and corrective action (Privacy)',
                'description' => 'When a privacy-related nonconformity occurs, the organization shall react and take corrective action.',
                'category' => 'Improvement',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.28'],
                    'incident_management' => true,
                ],
            ],
            [
                'id' => '27701-10.2',
                'title' => 'Continual improvement (PIMS)',
                'description' => 'The organization shall continually improve the suitability, adequacy and effectiveness of the PIMS.',
                'category' => 'Improvement',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1'],
                    'audit_evidence' => true,
                ],
            ],

            // Annex A: Additional ISO/IEC 27002 guidance for PII controllers
            [
                'id' => '27701-A.7.2.1',
                'title' => 'Identify and document purpose (Controller)',
                'description' => 'PII controllers shall identify and document specific, explicit and legitimate purposes for PII processing.',
                'category' => 'PII Controller Obligations',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                    'asset_types' => ['data'],
                ],
            ],
            [
                'id' => '27701-A.7.2.2',
                'title' => 'Identify lawful basis (Controller)',
                'description' => 'PII controllers shall identify and document the lawful basis for PII processing.',
                'category' => 'PII Controller Obligations',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => '27701-A.7.2.3',
                'title' => 'Determine when and how consent is obtained (Controller)',
                'description' => 'PII controllers shall determine circumstances when consent is required and establish procedures for obtaining it.',
                'category' => 'Consent Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                ],
            ],
            [
                'id' => '27701-A.7.2.4',
                'title' => 'Provide privacy notices (Controller)',
                'description' => 'PII controllers shall provide PII principals with clear and easily accessible privacy notices.',
                'category' => 'Transparency',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                ],
            ],
            [
                'id' => '27701-A.7.2.5',
                'title' => 'Provide mechanism to modify or withdraw consent (Controller)',
                'description' => 'PII controllers shall provide mechanisms for PII principals to modify or withdraw consent.',
                'category' => 'Consent Management',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                ],
            ],
            [
                'id' => '27701-A.7.3.1',
                'title' => 'Limit collection (Controller)',
                'description' => 'PII controllers shall limit the collection of PII to what is adequate, relevant and necessary.',
                'category' => 'Data Minimization',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                    'asset_types' => ['data'],
                ],
            ],
            [
                'id' => '27701-A.7.3.2',
                'title' => 'Accuracy and quality (Controller)',
                'description' => 'PII controllers shall ensure PII is accurate, complete and kept up-to-date.',
                'category' => 'Data Quality',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.3'],
                    'asset_types' => ['data'],
                ],
            ],
            [
                'id' => '27701-A.7.3.3',
                'title' => 'Specify obligations in contracts (Controller)',
                'description' => 'PII controllers shall specify privacy obligations when entering contracts with PII processors.',
                'category' => 'Contracts',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19', '5.20'],
                ],
            ],
            [
                'id' => '27701-A.7.3.4',
                'title' => 'Records related to processing PII (Controller)',
                'description' => 'PII controllers shall maintain records of PII processing activities.',
                'category' => 'Records Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => '27701-A.7.3.5',
                'title' => 'Provide PII principals with access to their PII (Controller)',
                'description' => 'PII controllers shall provide PII principals with access to their PII upon request.',
                'category' => 'PII Principal Rights',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                ],
            ],
            [
                'id' => '27701-A.7.3.6',
                'title' => 'Correction and erasure (Controller)',
                'description' => 'PII controllers shall provide mechanisms for correction and erasure of PII.',
                'category' => 'PII Principal Rights',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34', '8.10'],
                ],
            ],
            [
                'id' => '27701-A.7.3.9',
                'title' => 'Temporary file management (Controller)',
                'description' => 'PII controllers shall manage temporary files containing PII.',
                'category' => 'Data Management',
                'priority' => 'medium',
                'data_source_mapping' => [
                    'iso_controls' => ['8.10'],
                    'asset_types' => ['data'],
                ],
            ],
            [
                'id' => '27701-A.7.4.1',
                'title' => 'Restrict identification of PII principals (Controller)',
                'description' => 'PII controllers shall limit identification of PII principals to what is necessary.',
                'category' => 'Pseudonymization',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.11'],
                ],
            ],
            [
                'id' => '27701-A.7.4.2',
                'title' => 'De-identification and deletion at the end of processing (Controller)',
                'description' => 'PII controllers shall de-identify or delete PII when it is no longer needed.',
                'category' => 'Data Retention',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.10'],
                    'asset_types' => ['data'],
                ],
            ],
            [
                'id' => '27701-A.7.4.3',
                'title' => 'Automated decision making (Controller)',
                'description' => 'PII controllers shall ensure automated decision making is fair, transparent and accountable.',
                'category' => 'Automated Processing',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                ],
            ],
            [
                'id' => '27701-A.7.4.4',
                'title' => 'Data portability (Controller)',
                'description' => 'PII controllers shall provide PII in a structured, commonly used and machine-readable format.',
                'category' => 'PII Principal Rights',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                ],
            ],
            [
                'id' => '27701-A.7.5.1',
                'title' => 'Notification of PII breach (Controller)',
                'description' => 'PII controllers shall notify authorities and PII principals of PII breaches.',
                'category' => 'Breach Notification',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.26'],
                    'incident_management' => true,
                ],
            ],

            // Annex B: Additional ISO/IEC 27002 guidance for PII processors
            [
                'id' => '27701-B.8.2.1',
                'title' => 'Customer agreements and responsibilities (Processor)',
                'description' => 'PII processors shall establish agreements clearly defining customer responsibilities.',
                'category' => 'PII Processor Obligations',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19', '5.20'],
                ],
            ],
            [
                'id' => '27701-B.8.2.2',
                'title' => 'Process only on instructions (Processor)',
                'description' => 'PII processors shall process PII only on documented instructions from the PII controller.',
                'category' => 'PII Processor Obligations',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19'],
                ],
            ],
            [
                'id' => '27701-B.8.2.3',
                'title' => 'Return, transfer or disposal of PII (Processor)',
                'description' => 'PII processors shall return, transfer or dispose of PII as instructed by the controller.',
                'category' => 'PII Processor Obligations',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.10'],
                    'asset_types' => ['data'],
                ],
            ],
            [
                'id' => '27701-B.8.3.1',
                'title' => 'Subcontractor relationships (Processor)',
                'description' => 'PII processors shall only engage sub-processors with prior authorization.',
                'category' => 'Subcontracting',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19', '5.22'],
                ],
            ],
            [
                'id' => '27701-B.8.4.1',
                'title' => 'Provide assistance to customer (Processor)',
                'description' => 'PII processors shall assist controllers in fulfilling their obligations.',
                'category' => 'PII Processor Obligations',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19'],
                ],
            ],
            [
                'id' => '27701-B.8.4.2',
                'title' => 'Notification of PII breach to customer (Processor)',
                'description' => 'PII processors shall notify controllers of PII breaches without undue delay.',
                'category' => 'Breach Notification',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.26'],
                    'incident_management' => true,
                ],
            ],
            [
                'id' => '27701-B.8.5.1',
                'title' => 'Review for changes impacting customer (Processor)',
                'description' => 'PII processors shall review changes that could affect customer PII processing.',
                'category' => 'Change Management',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.32'],
                ],
            ],
            [
                'id' => '27701-B.8.5.2',
                'title' => 'Align with customer PII policies (Processor)',
                'description' => 'PII processors shall ensure their processing aligns with customer policies.',
                'category' => 'PII Processor Obligations',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19'],
                ],
            ],
            [
                'id' => '27701-B.8.5.3',
                'title' => 'Records related to processing PII (Processor)',
                'description' => 'PII processors shall maintain records of PII processing activities on behalf of controllers.',
                'category' => 'Records Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19'],
                    'audit_evidence' => true,
                ],
            ],

            // Additional PII Controller Controls (Annex A)
            [
                'id' => '27701-A.7.2.1',
                'title' => 'Conditions for Collection and Processing (Controller)',
                'description' => 'PII controllers shall identify and document lawful basis for PII processing.',
                'category' => 'PII Controller - Legal Basis',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                    'gdpr_article' => ['6'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => '27701-A.7.2.2',
                'title' => 'Purpose Specification and Limitation (Controller)',
                'description' => 'PII controllers shall specify purposes for processing and limit processing to those purposes.',
                'category' => 'PII Controller - Purpose',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                    'gdpr_article' => ['5.1.b'],
                ],
            ],
            [
                'id' => '27701-A.7.2.3',
                'title' => 'Accuracy and Quality (Controller)',
                'description' => 'PII controllers shall ensure PII is accurate, complete, up-to-date and relevant.',
                'category' => 'PII Controller - Data Quality',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                    'gdpr_article' => ['5.1.d'],
                ],
            ],
            [
                'id' => '27701-A.7.2.4',
                'title' => 'PII Minimization (Controller)',
                'description' => 'PII controllers shall limit PII collection to what is adequate, relevant and necessary.',
                'category' => 'PII Controller - Minimization',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                    'gdpr_article' => ['5.1.c'],
                ],
            ],
            [
                'id' => '27701-A.7.2.5',
                'title' => 'Retention Period (Controller)',
                'description' => 'PII controllers shall define and implement retention periods for PII.',
                'category' => 'PII Controller - Retention',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                    'gdpr_article' => ['5.1.e'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => '27701-A.7.2.6',
                'title' => 'Disposal of PII (Controller)',
                'description' => 'PII controllers shall dispose of PII securely when no longer needed.',
                'category' => 'PII Controller - Disposal',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.10'],
                    'gdpr_article' => ['5.1.e'],
                ],
            ],
            [
                'id' => '27701-A.7.3.1',
                'title' => 'Information Provided to PII Principals (Controller)',
                'description' => 'PII controllers shall provide clear information about PII processing to data subjects.',
                'category' => 'PII Controller - Transparency',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                    'gdpr_article' => ['13', '14'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => '27701-A.7.3.2',
                'title' => 'Privacy Notices (Controller)',
                'description' => 'PII controllers shall provide comprehensive privacy notices at or before collection.',
                'category' => 'PII Controller - Transparency',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                    'gdpr_article' => ['13', '14'],
                ],
            ],
            [
                'id' => '27701-A.7.3.3',
                'title' => 'Obligation to Inform (Controller)',
                'description' => 'PII controllers shall inform data subjects of security breaches affecting their PII.',
                'category' => 'PII Controller - Breach Notification',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.26'],
                    'gdpr_article' => ['34'],
                    'incident_management' => true,
                ],
            ],
            [
                'id' => '27701-A.7.3.4',
                'title' => 'Consent Management (Controller)',
                'description' => 'PII controllers shall obtain, manage and document consent where required as lawful basis.',
                'category' => 'PII Controller - Consent',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                    'gdpr_article' => ['6.1.a', '7'],
                ],
            ],
            [
                'id' => '27701-A.7.4.1',
                'title' => 'Right of Access (Controller)',
                'description' => 'PII controllers shall enable data subjects to access their PII.',
                'category' => 'PII Controller - Data Subject Rights',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                    'gdpr_article' => ['15'],
                ],
            ],
            [
                'id' => '27701-A.7.4.2',
                'title' => 'Right to Rectification (Controller)',
                'description' => 'PII controllers shall enable data subjects to correct inaccurate PII.',
                'category' => 'PII Controller - Data Subject Rights',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                    'gdpr_article' => ['16'],
                ],
            ],
            [
                'id' => '27701-A.7.4.3',
                'title' => 'Right to Erasure (Controller)',
                'description' => 'PII controllers shall implement right to erasure (right to be forgotten).',
                'category' => 'PII Controller - Data Subject Rights',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.10'],
                    'gdpr_article' => ['17'],
                ],
            ],
            [
                'id' => '27701-A.7.4.4',
                'title' => 'Right to Restriction of Processing (Controller)',
                'description' => 'PII controllers shall enable data subjects to restrict processing of their PII.',
                'category' => 'PII Controller - Data Subject Rights',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                    'gdpr_article' => ['18'],
                ],
            ],
            [
                'id' => '27701-A.7.4.5',
                'title' => 'Right to Data Portability (Controller)',
                'description' => 'PII controllers shall enable data subjects to receive PII in structured, machine-readable format.',
                'category' => 'PII Controller - Data Subject Rights',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                    'gdpr_article' => ['20'],
                ],
            ],
            [
                'id' => '27701-A.7.4.6',
                'title' => 'Right to Object (Controller)',
                'description' => 'PII controllers shall enable data subjects to object to processing.',
                'category' => 'PII Controller - Data Subject Rights',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                    'gdpr_article' => ['21'],
                ],
            ],
            [
                'id' => '27701-A.7.4.7',
                'title' => 'Automated Decision Making (Controller)',
                'description' => 'PII controllers shall implement safeguards for automated decision-making including profiling.',
                'category' => 'PII Controller - Automated Decisions',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                    'gdpr_article' => ['22'],
                ],
            ],
            [
                'id' => '27701-A.7.5.1',
                'title' => 'Data Protection by Design (Controller)',
                'description' => 'PII controllers shall implement data protection by design principles.',
                'category' => 'PII Controller - Privacy by Design',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                    'gdpr_article' => ['25.1'],
                ],
            ],
            [
                'id' => '27701-A.7.5.2',
                'title' => 'Data Protection by Default (Controller)',
                'description' => 'PII controllers shall implement data protection by default principles.',
                'category' => 'PII Controller - Privacy by Default',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                    'gdpr_article' => ['25.2'],
                ],
            ],
            [
                'id' => '27701-A.7.5.3',
                'title' => 'Privacy Impact Assessment (Controller)',
                'description' => 'PII controllers shall conduct Privacy Impact Assessments (PIA/DPIA) for high-risk processing.',
                'category' => 'PII Controller - Risk Assessment',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.7', '5.34'],
                    'gdpr_article' => ['35'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => '27701-A.8.2.1',
                'title' => 'Data Protection Officer Appointment (Controller)',
                'description' => 'PII controllers shall appoint a Data Protection Officer where required by law.',
                'category' => 'PII Controller - DPO',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.3'],
                    'gdpr_article' => ['37'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => '27701-A.8.2.2',
                'title' => 'Contracts with PII Processors (Controller)',
                'description' => 'PII controllers shall ensure contracts with processors meet regulatory requirements.',
                'category' => 'PII Controller - Processor Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19', '5.20'],
                    'gdpr_article' => ['28'],
                ],
            ],
            [
                'id' => '27701-A.8.3.1',
                'title' => 'Records of Processing Activities (Controller)',
                'description' => 'PII controllers shall maintain records of all processing activities.',
                'category' => 'PII Controller - Documentation',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                    'gdpr_article' => ['30'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => '27701-A.8.4.1',
                'title' => 'International Data Transfers (Controller)',
                'description' => 'PII controllers shall ensure adequate safeguards for international data transfers.',
                'category' => 'PII Controller - Data Transfers',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                    'gdpr_article' => ['44', '45', '46'],
                ],
            ],
            [
                'id' => '27701-A.8.4.2',
                'title' => 'Standard Contractual Clauses (Controller)',
                'description' => 'PII controllers shall use standard contractual clauses or equivalent for international transfers.',
                'category' => 'PII Controller - Data Transfers',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                    'gdpr_article' => ['46.2.c'],
                ],
            ],

            // Additional PII Processor Controls
            [
                'id' => '27701-B.7.2.1',
                'title' => 'Security of PII Processing (Processor)',
                'description' => 'PII processors shall implement appropriate technical and organizational security measures.',
                'category' => 'PII Processor - Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1', '8.1'],
                    'gdpr_article' => ['32'],
                ],
            ],
            [
                'id' => '27701-B.7.2.2',
                'title' => 'Confidentiality of PII (Processor)',
                'description' => 'PII processors shall ensure staff processing PII are bound by confidentiality.',
                'category' => 'PII Processor - Confidentiality',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['6.2'],
                    'gdpr_article' => ['28.3.b'],
                ],
            ],
            [
                'id' => '27701-B.7.3.1',
                'title' => 'Processor Training (Processor)',
                'description' => 'PII processors shall provide privacy and security training to personnel.',
                'category' => 'PII Processor - Training',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['6.3'],
                    'training_required' => true,
                ],
            ],
            [
                'id' => '27701-B.8.1.1',
                'title' => 'Demonstrate Compliance (Processor)',
                'description' => 'PII processors shall demonstrate compliance with processing obligations to controllers.',
                'category' => 'PII Processor - Compliance',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19'],
                    'gdpr_article' => ['28.3.h'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => '27701-B.8.1.2',
                'title' => 'Audit Rights for Controllers (Processor)',
                'description' => 'PII processors shall facilitate audits and inspections by controllers.',
                'category' => 'PII Processor - Audit',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19', '5.22'],
                    'gdpr_article' => ['28.3.h'],
                ],
            ],

            // GDPR-specific Requirements
            [
                'id' => '27701-GDPR-1',
                'title' => 'Supervisory Authority Cooperation',
                'description' => 'Organizations shall cooperate with supervisory authorities and respond to inquiries.',
                'category' => 'GDPR - Compliance',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.31'],
                    'gdpr_article' => ['31'],
                ],
            ],
            [
                'id' => '27701-GDPR-2',
                'title' => 'Breach Notification to Authorities',
                'description' => 'Organizations shall notify supervisory authorities of personal data breaches within 72 hours.',
                'category' => 'GDPR - Breach Notification',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.26'],
                    'gdpr_article' => ['33'],
                    'incident_management' => true,
                ],
            ],
            [
                'id' => '27701-GDPR-3',
                'title' => 'Joint Controllers Agreement',
                'description' => 'Joint controllers shall establish agreements defining responsibilities.',
                'category' => 'GDPR - Joint Controllers',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1', '5.3'],
                    'gdpr_article' => ['26'],
                ],
            ],
            [
                'id' => '27701-GDPR-4',
                'title' => 'Children PII Protection',
                'description' => 'Organizations shall implement enhanced protections for processing children PII.',
                'category' => 'GDPR - Special Categories',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                    'gdpr_article' => ['8'],
                ],
            ],
            [
                'id' => '27701-GDPR-5',
                'title' => 'Special Categories of PII',
                'description' => 'Organizations shall implement additional safeguards for special categories of personal data.',
                'category' => 'GDPR - Special Categories',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34', '8.24'],
                    'gdpr_article' => ['9'],
                ],
            ],
            [
                'id' => '27701-GDPR-6',
                'title' => 'Pseudonymization and Anonymization',
                'description' => 'Organizations should use pseudonymization and anonymization techniques where appropriate.',
                'category' => 'GDPR - Technical Measures',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.11'],
                    'gdpr_article' => ['25', '32'],
                ],
            ],
            [
                'id' => '27701-GDPR-7',
                'title' => 'Certification and Codes of Conduct',
                'description' => 'Organizations may use approved certifications and codes of conduct to demonstrate compliance.',
                'category' => 'GDPR - Compliance',
                'priority' => 'medium',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1'],
                    'gdpr_article' => ['40', '42', '43'],
                ],
            ],
        ];
    }
}
