<?php

namespace App\Command;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:load-c5-2025-requirements',
    description: 'Load BSI C5:2025 Community Draft (Cloud Computing Compliance Criteria Catalogue) requirements with ISMS data mappings'
)]
class LoadC52025RequirementsCommand
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function __invoke(SymfonyStyle $symfonyStyle): int
    {
        // Create or get C5:2025 framework
        $framework = $this->entityManager->getRepository(ComplianceFramework::class)
            ->findOneBy(['code' => 'BSI-C5-2025']);
        if (!$framework instanceof ComplianceFramework) {
            $framework = new ComplianceFramework();
            $framework->setCode('BSI-C5-2025')
                ->setName('BSI C5:2025 Community Draft - Cloud Computing Compliance Criteria Catalogue')
                ->setDescription('German Federal Office for Information Security (BSI) criteria catalogue for secure cloud computing - 2025 Community Draft with new requirements for container management, supply chain security, post-quantum cryptography, and EUCS Substantial alignment')
                ->setVersion('2025 Community Draft')
                ->setApplicableIndustry('cloud_services')
                ->setRegulatoryBody('BSI - Bundesamt für Sicherheit in der Informationstechnik')
                ->setMandatory(false)
                ->setScopeDescription('Cloud service providers and cloud customers in Germany. Mandatory from January 1, 2027. Aligned with EUCS Substantial, ISO 27001:2022, NIS2, CSA CCM v4.')
                ->setActive(true);

            $this->entityManager->persist($framework);
        }
        $requirements = $this->getC52025Requirements();
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
        $symfonyStyle->success(sprintf('Successfully loaded %d BSI C5:2025 Community Draft requirements', count($requirements)));
        return Command::SUCCESS;
    }

    private function getC52025Requirements(): array
    {
        return [
            // NEW: Container Management (CNT) - C5:2025 specific
            [
                'id' => 'C5-2025-CNT-1',
                'title' => 'Container Image Security',
                'description' => 'Container images shall be scanned for vulnerabilities before deployment.',
                'category' => 'Container Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.8'],
                    'new_in_c5_2025' => true,
                    'asset_types' => ['cloud'],
                ],
            ],
            [
                'id' => 'C5-2025-CNT-2',
                'title' => 'Container Runtime Security',
                'description' => 'Container runtime environments shall be secured and monitored.',
                'category' => 'Container Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.16', '8.20'],
                    'new_in_c5_2025' => true,
                    'asset_types' => ['cloud'],
                ],
            ],
            [
                'id' => 'C5-2025-CNT-3',
                'title' => 'Container Orchestration Security',
                'description' => 'Container orchestration platforms (Kubernetes, etc.) shall be configured securely.',
                'category' => 'Container Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.9', '8.20'],
                    'new_in_c5_2025' => true,
                    'asset_types' => ['cloud'],
                ],
            ],
            [
                'id' => 'C5-2025-CNT-4',
                'title' => 'Container Registry Security',
                'description' => 'Container registries shall implement access controls and image signing.',
                'category' => 'Container Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.18', '8.24'],
                    'new_in_c5_2025' => true,
                    'asset_types' => ['cloud'],
                ],
            ],
            [
                'id' => 'C5-2025-CNT-5',
                'title' => 'Container Isolation',
                'description' => 'Containers shall be isolated from each other and from the host system.',
                'category' => 'Container Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.22'],
                    'new_in_c5_2025' => true,
                    'asset_types' => ['cloud'],
                ],
            ],
            [
                'id' => 'C5-2025-CNT-6',
                'title' => 'Container Network Policies',
                'description' => 'Network policies shall restrict container-to-container and container-to-external communications.',
                'category' => 'Container Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.20', '8.22'],
                    'new_in_c5_2025' => true,
                    'asset_types' => ['cloud'],
                ],
            ],

            // NEW: Supply Chain Security (SCS) - C5:2025 specific
            [
                'id' => 'C5-2025-SCS-1',
                'title' => 'Software Supply Chain Security',
                'description' => 'Software supply chains shall be secured with integrity verification and SBOM (Software Bill of Materials).',
                'category' => 'Supply Chain Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19', '8.28'],
                    'new_in_c5_2025' => true,
                ],
            ],
            [
                'id' => 'C5-2025-SCS-2',
                'title' => 'Third-Party Component Vulnerability Management',
                'description' => 'Third-party software components shall be inventoried and monitored for vulnerabilities.',
                'category' => 'Supply Chain Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19', '8.8'],
                    'new_in_c5_2025' => true,
                ],
            ],
            [
                'id' => 'C5-2025-SCS-3',
                'title' => 'Supplier Security Assessment',
                'description' => 'Suppliers shall be assessed for security practices before engagement and regularly thereafter.',
                'category' => 'Supply Chain Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19', '5.20'],
                    'new_in_c5_2025' => true,
                    'c5_2020_reference' => 'C5-SUP-1',
                ],
            ],
            [
                'id' => 'C5-2025-SCS-4',
                'title' => 'Secure Software Development Practices',
                'description' => 'Secure software development practices shall be implemented throughout the SDLC.',
                'category' => 'Supply Chain Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.25', '8.27', '8.28'],
                    'new_in_c5_2025' => true,
                ],
            ],
            [
                'id' => 'C5-2025-SCS-5',
                'title' => 'Code Signing and Verification',
                'description' => 'Software artifacts shall be digitally signed and verified before deployment.',
                'category' => 'Supply Chain Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                    'new_in_c5_2025' => true,
                ],
            ],
            [
                'id' => 'C5-2025-SCS-6',
                'title' => 'Dependency Management',
                'description' => 'Software dependencies shall be managed with version pinning and integrity checks.',
                'category' => 'Supply Chain Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.19'],
                    'new_in_c5_2025' => true,
                ],
            ],

            // NEW: Post-Quantum Cryptography (PQC) - C5:2025 specific
            [
                'id' => 'C5-2025-PQC-1',
                'title' => 'Post-Quantum Cryptography Roadmap',
                'description' => 'A roadmap for transitioning to post-quantum cryptographic algorithms shall be established.',
                'category' => 'Post-Quantum Cryptography',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                    'new_in_c5_2025' => true,
                ],
            ],
            [
                'id' => 'C5-2025-PQC-2',
                'title' => 'Cryptographic Agility',
                'description' => 'Systems shall be designed for cryptographic agility to enable algorithm transitions.',
                'category' => 'Post-Quantum Cryptography',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                    'new_in_c5_2025' => true,
                ],
            ],
            [
                'id' => 'C5-2025-PQC-3',
                'title' => 'Quantum-Resistant Algorithms',
                'description' => 'Evaluation and adoption of quantum-resistant cryptographic algorithms shall be planned.',
                'category' => 'Post-Quantum Cryptography',
                'priority' => 'medium',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                    'new_in_c5_2025' => true,
                ],
            ],
            [
                'id' => 'C5-2025-PQC-4',
                'title' => 'Long-Term Data Protection',
                'description' => 'Data requiring long-term confidentiality shall be protected against quantum computing threats.',
                'category' => 'Post-Quantum Cryptography',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.11', '8.24'],
                    'new_in_c5_2025' => true,
                    'asset_types' => ['data'],
                ],
            ],

            // NEW: Confidential Computing (CFC) - C5:2025 specific
            [
                'id' => 'C5-2025-CFC-1',
                'title' => 'Trusted Execution Environments',
                'description' => 'Trusted Execution Environments (TEE) shall be utilized for sensitive workloads where appropriate.',
                'category' => 'Confidential Computing',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                    'new_in_c5_2025' => true,
                    'asset_types' => ['cloud'],
                ],
            ],
            [
                'id' => 'C5-2025-CFC-2',
                'title' => 'Memory Encryption',
                'description' => 'Memory encryption shall be implemented for data in use where technically feasible.',
                'category' => 'Confidential Computing',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                    'new_in_c5_2025' => true,
                    'asset_types' => ['data'],
                ],
            ],
            [
                'id' => 'C5-2025-CFC-3',
                'title' => 'Secure Enclaves',
                'description' => 'Secure enclaves shall be used to protect sensitive data and computations.',
                'category' => 'Confidential Computing',
                'priority' => 'medium',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                    'new_in_c5_2025' => true,
                    'asset_types' => ['cloud'],
                ],
            ],
            [
                'id' => 'C5-2025-CFC-4',
                'title' => 'Attestation and Verification',
                'description' => 'Remote attestation shall verify the integrity of confidential computing environments.',
                'category' => 'Confidential Computing',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                    'new_in_c5_2025' => true,
                ],
            ],

            // NEW: Enhanced Client Separation (ECS) - C5:2025 specific
            [
                'id' => 'C5-2025-ECS-1',
                'title' => 'Multi-Tenant Isolation Assurance',
                'description' => 'Multi-tenant isolation shall be regularly tested and verified through independent assessments.',
                'category' => 'Enhanced Client Separation',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.11'],
                    'new_in_c5_2025' => true,
                    'c5_2020_reference' => 'C5-DSI-1',
                    'asset_types' => ['cloud'],
                ],
            ],
            [
                'id' => 'C5-2025-ECS-2',
                'title' => 'Dedicated Resources Option',
                'description' => 'Options for dedicated resources shall be available for customers with high security requirements.',
                'category' => 'Enhanced Client Separation',
                'priority' => 'medium',
                'data_source_mapping' => [
                    'iso_controls' => ['8.11'],
                    'new_in_c5_2025' => true,
                    'asset_types' => ['cloud'],
                ],
            ],
            [
                'id' => 'C5-2025-ECS-3',
                'title' => 'Hypervisor Security',
                'description' => 'Hypervisors shall be hardened and regularly updated to prevent cross-tenant attacks.',
                'category' => 'Enhanced Client Separation',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.8', '8.9'],
                    'new_in_c5_2025' => true,
                    'asset_types' => ['cloud'],
                ],
            ],
            [
                'id' => 'C5-2025-ECS-4',
                'title' => 'Network Isolation',
                'description' => 'Network isolation between tenants shall be enforced at multiple layers.',
                'category' => 'Enhanced Client Separation',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.20', '8.22'],
                    'new_in_c5_2025' => true,
                    'asset_types' => ['cloud'],
                ],
            ],

            // NEW: NIS2 Alignment (NIS2) - C5:2025 specific
            [
                'id' => 'C5-2025-NIS2-1',
                'title' => 'NIS2 Incident Reporting',
                'description' => 'Incident reporting procedures shall align with NIS2 Directive timelines and requirements.',
                'category' => 'NIS2 Alignment',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.26'],
                    'new_in_c5_2025' => true,
                    'incident_management' => true,
                    'nis2_aligned' => true,
                ],
            ],
            [
                'id' => 'C5-2025-NIS2-2',
                'title' => 'Supply Chain Risk Management (NIS2)',
                'description' => 'Supply chain risks shall be managed in accordance with NIS2 requirements.',
                'category' => 'NIS2 Alignment',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19', '5.20'],
                    'new_in_c5_2025' => true,
                    'nis2_aligned' => true,
                ],
            ],
            [
                'id' => 'C5-2025-NIS2-3',
                'title' => 'Cybersecurity Governance (NIS2)',
                'description' => 'Cybersecurity governance shall meet NIS2 management accountability requirements.',
                'category' => 'NIS2 Alignment',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1', '5.2'],
                    'new_in_c5_2025' => true,
                    'nis2_aligned' => true,
                ],
            ],

            // NEW: AI and Machine Learning Security (AI) - C5:2025 specific
            [
                'id' => 'C5-2025-AI-1',
                'title' => 'AI Model Security',
                'description' => 'AI and ML models shall be protected against adversarial attacks and model theft.',
                'category' => 'AI and ML Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.28'],
                    'new_in_c5_2025' => true,
                    'asset_types' => ['data'],
                ],
            ],
            [
                'id' => 'C5-2025-AI-2',
                'title' => 'Training Data Security',
                'description' => 'Training data for AI/ML models shall be protected and validated for integrity.',
                'category' => 'AI and ML Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.11'],
                    'new_in_c5_2025' => true,
                    'asset_types' => ['data'],
                ],
            ],
            [
                'id' => 'C5-2025-AI-3',
                'title' => 'AI Transparency and Explainability',
                'description' => 'AI systems shall provide transparency and explainability for critical decisions.',
                'category' => 'AI and ML Security',
                'priority' => 'medium',
                'data_source_mapping' => [
                    'new_in_c5_2025' => true,
                ],
            ],
            [
                'id' => 'C5-2025-AI-4',
                'title' => 'Bias Detection and Mitigation',
                'description' => 'AI/ML models shall be tested for bias and discrimination.',
                'category' => 'AI and ML Security',
                'priority' => 'medium',
                'data_source_mapping' => [
                    'new_in_c5_2025' => true,
                ],
            ],

            // ENHANCED from C5:2020 - Organization (ORP) with subcriteria
            [
                'id' => 'C5-2025-ORP-1.1',
                'title' => 'Information Security Policy - Definition',
                'description' => 'Information security policies shall be established and documented.',
                'category' => 'Organisation and Personnel',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1'],
                    'c5_2020_reference' => 'C5-ORP-1',
                    'subcriteria' => '1.1',
                ],
            ],
            [
                'id' => 'C5-2025-ORP-1.2',
                'title' => 'Information Security Policy - Communication',
                'description' => 'Information security policies shall be communicated to all relevant parties.',
                'category' => 'Organisation and Personnel',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1'],
                    'c5_2020_reference' => 'C5-ORP-1',
                    'subcriteria' => '1.2',
                ],
            ],
            [
                'id' => 'C5-2025-ORP-1.3',
                'title' => 'Information Security Policy - Review',
                'description' => 'Information security policies shall be reviewed and updated regularly.',
                'category' => 'Organisation and Personnel',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1'],
                    'c5_2020_reference' => 'C5-ORP-1',
                    'subcriteria' => '1.3',
                ],
            ],

            // EUCS Substantial Alignment
            [
                'id' => 'C5-2025-EUCS-1',
                'title' => 'EUCS Substantial Compliance',
                'description' => 'Cloud services shall meet EUCS Substantial assurance level requirements.',
                'category' => 'EUCS Alignment',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'new_in_c5_2025' => true,
                    'eucs_substantial' => true,
                ],
            ],
            [
                'id' => 'C5-2025-EUCS-2',
                'title' => 'EU Data Residency',
                'description' => 'Data residency requirements for EU customers shall be clearly defined and enforced.',
                'category' => 'EUCS Alignment',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.20'],
                    'new_in_c5_2025' => true,
                    'eucs_substantial' => true,
                    'asset_types' => ['data', 'cloud'],
                    'gdpr_relevant' => true,
                ],
            ],
            [
                'id' => 'C5-2025-EUCS-3',
                'title' => 'Cross-Border Data Transfer Controls',
                'description' => 'Cross-border data transfers shall comply with EU regulations.',
                'category' => 'EUCS Alignment',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.20', '5.34'],
                    'new_in_c5_2025' => true,
                    'eucs_substantial' => true,
                    'gdpr_relevant' => true,
                ],
            ],

            // ISO 27001:2022 New Controls Integration
            [
                'id' => 'C5-2025-ISO-1',
                'title' => 'Threat Intelligence',
                'description' => 'Threat intelligence shall be collected and used to inform security measures (ISO 27001:2022 5.7).',
                'category' => 'ISO 27001:2022 Integration',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.7'],
                    'new_in_c5_2025' => true,
                    'iso_27001_2022' => true,
                ],
            ],
            [
                'id' => 'C5-2025-ISO-2',
                'title' => 'Cloud Services Security',
                'description' => 'Use of cloud services shall be managed securely (ISO 27001:2022 5.23).',
                'category' => 'ISO 27001:2022 Integration',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.23'],
                    'new_in_c5_2025' => true,
                    'iso_27001_2022' => true,
                    'asset_types' => ['cloud'],
                ],
            ],
            [
                'id' => 'C5-2025-ISO-3',
                'title' => 'Information Security for Cloud Computing',
                'description' => 'Cloud computing environments shall implement information security controls (ISO 27001:2022 5.23).',
                'category' => 'ISO 27001:2022 Integration',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.23'],
                    'new_in_c5_2025' => true,
                    'iso_27001_2022' => true,
                    'asset_types' => ['cloud'],
                ],
            ],

            // CSA CCM v4 Alignment
            [
                'id' => 'C5-2025-CSA-1',
                'title' => 'DevSecOps Integration',
                'description' => 'Security shall be integrated into DevOps practices (DevSecOps).',
                'category' => 'CSA CCM v4 Alignment',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.25', '8.27'],
                    'new_in_c5_2025' => true,
                    'csa_ccm_v4' => true,
                ],
            ],
            [
                'id' => 'C5-2025-CSA-2',
                'title' => 'Infrastructure as Code Security',
                'description' => 'Infrastructure as Code (IaC) shall be secured and version-controlled.',
                'category' => 'CSA CCM v4 Alignment',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.27', '8.32'],
                    'new_in_c5_2025' => true,
                    'csa_ccm_v4' => true,
                    'asset_types' => ['cloud'],
                ],
            ],
            [
                'id' => 'C5-2025-CSA-3',
                'title' => 'Serverless Security',
                'description' => 'Serverless computing environments shall be secured appropriately.',
                'category' => 'CSA CCM v4 Alignment',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.20'],
                    'new_in_c5_2025' => true,
                    'csa_ccm_v4' => true,
                    'asset_types' => ['cloud'],
                ],
            ],
        ];
    }
}
