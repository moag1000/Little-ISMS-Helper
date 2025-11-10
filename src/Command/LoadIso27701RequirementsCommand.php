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
        ];
    }
}
