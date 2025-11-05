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
    name: 'app:load-tisax-requirements',
    description: 'Load TISAX (VDA ISA) requirements with ISO 27001 control mappings'
)]
class LoadTisaxRequirementsCommand extends Command
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Create or get TISAX framework
        $framework = $this->entityManager->getRepository(ComplianceFramework::class)
            ->findOneBy(['code' => 'TISAX']);

        if (!$framework) {
            $framework = new ComplianceFramework();
            $framework->setCode('TISAX')
                ->setName('TISAX (Trusted Information Security Assessment Exchange)')
                ->setDescription('Information security assessment standard for the automotive industry based on VDA ISA')
                ->setVersion('6.0.2')
                ->setApplicableIndustry('automotive')
                ->setRegulatoryBody('VDA (Verband der Automobilindustrie)')
                ->setMandatory(false)
                ->setScopeDescription('Comprehensive information security assessment for automotive supply chain')
                ->setActive(true);

            $this->entityManager->persist($framework);
        }

        $requirements = $this->getTisaxRequirements();

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

        $io->success(sprintf('Successfully loaded %d TISAX requirements', count($requirements)));

        return Command::SUCCESS;
    }

    private function getTisaxRequirements(): array
    {
        return [
            // 1. Information Security (INF)
            [
                'id' => 'INF-1.1',
                'title' => 'Information Security Policy',
                'description' => 'An information security policy shall be defined, documented, approved by management, published and communicated to employees and relevant external parties.',
                'category' => 'Information Security Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'INF-1.2',
                'title' => 'Review of Information Security Policy',
                'description' => 'The information security policy shall be reviewed at planned intervals or if significant changes occur to ensure its continuing suitability, adequacy and effectiveness.',
                'category' => 'Information Security Management',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'INF-2.1',
                'title' => 'Management Responsibility for Information Security',
                'description' => 'Management shall actively support security within the organization through clear direction, demonstrated commitment, explicit assignment and acknowledgment of information security responsibilities.',
                'category' => 'Information Security Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.3'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'INF-3.1',
                'title' => 'Inventory of Assets',
                'description' => 'Assets associated with information and information processing facilities shall be identified and an inventory of these assets shall be drawn up and maintained.',
                'category' => 'Asset Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.9'],
                    'asset_types' => ['hardware', 'software', 'data', 'services'],
                ],
            ],
            [
                'id' => 'INF-3.2',
                'title' => 'Ownership of Assets',
                'description' => 'Assets maintained in the inventory shall be owned.',
                'category' => 'Asset Management',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.9'],
                    'asset_types' => ['hardware', 'software', 'data', 'services'],
                ],
            ],
            [
                'id' => 'INF-3.3',
                'title' => 'Acceptable Use of Assets',
                'description' => 'Rules for the acceptable use of information and of assets associated with information and information processing facilities shall be identified, documented and implemented.',
                'category' => 'Asset Management',
                'priority' => 'medium',
                'data_source_mapping' => [
                    'iso_controls' => ['5.10'],
                    'asset_types' => ['hardware', 'software', 'data'],
                ],
            ],
            [
                'id' => 'INF-3.4',
                'title' => 'Return of Assets',
                'description' => 'All employees and external party users shall return all of the organizational assets in their possession upon termination of their employment, contract or agreement.',
                'category' => 'Asset Management',
                'priority' => 'medium',
                'data_source_mapping' => [
                    'iso_controls' => ['5.10'],
                ],
            ],
            [
                'id' => 'INF-4.1',
                'title' => 'Classification Guidelines',
                'description' => 'Information shall be classified in terms of legal requirements, value, criticality and sensitivity to unauthorised disclosure or modification.',
                'category' => 'Information Classification',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.12'],
                    'asset_types' => ['data'],
                ],
            ],
            [
                'id' => 'INF-4.2',
                'title' => 'Labelling of Information',
                'description' => 'An appropriate set of procedures for information labelling shall be developed and implemented in accordance with the information classification scheme adopted by the organization.',
                'category' => 'Information Classification',
                'priority' => 'medium',
                'data_source_mapping' => [
                    'iso_controls' => ['5.13'],
                ],
            ],

            // 2. Access Control (ACC)
            [
                'id' => 'ACC-1.1',
                'title' => 'Access Control Policy',
                'description' => 'An access control policy shall be established, documented and reviewed based on business and information security requirements.',
                'category' => 'Access Control',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.15'],
                ],
            ],
            [
                'id' => 'ACC-2.1',
                'title' => 'User Registration and De-registration',
                'description' => 'A formal user registration and de-registration process shall be implemented to enable assignment of access rights.',
                'category' => 'User Access Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.18'],
                ],
            ],
            [
                'id' => 'ACC-2.2',
                'title' => 'User Access Provisioning',
                'description' => 'A formal user access provisioning process shall be implemented to assign or revoke access rights for all user types to all systems and services.',
                'category' => 'User Access Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.18'],
                ],
            ],
            [
                'id' => 'ACC-2.3',
                'title' => 'Management of Privileged Access Rights',
                'description' => 'The allocation and use of privileged access rights shall be restricted and controlled.',
                'category' => 'User Access Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.2'],
                ],
            ],
            [
                'id' => 'ACC-3.1',
                'title' => 'Use of Secret Authentication Information',
                'description' => 'Users shall be required to follow good security practices in the selection and use of secret authentication information.',
                'category' => 'User Responsibilities',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.17', '5.18'],
                ],
            ],

            // 3. Cryptography (CRY)
            [
                'id' => 'CRY-1.1',
                'title' => 'Policy on the Use of Cryptographic Controls',
                'description' => 'A policy on the use of cryptographic controls for protection of information shall be developed and implemented.',
                'category' => 'Cryptography',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                ],
            ],
            [
                'id' => 'CRY-1.2',
                'title' => 'Key Management',
                'description' => 'A policy on the use, protection and lifetime of cryptographic keys shall be developed and implemented through their whole lifecycle.',
                'category' => 'Cryptography',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.24'],
                ],
            ],

            // 4. Physical and Environmental Security (PHY)
            [
                'id' => 'PHY-1.1',
                'title' => 'Physical Security Perimeter',
                'description' => 'Security perimeters shall be defined and used to protect areas that contain either sensitive or critical information and information processing facilities.',
                'category' => 'Physical Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['7.1'],
                ],
            ],
            [
                'id' => 'PHY-1.2',
                'title' => 'Physical Entry Controls',
                'description' => 'Secure areas shall be protected by appropriate entry controls to ensure that only authorized personnel are allowed access.',
                'category' => 'Physical Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['7.2'],
                ],
            ],
            [
                'id' => 'PHY-2.1',
                'title' => 'Equipment Siting and Protection',
                'description' => 'Equipment shall be sited and protected to reduce the risks from environmental threats and hazards, and opportunities for unauthorized access.',
                'category' => 'Equipment Security',
                'priority' => 'medium',
                'data_source_mapping' => [
                    'iso_controls' => ['7.8'],
                ],
            ],

            // 5. Operations Security (OPS)
            [
                'id' => 'OPS-1.1',
                'title' => 'Documented Operating Procedures',
                'description' => 'Operating procedures shall be documented and made available to all users who need them.',
                'category' => 'Operations Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.1'],
                ],
            ],
            [
                'id' => 'OPS-2.1',
                'title' => 'Protection Against Malware',
                'description' => 'Detection, prevention and recovery controls to protect against malware shall be implemented, combined with appropriate user awareness.',
                'category' => 'Malware Protection',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.7'],
                ],
            ],
            [
                'id' => 'OPS-3.1',
                'title' => 'Information Backup',
                'description' => 'Backup copies of information, software and system images shall be taken and tested regularly in accordance with an agreed backup policy.',
                'category' => 'Backup',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.13'],
                    'bcm_required' => true,
                ],
            ],
            [
                'id' => 'OPS-4.1',
                'title' => 'Event Logging',
                'description' => 'Event logs recording user activities, exceptions, faults and information security events shall be produced, kept and regularly reviewed.',
                'category' => 'Logging and Monitoring',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.15'],
                ],
            ],
            [
                'id' => 'OPS-4.2',
                'title' => 'Protection of Log Information',
                'description' => 'Logging facilities and log information shall be protected against tampering and unauthorized access.',
                'category' => 'Logging and Monitoring',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.15'],
                ],
            ],

            // 6. Communications Security (COM)
            [
                'id' => 'COM-1.1',
                'title' => 'Network Controls',
                'description' => 'Networks shall be managed and controlled to protect information in systems and applications.',
                'category' => 'Network Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.20', '8.21'],
                ],
            ],
            [
                'id' => 'COM-2.1',
                'title' => 'Information Transfer Policies and Procedures',
                'description' => 'Formal transfer policies, procedures and controls shall be in place to protect the transfer of information through the use of all types of communication facilities.',
                'category' => 'Information Transfer',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.14'],
                ],
            ],

            // 7. Business Continuity Management (BCM)
            [
                'id' => 'BCM-1.1',
                'title' => 'Planning Information Security Continuity',
                'description' => 'The organization shall determine its requirements for information security and the continuity of information security management in adverse situations.',
                'category' => 'Business Continuity',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.29', '5.30'],
                    'bcm_required' => true,
                ],
            ],
            [
                'id' => 'BCM-1.2',
                'title' => 'Implementing Information Security Continuity',
                'description' => 'The organization shall establish, document, implement and maintain processes, procedures and controls to ensure the required level of continuity for information security during an adverse situation.',
                'category' => 'Business Continuity',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.30'],
                    'bcm_required' => true,
                ],
            ],

            // 8. Incident Management (INC)
            [
                'id' => 'INC-1.1',
                'title' => 'Responsibilities and Procedures',
                'description' => 'Management responsibilities and procedures shall be established to ensure a quick, effective and orderly response to information security incidents.',
                'category' => 'Incident Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.24', '5.25'],
                    'incident_management' => true,
                ],
            ],
            [
                'id' => 'INC-1.2',
                'title' => 'Reporting Information Security Events',
                'description' => 'Information security events shall be reported through appropriate management channels as quickly as possible.',
                'category' => 'Incident Management',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.24'],
                    'incident_management' => true,
                ],
            ],
            [
                'id' => 'INC-1.3',
                'title' => 'Assessment of and Decision on Information Security Events',
                'description' => 'Information security events shall be assessed and it shall be decided if they are to be classified as information security incidents.',
                'category' => 'Incident Management',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.25'],
                    'incident_management' => true,
                ],
            ],
        ];
    }
}
