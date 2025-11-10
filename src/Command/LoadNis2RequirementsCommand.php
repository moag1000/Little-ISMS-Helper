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
    name: 'app:load-nis2-requirements',
    description: 'Load NIS2 (Network and Information Security Directive 2) requirements with ISMS data mappings'
)]
class LoadNis2RequirementsCommand extends Command
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Create or get NIS2 framework
        $framework = $this->entityManager->getRepository(ComplianceFramework::class)
            ->findOneBy(['code' => 'NIS2']);

        if (!$framework) {
            $framework = new ComplianceFramework();
            $framework->setCode('NIS2')
                ->setName('NIS2 (Network and Information Security Directive 2)')
                ->setDescription('EU directive on measures for a high common level of cybersecurity across the Union')
                ->setVersion('2022/2555')
                ->setApplicableIndustry('all_sectors')
                ->setRegulatoryBody('European Union')
                ->setMandatory(true)
                ->setScopeDescription('Applies to essential and important entities across various sectors including energy, transport, banking, health, digital infrastructure, and more')
                ->setActive(true);

            $this->entityManager->persist($framework);
        } else {
            // Framework exists - check if requirements are already loaded
            $existingRequirements = $this->entityManager
                ->getRepository(ComplianceRequirement::class)
                ->findBy(['framework' => $framework]);

            if (!empty($existingRequirements)) {
                $io->warning(sprintf(
                    'Framework NIS2 already has %d requirements loaded. Skipping to avoid duplicates.',
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

            $requirements = $this->getNis2Requirements();

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

            $io->success(sprintf('Successfully loaded %d NIS2 requirements', count($requirements)));
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            $io->error('Failed to load NIS2 requirements: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function getNis2Requirements(): array
    {
        return [
            // Article 21: Cybersecurity Risk Management Measures
            [
                'id' => 'NIS2-21.1',
                'title' => 'Risk Analysis and Information System Security',
                'description' => 'Entities shall implement policies on risk analysis and information system security.',
                'category' => 'Risk Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.7', '8.8'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'NIS2-21.2',
                'title' => 'Incident Handling',
                'description' => 'Entities shall have policies and procedures to handle incidents.',
                'category' => 'Incident Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.24', '5.25', '5.26'],
                    'incident_management' => true,
                ],
            ],
            [
                'id' => 'NIS2-21.3',
                'title' => 'Business Continuity and Crisis Management',
                'description' => 'Entities shall implement business continuity, such as backup management and disaster recovery, and crisis management.',
                'category' => 'Business Continuity',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.29', '5.30'],
                    'bcm_required' => true,
                ],
            ],
            [
                'id' => 'NIS2-21.4',
                'title' => 'Supply Chain Security',
                'description' => 'Entities shall implement security measures for supply chain, including security-related aspects concerning the relationships between each entity and its suppliers or service providers.',
                'category' => 'Supply Chain Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19', '5.20', '5.21', '5.22'],
                ],
            ],
            [
                'id' => 'NIS2-21.5',
                'title' => 'Security in Network and Information Systems Acquisition',
                'description' => 'Entities shall implement policies on security in network and information systems acquisition, development and maintenance.',
                'category' => 'System Development',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.25', '8.26', '8.27', '8.28'],
                ],
            ],
            [
                'id' => 'NIS2-21.6',
                'title' => 'Vulnerability Handling and Disclosure',
                'description' => 'Entities shall implement policies and procedures to assess the effectiveness of cybersecurity risk-management measures and handle vulnerabilities.',
                'category' => 'Vulnerability Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.8'],
                ],
            ],
            [
                'id' => 'NIS2-21.7',
                'title' => 'Basic Cyber Hygiene Practices',
                'description' => 'Entities shall implement practices including training and education, as well as policies for basic cyber hygiene.',
                'category' => 'Awareness and Training',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['6.3'],
                ],
            ],
            [
                'id' => 'NIS2-21.8',
                'title' => 'Cryptography and Encryption',
                'description' => 'Entities shall implement policies and procedures for the use of cryptography and encryption.',
                'category' => 'Cryptography',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                ],
            ],
            [
                'id' => 'NIS2-21.9',
                'title' => 'Human Resources Security',
                'description' => 'Entities shall implement human resources security measures, access control policies and asset management.',
                'category' => 'Human Resources',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['6.1', '6.2', '6.3', '6.4'],
                ],
            ],
            [
                'id' => 'NIS2-21.10',
                'title' => 'Access Control',
                'description' => 'Entities shall implement access control policies and procedures.',
                'category' => 'Access Control',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.15', '5.16', '5.17', '5.18'],
                ],
            ],
            [
                'id' => 'NIS2-21.11',
                'title' => 'Asset Management',
                'description' => 'Entities shall identify and maintain an inventory of network and information systems.',
                'category' => 'Asset Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.9'],
                    'asset_types' => ['hardware', 'software', 'data', 'services', 'cloud'],
                ],
            ],
            [
                'id' => 'NIS2-21.12',
                'title' => 'Multi-Factor Authentication',
                'description' => 'Entities shall use multi-factor authentication or continuous authentication solutions.',
                'category' => 'Authentication',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.17', '5.18'],
                ],
            ],
            [
                'id' => 'NIS2-21.13',
                'title' => 'Secured Voice, Video and Text Communications',
                'description' => 'Entities shall use secured voice, video and text communications and secured emergency communication systems.',
                'category' => 'Communications Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.14', '8.22'],
                ],
            ],

            // Article 23: Reporting Obligations
            [
                'id' => 'NIS2-23.1',
                'title' => 'Early Warning Notification',
                'description' => 'Entities shall notify the CSIRT or competent authority without undue delay (24 hours) of any incident having a significant impact.',
                'category' => 'Incident Reporting',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.26'],
                    'incident_management' => true,
                ],
            ],
            [
                'id' => 'NIS2-23.2',
                'title' => 'Incident Notification',
                'description' => 'Entities shall submit an incident notification within 72 hours of becoming aware of the significant incident.',
                'category' => 'Incident Reporting',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.26'],
                    'incident_management' => true,
                ],
            ],
            [
                'id' => 'NIS2-23.3',
                'title' => 'Final Report',
                'description' => 'Entities shall submit a final report within one month of the incident notification.',
                'category' => 'Incident Reporting',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.26', '5.28'],
                    'incident_management' => true,
                ],
            ],

            // Article 20: Governance
            [
                'id' => 'NIS2-20.1',
                'title' => 'Management Body Approval',
                'description' => 'The management body shall be required to approve the cybersecurity risk-management measures.',
                'category' => 'Governance',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1', '5.3'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'NIS2-20.2',
                'title' => 'Management Body Oversight',
                'description' => 'The management body shall oversee the implementation of cybersecurity risk-management measures.',
                'category' => 'Governance',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1', '5.2', '5.3'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'NIS2-20.3',
                'title' => 'Management Training',
                'description' => 'Members of the management body shall follow training on cybersecurity.',
                'category' => 'Governance',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['6.3'],
                ],
            ],

            // Additional Security Measures
            [
                'id' => 'NIS2-SEC.1',
                'title' => 'Network Segmentation',
                'description' => 'Entities shall implement network segmentation based on risk assessment.',
                'category' => 'Network Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.20', '8.21'],
                ],
            ],
            [
                'id' => 'NIS2-SEC.2',
                'title' => 'Security Monitoring',
                'description' => 'Entities shall implement continuous security monitoring and logging.',
                'category' => 'Security Monitoring',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.15', '8.16'],
                ],
            ],
            [
                'id' => 'NIS2-SEC.3',
                'title' => 'Backup and Recovery',
                'description' => 'Entities shall implement backup management and disaster recovery procedures.',
                'category' => 'Backup',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.13'],
                    'bcm_required' => true,
                ],
            ],
            [
                'id' => 'NIS2-SEC.4',
                'title' => 'Physical Security',
                'description' => 'Entities shall protect physical locations and facilities.',
                'category' => 'Physical Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['7.1', '7.2', '7.3'],
                ],
            ],
        ];
    }
}
