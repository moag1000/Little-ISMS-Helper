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
    name: 'app:supplement-tisax-requirements',
    description: 'Supplement TISAX with additional VDA ISA 6.0.2 requirements for completeness'
)]
class SupplementTisaxRequirementsCommand extends Command
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Get existing TISAX framework
        $framework = $this->entityManager->getRepository(ComplianceFramework::class)
            ->findOneBy(['code' => 'TISAX']);

        if (!$framework) {
            $io->error('TISAX framework not found. Please run app:load-tisax-requirements first.');
            return Command::FAILURE;
        }

        $io->info('Adding additional VDA ISA 6.0.2 requirements to TISAX framework...');

        try {
            $this->entityManager->beginTransaction();

            $additionalRequirements = $this->getAdditionalTisaxRequirements();
            $addedCount = 0;

            foreach ($additionalRequirements as $reqData) {
                // Check if requirement already exists
                $existing = $this->entityManager
                    ->getRepository(ComplianceRequirement::class)
                    ->findOneBy([
                        'framework' => $framework,
                        'requirementId' => $reqData['id']
                    ]);

                if ($existing) {
                    continue;
                }

                $requirement = new ComplianceRequirement();
                $requirement->setFramework($framework)
                    ->setRequirementId($reqData['id'])
                    ->setTitle($reqData['title'])
                    ->setDescription($reqData['description'])
                    ->setCategory($reqData['category'])
                    ->setPriority($reqData['priority'])
                    ->setDataSourceMapping($reqData['data_source_mapping']);

                $this->entityManager->persist($requirement);
                $addedCount++;
            }

            $framework->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->persist($framework);

            $this->entityManager->flush();
            $this->entityManager->commit();

            $io->success(sprintf('Successfully added %d additional requirements to TISAX framework', $addedCount));
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            $io->error('Failed to supplement TISAX requirements: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function getAdditionalTisaxRequirements(): array
    {
        return [
            // 5. Data Protection (DAT) - Missing requirements
            [
                'id' => 'DAT-1.1',
                'title' => 'Protection of Prototypes and Pre-Production Information',
                'description' => 'Special protection measures shall be implemented for prototypes and pre-production information.',
                'category' => 'Data Protection',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.12', '8.11'],
                    'asset_types' => ['data'],
                ],
            ],
            [
                'id' => 'DAT-1.2',
                'title' => 'Secure Handling of Customer Data',
                'description' => 'Customer data including technical drawings, specifications and confidential information shall be handled securely.',
                'category' => 'Data Protection',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.12', '5.34', '8.11'],
                    'asset_types' => ['data'],
                ],
            ],
            [
                'id' => 'DAT-2.1',
                'title' => 'Data Classification and Handling',
                'description' => 'All data shall be classified according to confidentiality levels and handled accordingly.',
                'category' => 'Data Classification',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.12'],
                    'asset_types' => ['data'],
                ],
            ],
            [
                'id' => 'DAT-3.1',
                'title' => 'Data Disposal',
                'description' => 'Secure disposal procedures shall be in place for all types of data carriers.',
                'category' => 'Data Disposal',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.10'],
                ],
            ],
            [
                'id' => 'DAT-4.1',
                'title' => 'Mobile Data Carriers',
                'description' => 'Use of mobile data carriers shall be controlled and encrypted.',
                'category' => 'Mobile Data',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.9', '8.24'],
                ],
            ],

            // 6. Network Security (NET)
            [
                'id' => 'NET-1.1',
                'title' => 'Network Architecture Documentation',
                'description' => 'Network architecture shall be documented including all connections and security zones.',
                'category' => 'Network Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.20'],
                ],
            ],
            [
                'id' => 'NET-1.2',
                'title' => 'Firewall Configuration',
                'description' => 'Firewalls shall be configured according to the principle of least privilege.',
                'category' => 'Network Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.20'],
                ],
            ],
            [
                'id' => 'NET-2.1',
                'title' => 'Wireless Network Security',
                'description' => 'Wireless networks shall be secured using WPA3 or equivalent encryption.',
                'category' => 'Wireless Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.21', '8.24'],
                ],
            ],
            [
                'id' => 'NET-3.1',
                'title' => 'Remote Access Security',
                'description' => 'Remote access shall be secured using VPN with multi-factor authentication.',
                'category' => 'Remote Access',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.17', '8.21'],
                ],
            ],
            [
                'id' => 'NET-4.1',
                'title' => 'Network Monitoring',
                'description' => 'Network traffic shall be continuously monitored for anomalies and security events.',
                'category' => 'Network Monitoring',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.15', '8.16'],
                ],
            ],

            // 7. Identity and Access Management (IAM)
            [
                'id' => 'IAM-1.1',
                'title' => 'Identity Lifecycle Management',
                'description' => 'Processes for creating, modifying and deleting user identities shall be established.',
                'category' => 'Identity Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.18'],
                ],
            ],
            [
                'id' => 'IAM-2.1',
                'title' => 'Strong Authentication',
                'description' => 'Multi-factor authentication shall be used for all privileged and remote access.',
                'category' => 'Authentication',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.17', '5.18'],
                ],
            ],
            [
                'id' => 'IAM-3.1',
                'title' => 'Privileged Account Management',
                'description' => 'Privileged accounts shall be managed, monitored and regularly reviewed.',
                'category' => 'Privileged Access',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.2'],
                ],
            ],
            [
                'id' => 'IAM-4.1',
                'title' => 'Access Rights Review',
                'description' => 'Access rights shall be reviewed at least annually and upon role changes.',
                'category' => 'Access Control',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.18'],
                ],
            ],

            // 8. Software Development (DEV)
            [
                'id' => 'DEV-1.1',
                'title' => 'Secure Development Lifecycle',
                'description' => 'A secure development lifecycle (SDL) shall be implemented for all software development.',
                'category' => 'Software Development',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.25'],
                ],
            ],
            [
                'id' => 'DEV-1.2',
                'title' => 'Code Review and Testing',
                'description' => 'Code shall be reviewed and security tested before deployment.',
                'category' => 'Software Development',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.25', '8.29'],
                ],
            ],
            [
                'id' => 'DEV-2.1',
                'title' => 'Change Management',
                'description' => 'All changes to systems and applications shall follow a formal change management process.',
                'category' => 'Change Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.32'],
                ],
            ],
            [
                'id' => 'DEV-3.1',
                'title' => 'Development and Production Separation',
                'description' => 'Development, testing and production environments shall be separated.',
                'category' => 'Environment Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.31'],
                ],
            ],

            // 9. Supplier Management (SUP)
            [
                'id' => 'SUP-1.1',
                'title' => 'Supplier Security Assessment',
                'description' => 'Security assessments shall be conducted for all suppliers handling sensitive information.',
                'category' => 'Supplier Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19', '5.20'],
                ],
            ],
            [
                'id' => 'SUP-1.2',
                'title' => 'Supplier Contracts',
                'description' => 'Contracts with suppliers shall include security requirements and right to audit.',
                'category' => 'Supplier Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19', '5.20'],
                ],
            ],
            [
                'id' => 'SUP-2.1',
                'title' => 'Supplier Monitoring',
                'description' => 'Supplier compliance with security requirements shall be regularly monitored.',
                'category' => 'Supplier Management',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.21'],
                ],
            ],
            [
                'id' => 'SUP-3.1',
                'title' => 'Sub-contractor Management',
                'description' => 'Use of sub-contractors by suppliers shall be controlled and approved.',
                'category' => 'Supplier Management',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.22'],
                ],
            ],

            // 10. Security Testing (TST)
            [
                'id' => 'TST-1.1',
                'title' => 'Penetration Testing',
                'description' => 'Penetration tests shall be conducted at least annually for internet-facing systems.',
                'category' => 'Security Testing',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.8', '8.29'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'TST-1.2',
                'title' => 'Vulnerability Scanning',
                'description' => 'Automated vulnerability scans shall be performed regularly.',
                'category' => 'Security Testing',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.8'],
                ],
            ],
            [
                'id' => 'TST-2.1',
                'title' => 'Security Test Documentation',
                'description' => 'Results of security tests and remediation actions shall be documented.',
                'category' => 'Security Testing',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.8'],
                    'audit_evidence' => true,
                ],
            ],

            // 11. Secure Disposal (DIS)
            [
                'id' => 'DIS-1.1',
                'title' => 'Media Sanitization',
                'description' => 'Storage media shall be sanitized using approved methods before disposal or reuse.',
                'category' => 'Secure Disposal',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.10'],
                ],
            ],
            [
                'id' => 'DIS-1.2',
                'title' => 'Hardware Disposal',
                'description' => 'Hardware containing sensitive data shall be physically destroyed or certified wiped.',
                'category' => 'Secure Disposal',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.10'],
                ],
            ],

            // 12. Personnel Security (PER)
            [
                'id' => 'PER-1.1',
                'title' => 'Background Checks',
                'description' => 'Background checks shall be conducted for personnel with access to sensitive information.',
                'category' => 'Personnel Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['6.1'],
                ],
            ],
            [
                'id' => 'PER-1.2',
                'title' => 'Confidentiality Agreements',
                'description' => 'All personnel shall sign confidentiality and non-disclosure agreements.',
                'category' => 'Personnel Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['6.2'],
                ],
            ],
            [
                'id' => 'PER-2.1',
                'title' => 'Security Awareness Training',
                'description' => 'Regular security awareness training shall be provided to all personnel.',
                'category' => 'Training',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['6.3'],
                ],
            ],
            [
                'id' => 'PER-3.1',
                'title' => 'Termination Procedures',
                'description' => 'Procedures for terminating access rights shall be followed upon personnel departure.',
                'category' => 'Personnel Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['6.4'],
                ],
            ],

            // 13. End User Devices (END)
            [
                'id' => 'END-1.1',
                'title' => 'Mobile Device Management',
                'description' => 'Mobile devices accessing company data shall be managed through MDM solution.',
                'category' => 'End User Devices',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.9'],
                ],
            ],
            [
                'id' => 'END-1.2',
                'title' => 'Endpoint Protection',
                'description' => 'All endpoints shall have updated antivirus and endpoint detection and response (EDR) solutions.',
                'category' => 'End User Devices',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.7'],
                ],
            ],
            [
                'id' => 'END-2.1',
                'title' => 'BYOD Policy',
                'description' => 'If BYOD is permitted, strict security controls including containerization shall be implemented.',
                'category' => 'End User Devices',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.9'],
                ],
            ],
        ];
    }
}
