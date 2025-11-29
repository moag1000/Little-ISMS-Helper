<?php

namespace App\Command;

use DateTimeImmutable;
use Exception;
use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:load-iso27701v2025-requirements',
    description: 'Load ISO 27701:2025 Privacy Information Management System - Standalone privacy standard with AI governance'
)]
class LoadIso27701v2025RequirementsCommand
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function __invoke(SymfonyStyle $symfonyStyle): int
    {
        // Create or get ISO 27701:2025 framework
        $framework = $this->entityManager->getRepository(ComplianceFramework::class)
            ->findOneBy(['code' => 'ISO27701_2025']);
        if (!$framework instanceof ComplianceFramework) {
            $framework = new ComplianceFramework();
            $framework->setCode('ISO27701_2025')
                ->setName('ISO/IEC 27701:2025 - Privacy Information Management System (PIMS)')
                ->setDescription('Standalone privacy management standard aligned with ISO 27001:2022/27002:2022, enhanced for AI and digital ecosystems')
                ->setVersion('2025')
                ->setApplicableIndustry('all_sectors')
                ->setRegulatoryBody('ISO/IEC')
                ->setMandatory(false)
                ->setScopeDescription('Provides comprehensive guidance for establishing, implementing, maintaining and improving a Privacy Information Management System - can be used standalone or integrated with ISO 27001')
                ->setActive(true);

            $this->entityManager->persist($framework);
        } else {
            // Framework exists - check if requirements are already loaded
            $existingRequirements = $this->entityManager
                ->getRepository(ComplianceRequirement::class)
                ->findBy(['complianceFramework' => $framework]);

            if ($existingRequirements !== []) {
                $symfonyStyle->warning(sprintf(
                    'Framework ISO 27701:2025 already has %d requirements loaded. Skipping to avoid duplicates.',
                    count($existingRequirements)
                ));
                return Command::SUCCESS;
            }

            // Framework exists but has no requirements - update timestamp
            $framework->setUpdatedAt(new DateTimeImmutable());
            $this->entityManager->persist($framework);
        }
        try {
            $this->entityManager->beginTransaction();

            $requirements = $this->getIso27701v2025Requirements();

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

            $symfonyStyle->success(sprintf('Successfully loaded %d ISO 27701:2025 requirements', count($requirements)));
        } catch (Exception $e) {
            $this->entityManager->rollback();
            $symfonyStyle->error('Failed to load ISO 27701:2025 requirements: ' . $e->getMessage());
            return Command::FAILURE;
        }
        return Command::SUCCESS;
    }

    private function getIso27701v2025Requirements(): array
    {
        return [
            // Section 5: PIMS-specific requirements related to ISO/IEC 27001
            [
                'id' => '27701:2025-5.2.1',
                'title' => 'Understanding the organization and its context (Privacy)',
                'description' => 'The organization shall determine external and internal issues relevant to its purpose and that affect its ability to achieve the intended outcomes of its PIMS, including digital ecosystem considerations.',
                'category' => 'PIMS Context',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1', '5.2'],
                    'iso27701_2019_ref' => '27701-5.2.1',
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => '27701:2025-5.2.2',
                'title' => 'Understanding the needs and expectations of interested parties (Privacy)',
                'description' => 'The organization shall determine interested parties relevant to the PIMS and their privacy requirements, including data subjects in digital ecosystems.',
                'category' => 'PIMS Context',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.2'],
                    'iso27701_2019_ref' => '27701-5.2.2',
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => '27701:2025-5.3',
                'title' => 'Determining the scope of the PIMS',
                'description' => 'The organization shall determine the boundaries and applicability of the PIMS to establish its scope, considering PII processing activities across digital platforms.',
                'category' => 'PIMS Scope',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1'],
                    'iso27701_2019_ref' => '27701-5.3',
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => '27701:2025-5.4.1',
                'title' => 'Privacy Information Management System',
                'description' => 'The organization shall establish, implement, maintain and continually improve a PIMS in accordance with this standard - can be standalone or integrated with ISMS.',
                'category' => 'PIMS Implementation',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1'],
                    'iso27701_2019_ref' => '27701-5.4.1',
                    'audit_evidence' => true,
                ],
            ],

            // Section 6: Planning for the PIMS
            [
                'id' => '27701:2025-6.1.1',
                'title' => 'Actions to address privacy risks and opportunities',
                'description' => 'When planning for the PIMS, the organization shall consider privacy-related risks and opportunities, including those from emerging technologies and AI systems.',
                'category' => 'PIMS Planning',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.7', '8.2'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => '27701:2025-6.1.2',
                'title' => 'PII protection impact assessment (PPIA)',
                'description' => 'The organization shall establish and maintain a process for conducting PII protection impact assessments, including for AI and automated decision-making systems.',
                'category' => 'Privacy Impact Assessment',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.7'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => '27701:2025-6.2',
                'title' => 'Privacy objectives and planning to achieve them',
                'description' => 'The organization shall establish privacy objectives at relevant functions and levels, including specific objectives for AI governance.',
                'category' => 'PIMS Planning',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.2'],
                    'audit_evidence' => true,
                ],
            ],

            // Section 7: Support for the PIMS
            [
                'id' => '27701:2025-7.2.2',
                'title' => 'Competence (Privacy-specific)',
                'description' => 'The organization shall ensure persons with PII processing responsibilities have appropriate competence, including understanding of AI and automated processing implications.',
                'category' => 'PIMS Support',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['6.3'],
                ],
            ],
            [
                'id' => '27701:2025-7.3',
                'title' => 'Awareness (Privacy)',
                'description' => 'The organization shall ensure persons doing work under its control are aware of privacy policies, their contribution to the PIMS, and privacy implications of AI systems.',
                'category' => 'Awareness',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['6.3'],
                ],
            ],
            [
                'id' => '27701:2025-7.4.1',
                'title' => 'Communication (Privacy)',
                'description' => 'The organization shall determine the need for internal and external communications relevant to the PIMS including privacy notices for AI-driven processing.',
                'category' => 'Communication',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => '27701:2025-7.5.1',
                'title' => 'Documented information (Privacy-specific)',
                'description' => 'The PIMS shall include documented information for privacy-specific requirements including records of PII processing and AI system documentation.',
                'category' => 'Documentation',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                    'audit_evidence' => true,
                ],
            ],

            // Section 8: Operation of the PIMS
            [
                'id' => '27701:2025-8.2',
                'title' => 'PII protection impact assessment execution',
                'description' => 'The organization shall carry out PII protection impact assessments for PII processing that could lead to high privacy risks, mandatory for AI and automated decision-making.',
                'category' => 'Operations',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.7'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => '27701:2025-8.3',
                'title' => 'Working with PII processors',
                'description' => 'The organization shall ensure PII processors provide sufficient guarantees to implement appropriate technical and organizational measures, including for cloud and AI services.',
                'category' => 'Third Party Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19', '5.20', '5.21'],
                ],
            ],
            [
                'id' => '27701:2025-8.4',
                'title' => 'Third country and international transfers',
                'description' => 'The organization shall identify where PII is transferred to other jurisdictions and ensure appropriate safeguards including for cloud services and global AI platforms.',
                'category' => 'Data Transfers',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.14'],
                ],
            ],

            // Section 9: Performance evaluation
            [
                'id' => '27701:2025-9.1',
                'title' => 'Monitoring, measurement, analysis and evaluation (Privacy)',
                'description' => 'The organization shall evaluate privacy performance and the effectiveness of the PIMS, including monitoring of AI systems.',
                'category' => 'Performance Evaluation',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => '27701:2025-9.2',
                'title' => 'Internal audit (PIMS)',
                'description' => 'The organization shall conduct internal audits at planned intervals for the PIMS, including audit of AI governance controls.',
                'category' => 'Audit',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => '27701:2025-9.3',
                'title' => 'Management review (PIMS)',
                'description' => 'Top management shall review the PIMS at planned intervals, including review of AI system privacy implications.',
                'category' => 'Management Review',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1'],
                    'audit_evidence' => true,
                ],
            ],

            // Section 10: Improvement
            [
                'id' => '27701:2025-10.1',
                'title' => 'Nonconformity and corrective action (Privacy)',
                'description' => 'When a privacy-related nonconformity occurs, the organization shall react and take corrective action, including for AI system failures.',
                'category' => 'Improvement',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.28'],
                    'incident_management' => true,
                ],
            ],
            [
                'id' => '27701:2025-10.2',
                'title' => 'Continual improvement (PIMS)',
                'description' => 'The organization shall continually improve the suitability, adequacy and effectiveness of the PIMS.',
                'category' => 'Improvement',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1'],
                    'audit_evidence' => true,
                ],
            ],

            // Annex A: Additional guidance for PII controllers (2025 updates)
            [
                'id' => '27701:2025-A.7.2.1',
                'title' => 'Identify and document purpose (Controller)',
                'description' => 'PII controllers shall identify and document specific, explicit and legitimate purposes for PII processing, including clear articulation of AI and automated processing purposes.',
                'category' => 'PII Controller Obligations',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                    'asset_types' => ['data'],
                ],
            ],
            [
                'id' => '27701:2025-A.7.2.2',
                'title' => 'Identify lawful basis (Controller)',
                'description' => 'PII controllers shall identify and document the lawful basis for PII processing, particularly for AI training and inference.',
                'category' => 'PII Controller Obligations',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => '27701:2025-A.7.2.3',
                'title' => 'Determine when and how consent is obtained (Controller)',
                'description' => 'PII controllers shall determine circumstances when consent is required and establish procedures for obtaining it, with special considerations for AI-driven processing.',
                'category' => 'Consent Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                ],
            ],
            [
                'id' => '27701:2025-A.7.2.4',
                'title' => 'Provide privacy notices (Controller)',
                'description' => 'PII controllers shall provide PII principals with clear and easily accessible privacy notices, including information about AI and automated decision-making.',
                'category' => 'Transparency',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                ],
            ],
            [
                'id' => '27701:2025-A.7.2.5',
                'title' => 'Provide mechanism to modify or withdraw consent (Controller)',
                'description' => 'PII controllers shall provide mechanisms for PII principals to modify or withdraw consent, including for AI model training.',
                'category' => 'Consent Management',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                ],
            ],
            [
                'id' => '27701:2025-A.7.3.1',
                'title' => 'Limit collection (Controller)',
                'description' => 'PII controllers shall limit the collection of PII to what is adequate, relevant and necessary, applying data minimization principles to AI training datasets.',
                'category' => 'Data Minimization',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                    'asset_types' => ['data'],
                ],
            ],
            [
                'id' => '27701:2025-A.7.3.2',
                'title' => 'Accuracy and quality (Controller)',
                'description' => 'PII controllers shall ensure PII is accurate, complete and kept up-to-date, including data used for AI model training and inference.',
                'category' => 'Data Quality',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.3'],
                    'asset_types' => ['data'],
                ],
            ],
            [
                'id' => '27701:2025-A.7.3.3',
                'title' => 'Specify obligations in contracts (Controller)',
                'description' => 'PII controllers shall specify privacy obligations when entering contracts with PII processors, including cloud service providers and AI platform vendors.',
                'category' => 'Contracts',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19', '5.20'],
                ],
            ],
            [
                'id' => '27701:2025-A.7.3.4',
                'title' => 'Records related to processing PII (Controller)',
                'description' => 'PII controllers shall maintain comprehensive records of PII processing activities, including AI model lineage and training data provenance.',
                'category' => 'Records Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => '27701:2025-A.7.3.5',
                'title' => 'Provide PII principals with access to their PII (Controller)',
                'description' => 'PII controllers shall provide PII principals with access to their PII upon request, including data used in AI systems.',
                'category' => 'PII Principal Rights',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                ],
            ],
            [
                'id' => '27701:2025-A.7.3.6',
                'title' => 'Correction and erasure (Controller)',
                'description' => 'PII controllers shall provide mechanisms for correction and erasure of PII, including the right to be forgotten from AI training datasets.',
                'category' => 'PII Principal Rights',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34', '8.10'],
                ],
            ],
            [
                'id' => '27701:2025-A.7.3.7',
                'title' => 'PII principals accessing their own PII (Controller)',
                'description' => 'PII controllers shall provide PII principals with the capability to review their PII and challenge its accuracy and completeness.',
                'category' => 'PII Principal Rights',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                ],
            ],
            [
                'id' => '27701:2025-A.7.3.8',
                'title' => 'Provide information to PII principals on request (Controller)',
                'description' => 'PII controllers shall provide information about PII processing to PII principals upon request, including AI processing details.',
                'category' => 'Transparency',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                ],
            ],
            [
                'id' => '27701:2025-A.7.3.9',
                'title' => 'Temporary file management (Controller)',
                'description' => 'PII controllers shall manage temporary files containing PII, including cached data in AI systems.',
                'category' => 'Data Management',
                'priority' => 'medium',
                'data_source_mapping' => [
                    'iso_controls' => ['8.10'],
                    'asset_types' => ['data'],
                ],
            ],
            [
                'id' => '27701:2025-A.7.3.10',
                'title' => 'Disposal of PII (Controller)',
                'description' => 'PII controllers shall dispose of PII in a secure manner when no longer required, including secure deletion from backups and AI models.',
                'category' => 'Data Disposal',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.10'],
                    'asset_types' => ['data'],
                ],
            ],
            [
                'id' => '27701:2025-A.7.4.1',
                'title' => 'Restrict identification of PII principals (Controller)',
                'description' => 'PII controllers shall limit identification of PII principals to what is necessary, implementing pseudonymization and anonymization for AI datasets.',
                'category' => 'Pseudonymization',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.11'],
                ],
            ],
            [
                'id' => '27701:2025-A.7.4.2',
                'title' => 'De-identification and deletion at the end of processing (Controller)',
                'description' => 'PII controllers shall de-identify or delete PII when it is no longer needed, including removal from AI models where technically feasible.',
                'category' => 'Data Retention',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.10'],
                    'asset_types' => ['data'],
                ],
            ],
            [
                'id' => '27701:2025-A.7.4.3',
                'title' => 'Automated decision making and AI systems (Controller)',
                'description' => 'PII controllers shall ensure automated decision making and AI systems are fair, transparent, explainable and accountable, with human oversight for high-risk decisions.',
                'category' => 'AI Governance',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => '27701:2025-A.7.4.4',
                'title' => 'Data portability (Controller)',
                'description' => 'PII controllers shall provide PII in a structured, commonly used and machine-readable format, including data used in AI personalization.',
                'category' => 'PII Principal Rights',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                ],
            ],
            [
                'id' => '27701:2025-A.7.4.5',
                'title' => 'Restriction of processing (Controller)',
                'description' => 'PII controllers shall provide mechanisms for PII principals to restrict processing of their PII in certain circumstances.',
                'category' => 'PII Principal Rights',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                ],
            ],
            [
                'id' => '27701:2025-A.7.4.6',
                'title' => 'Object to processing (Controller)',
                'description' => 'PII controllers shall allow PII principals to object to processing of their PII, including profiling and automated decision-making.',
                'category' => 'PII Principal Rights',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                ],
            ],
            [
                'id' => '27701:2025-A.7.4.7',
                'title' => 'Retention and deletion of PII (Controller)',
                'description' => 'PII controllers shall define and implement retention periods and deletion schedules for PII, including AI training data.',
                'category' => 'Data Retention',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.10'],
                    'asset_types' => ['data'],
                ],
            ],
            [
                'id' => '27701:2025-A.7.4.8',
                'title' => 'PII transmission controls (Controller)',
                'description' => 'PII controllers shall implement controls for transmission of PII, ensuring confidentiality and integrity during transfer.',
                'category' => 'Data Transfer',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.14', '8.24'],
                ],
            ],
            [
                'id' => '27701:2025-A.7.5.1',
                'title' => 'Notification of PII breach (Controller)',
                'description' => 'PII controllers shall notify authorities and PII principals of PII breaches, including breaches involving AI systems or training data.',
                'category' => 'Breach Notification',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.26'],
                    'incident_management' => true,
                ],
            ],
            [
                'id' => '27701:2025-A.7.5.2',
                'title' => 'PII breach response (Controller)',
                'description' => 'PII controllers shall respond to PII breaches in accordance with documented procedures, including containment, investigation, and remediation.',
                'category' => 'Incident Response',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.26', '5.27'],
                    'incident_management' => true,
                ],
            ],
            [
                'id' => '27701:2025-A.7.5.3',
                'title' => 'Privacy complaints handling (Controller)',
                'description' => 'PII controllers shall establish and maintain a process for handling privacy complaints from PII principals.',
                'category' => 'Complaints Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                    'audit_evidence' => true,
                ],
            ],

            // NEW 2025: AI-Specific Requirements
            [
                'id' => '27701:2025-A.7.6.1',
                'title' => 'AI model transparency and explainability (Controller)',
                'description' => 'PII controllers shall ensure AI models processing PII are transparent and provide meaningful explanations of automated decisions affecting individuals.',
                'category' => 'AI Governance',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => '27701:2025-A.7.6.2',
                'title' => 'AI bias detection and mitigation (Controller)',
                'description' => 'PII controllers shall implement processes to detect, assess and mitigate bias in AI systems that process PII.',
                'category' => 'AI Governance',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34', '8.8'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => '27701:2025-A.7.6.3',
                'title' => 'AI training data governance (Controller)',
                'description' => 'PII controllers shall establish governance processes for AI training data including data quality, lineage, retention and deletion policies.',
                'category' => 'AI Governance',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34', '8.3'],
                    'asset_types' => ['data'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => '27701:2025-A.7.6.4',
                'title' => 'AI model versioning and audit trails (Controller)',
                'description' => 'PII controllers shall maintain version control and audit trails for AI models processing PII, documenting model changes and their privacy implications.',
                'category' => 'AI Governance',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.32'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => '27701:2025-A.7.6.5',
                'title' => 'Human oversight of AI decisions (Controller)',
                'description' => 'PII controllers shall implement appropriate human oversight for AI systems making significant decisions affecting individuals.',
                'category' => 'AI Governance',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                    'audit_evidence' => true,
                ],
            ],

            // Additional Controller Requirements - Access Control and Security
            [
                'id' => '27701:2025-A.7.8.1',
                'title' => 'Access controls for PII (Controller)',
                'description' => 'PII controllers shall implement appropriate access controls to limit access to PII based on business need and data classification.',
                'category' => 'Access Control',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.15', '5.18'],
                ],
            ],
            [
                'id' => '27701:2025-A.7.8.2',
                'title' => 'Encryption of PII (Controller)',
                'description' => 'PII controllers shall implement encryption for PII at rest and in transit where appropriate to the risk.',
                'category' => 'Cryptography',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                ],
            ],
            [
                'id' => '27701:2025-A.7.8.3',
                'title' => 'Logging of PII access (Controller)',
                'description' => 'PII controllers shall log access to and processing of PII to enable monitoring and investigation of privacy incidents.',
                'category' => 'Monitoring',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.15'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => '27701:2025-A.7.8.4',
                'title' => 'Testing and validation of PII systems (Controller)',
                'description' => 'PII controllers shall test and validate systems processing PII, including security testing and privacy controls testing.',
                'category' => 'Testing',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.29'],
                ],
            ],
            [
                'id' => '27701:2025-A.7.8.5',
                'title' => 'Backup of PII (Controller)',
                'description' => 'PII controllers shall implement backup procedures for PII while ensuring backup copies are subject to the same privacy controls.',
                'category' => 'Backup',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.13'],
                ],
            ],
            [
                'id' => '27701:2025-A.7.8.6',
                'title' => 'Physical security for PII storage (Controller)',
                'description' => 'PII controllers shall implement physical security controls for equipment and media storing PII.',
                'category' => 'Physical Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['7.1', '7.2', '7.8'],
                ],
            ],
            [
                'id' => '27701:2025-A.7.8.7',
                'title' => 'Protection against malware for PII systems (Controller)',
                'description' => 'PII controllers shall implement malware protection for systems processing PII to prevent data breaches.',
                'category' => 'Malware Protection',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.7'],
                ],
            ],
            [
                'id' => '27701:2025-A.7.8.8',
                'title' => 'Vulnerability management for PII systems (Controller)',
                'description' => 'PII controllers shall implement vulnerability management processes for systems processing PII.',
                'category' => 'Vulnerability Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.8'],
                ],
            ],
            [
                'id' => '27701:2025-A.7.8.9',
                'title' => 'Network security for PII transmission (Controller)',
                'description' => 'PII controllers shall implement network security controls to protect PII during transmission.',
                'category' => 'Network Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.20', '8.21', '8.22'],
                ],
            ],
            [
                'id' => '27701:2025-A.7.8.10',
                'title' => 'Configuration management for PII systems (Controller)',
                'description' => 'PII controllers shall implement configuration management for systems processing PII to maintain security baselines.',
                'category' => 'Configuration Management',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.9'],
                ],
            ],
            [
                'id' => '27701:2025-A.7.8.11',
                'title' => 'Secure development lifecycle for PII systems (Controller)',
                'description' => 'PII controllers shall apply secure development practices to systems and applications processing PII.',
                'category' => 'Secure Development',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.25', '8.27', '8.28'],
                ],
            ],
            [
                'id' => '27701:2025-A.7.8.12',
                'title' => 'Third-party service provider assessment (Controller)',
                'description' => 'PII controllers shall assess third-party service providers before engaging them to process PII.',
                'category' => 'Third Party Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19', '5.21'],
                ],
            ],
            [
                'id' => '27701:2025-A.7.8.13',
                'title' => 'Monitoring of third-party processing (Controller)',
                'description' => 'PII controllers shall monitor and audit third-party processing of PII to ensure compliance.',
                'category' => 'Third Party Management',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.22'],
                    'audit_evidence' => true,
                ],
            ],

            // NEW 2025: Digital Ecosystem Requirements
            [
                'id' => '27701:2025-A.7.7.1',
                'title' => 'Multi-party data ecosystem governance (Controller)',
                'description' => 'PII controllers participating in multi-party data ecosystems shall establish clear data governance agreements defining responsibilities.',
                'category' => 'Digital Ecosystems',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19', '5.20'],
                ],
            ],
            [
                'id' => '27701:2025-A.7.7.2',
                'title' => 'Privacy-enhancing technologies (Controller)',
                'description' => 'PII controllers shall evaluate and implement privacy-enhancing technologies (PETs) such as differential privacy, federated learning, and homomorphic encryption where appropriate.',
                'category' => 'Digital Ecosystems',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.11', '8.24'],
                ],
            ],
            [
                'id' => '27701:2025-A.7.7.3',
                'title' => 'Digital platform privacy controls (Controller)',
                'description' => 'PII controllers operating digital platforms shall implement privacy controls including privacy-by-design and privacy-by-default.',
                'category' => 'Digital Ecosystems',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                ],
            ],

            // Annex B: Additional guidance for PII processors (2025 updates)
            [
                'id' => '27701:2025-B.8.2.1',
                'title' => 'Customer agreements and responsibilities (Processor)',
                'description' => 'PII processors shall establish agreements clearly defining customer responsibilities, including for AI and cloud services.',
                'category' => 'PII Processor Obligations',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19', '5.20'],
                ],
            ],
            [
                'id' => '27701:2025-B.8.2.2',
                'title' => 'Process only on instructions (Processor)',
                'description' => 'PII processors shall process PII only on documented instructions from the PII controller, with clear boundaries for AI processing.',
                'category' => 'PII Processor Obligations',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19'],
                ],
            ],
            [
                'id' => '27701:2025-B.8.2.3',
                'title' => 'Return, transfer or disposal of PII (Processor)',
                'description' => 'PII processors shall return, transfer or dispose of PII as instructed by the controller, including deletion from AI models and backups.',
                'category' => 'PII Processor Obligations',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.10'],
                    'asset_types' => ['data'],
                ],
            ],
            [
                'id' => '27701:2025-B.8.2.4',
                'title' => 'Security of PII processing (Processor)',
                'description' => 'PII processors shall implement appropriate technical and organizational measures to ensure security of PII processing.',
                'category' => 'PII Processor Obligations',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.15', '8.1', '8.24'],
                ],
            ],
            [
                'id' => '27701:2025-B.8.2.5',
                'title' => 'PII processor personnel (Processor)',
                'description' => 'PII processors shall ensure personnel processing PII are subject to confidentiality obligations and have appropriate training.',
                'category' => 'PII Processor Obligations',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['6.1', '6.2', '6.3'],
                ],
            ],
            [
                'id' => '27701:2025-B.8.3.1',
                'title' => 'Subcontractor relationships (Processor)',
                'description' => 'PII processors shall only engage sub-processors with prior authorization, including cloud sub-processors and AI service providers.',
                'category' => 'Subcontracting',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19', '5.22'],
                ],
            ],
            [
                'id' => '27701:2025-B.8.3.2',
                'title' => 'Sub-processor obligations (Processor)',
                'description' => 'PII processors shall ensure sub-processors are subject to the same privacy obligations as the processor.',
                'category' => 'Subcontracting',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19', '5.20'],
                ],
            ],
            [
                'id' => '27701:2025-B.8.3.3',
                'title' => 'Change of sub-processors (Processor)',
                'description' => 'PII processors shall inform controllers of intended changes concerning addition or replacement of sub-processors.',
                'category' => 'Subcontracting',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.22', '5.23'],
                ],
            ],
            [
                'id' => '27701:2025-B.8.4.1',
                'title' => 'Provide assistance to customer (Processor)',
                'description' => 'PII processors shall assist controllers in fulfilling their obligations, including for data subject rights requests in AI contexts.',
                'category' => 'PII Processor Obligations',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19'],
                ],
            ],
            [
                'id' => '27701:2025-B.8.4.2',
                'title' => 'Notification of PII breach to customer (Processor)',
                'description' => 'PII processors shall notify controllers of PII breaches without undue delay, including breaches of AI systems.',
                'category' => 'Breach Notification',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.26'],
                    'incident_management' => true,
                ],
            ],
            [
                'id' => '27701:2025-B.8.4.3',
                'title' => 'Assistance with PPIA (Processor)',
                'description' => 'PII processors shall assist controllers in carrying out PII protection impact assessments, providing necessary information about processing.',
                'category' => 'PII Processor Obligations',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.7'],
                ],
            ],
            [
                'id' => '27701:2025-B.8.4.4',
                'title' => 'Make available information to demonstrate compliance (Processor)',
                'description' => 'PII processors shall make available information necessary to demonstrate compliance with processor obligations, including audit support.',
                'category' => 'PII Processor Obligations',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.35', '5.36'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => '27701:2025-B.8.5.1',
                'title' => 'Review for changes impacting customer (Processor)',
                'description' => 'PII processors shall review changes that could affect customer PII processing, including AI model updates and infrastructure changes.',
                'category' => 'Change Management',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.32'],
                ],
            ],
            [
                'id' => '27701:2025-B.8.5.2',
                'title' => 'Align with customer PII policies (Processor)',
                'description' => 'PII processors shall ensure their processing aligns with customer policies, including AI governance requirements.',
                'category' => 'PII Processor Obligations',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19'],
                ],
            ],
            [
                'id' => '27701:2025-B.8.5.3',
                'title' => 'Records related to processing PII (Processor)',
                'description' => 'PII processors shall maintain records of PII processing activities on behalf of controllers, including AI processing logs.',
                'category' => 'Records Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19'],
                    'audit_evidence' => true,
                ],
            ],

            [
                'id' => '27701:2025-B.8.5.4',
                'title' => 'PII de-identification and anonymization (Processor)',
                'description' => 'PII processors shall implement appropriate de-identification and anonymization techniques when instructed by controllers.',
                'category' => 'Data Protection',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.11'],
                ],
            ],
            [
                'id' => '27701:2025-B.8.5.5',
                'title' => 'Temporary files containing PII (Processor)',
                'description' => 'PII processors shall manage temporary files containing PII in accordance with controller requirements.',
                'category' => 'Data Management',
                'priority' => 'medium',
                'data_source_mapping' => [
                    'iso_controls' => ['8.10'],
                    'asset_types' => ['data'],
                ],
            ],
            [
                'id' => '27701:2025-B.8.5.6',
                'title' => 'Disposal of PII (Processor)',
                'description' => 'PII processors shall dispose of PII in a secure manner when instructed by controllers, including secure deletion from all systems.',
                'category' => 'Data Disposal',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.10', '7.14'],
                    'asset_types' => ['data'],
                ],
            ],

            // NEW 2025: Processor-specific AI and Cloud Requirements
            [
                'id' => '27701:2025-B.8.6.1',
                'title' => 'Cloud service privacy controls (Processor)',
                'description' => 'PII processors offering cloud services shall implement comprehensive privacy controls including data residency, encryption, and access controls.',
                'category' => 'Cloud & AI Services',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24', '8.9'],
                ],
            ],
            [
                'id' => '27701:2025-B.8.6.2',
                'title' => 'AI service transparency (Processor)',
                'description' => 'PII processors offering AI services shall provide transparency about AI models, processing methods, and data usage.',
                'category' => 'Cloud & AI Services',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                ],
            ],
            [
                'id' => '27701:2025-B.8.6.3',
                'title' => 'Data isolation in multi-tenant environments (Processor)',
                'description' => 'PII processors shall ensure effective data isolation in multi-tenant cloud and AI platforms.',
                'category' => 'Cloud & AI Services',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.20', '8.31'],
                ],
            ],
            [
                'id' => '27701:2025-B.8.6.4',
                'title' => 'Incident response for processor environments (Processor)',
                'description' => 'PII processors shall maintain incident response capabilities specific to PII breaches in their processing environments.',
                'category' => 'Incident Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.24', '5.25', '5.26'],
                    'incident_management' => true,
                ],
            ],
            [
                'id' => '27701:2025-B.8.6.5',
                'title' => 'Vulnerability management for processing infrastructure (Processor)',
                'description' => 'PII processors shall implement vulnerability management for infrastructure used to process customer PII.',
                'category' => 'Vulnerability Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.8'],
                ],
            ],
            [
                'id' => '27701:2025-B.8.6.6',
                'title' => 'Logging and monitoring of PII processing (Processor)',
                'description' => 'PII processors shall implement comprehensive logging and monitoring of PII processing activities.',
                'category' => 'Monitoring',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.15', '8.16'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => '27701:2025-B.8.6.7',
                'title' => 'Backup and recovery for customer PII (Processor)',
                'description' => 'PII processors shall implement backup and recovery procedures for customer PII according to agreed service levels.',
                'category' => 'Backup',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.13', '5.29'],
                ],
            ],
        ];
    }
}

