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
    name: 'app:load-iso22301-requirements',
    description: 'Load ISO 22301:2019 (Business Continuity) requirements with ISO 27001 mappings'
)]
class LoadIso22301RequirementsCommand extends Command
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Create or get ISO 22301 framework
        $framework = $this->entityManager->getRepository(ComplianceFramework::class)
            ->findOneBy(['code' => 'ISO-22301']);

        if (!$framework) {
            $framework = new ComplianceFramework();
            $framework->setCode('ISO-22301')
                ->setName('ISO 22301:2019 - Business Continuity Management')
                ->setDescription('Security and resilience — Business continuity management systems — Requirements')
                ->setVersion('2019')
                ->setApplicableIndustry('all')
                ->setRegulatoryBody('ISO (International Organization for Standardization)')
                ->setMandatory(false)
                ->setScopeDescription('International standard for business continuity management systems')
                ->setActive(true);

            $this->entityManager->persist($framework);
        }

        $requirements = $this->getIso22301Requirements();

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

        $io->success(sprintf('Successfully loaded %d ISO 22301 requirements', count($requirements)));

        return Command::SUCCESS;
    }

    private function getIso22301Requirements(): array
    {
        return [
            // Clause 4: Context of the Organization
            [
                'id' => 'ISO22301-4.1',
                'title' => 'Understanding the Organization and its Context',
                'description' => 'The organization shall determine external and internal issues relevant to its purpose and affecting its ability to achieve intended outcomes.',
                'category' => 'Context',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_27001_controls' => ['4.1'],
                ],
            ],
            [
                'id' => 'ISO22301-4.2',
                'title' => 'Needs and Expectations of Interested Parties',
                'description' => 'The organization shall determine relevant interested parties and their requirements relevant to the BCMS.',
                'category' => 'Context',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_27001_controls' => ['4.2'],
                ],
            ],
            [
                'id' => 'ISO22301-4.3',
                'title' => 'BCMS Scope',
                'description' => 'The organization shall determine the boundaries and applicability of the BCMS.',
                'category' => 'Context',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_27001_controls' => ['4.3'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'ISO22301-4.4',
                'title' => 'Business Continuity Management System',
                'description' => 'The organization shall establish, implement, maintain and continually improve a BCMS.',
                'category' => 'BCMS',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'bcm_required' => true,
                ],
            ],

            // Clause 5: Leadership
            [
                'id' => 'ISO22301-5.1',
                'title' => 'Leadership and Commitment',
                'description' => 'Top management shall demonstrate leadership and commitment with respect to the BCMS.',
                'category' => 'Leadership',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_27001_controls' => ['5.1'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'ISO22301-5.2',
                'title' => 'Policy',
                'description' => 'Top management shall establish a business continuity policy.',
                'category' => 'Leadership',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_27001_controls' => ['5.2'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'ISO22301-5.3',
                'title' => 'Roles, Responsibilities and Authorities',
                'description' => 'Top management shall ensure that responsibilities and authorities for relevant roles are assigned and communicated.',
                'category' => 'Leadership',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_27001_controls' => ['5.3'],
                ],
            ],

            // Clause 6: Planning
            [
                'id' => 'ISO22301-6.1',
                'title' => 'Actions to Address Risks and Opportunities',
                'description' => 'When planning the BCMS, the organization shall consider the issues and requirements and determine risks and opportunities.',
                'category' => 'Planning',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_27001_controls' => ['6.1'],
                ],
            ],
            [
                'id' => 'ISO22301-6.2',
                'title' => 'Business Continuity Objectives',
                'description' => 'The organization shall establish business continuity objectives at relevant functions and levels.',
                'category' => 'Planning',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_27001_controls' => ['6.2'],
                ],
            ],
            [
                'id' => 'ISO22301-6.3',
                'title' => 'Planning to Achieve Objectives',
                'description' => 'The organization shall plan how to achieve its business continuity objectives.',
                'category' => 'Planning',
                'priority' => 'high',
                'data_source_mapping' => [
                    'bcm_required' => true,
                ],
            ],

            // Clause 8.2: Business Impact Analysis
            [
                'id' => 'ISO22301-8.2.1',
                'title' => 'General (BIA)',
                'description' => 'The organization shall establish, implement and maintain a process for business impact analysis.',
                'category' => 'Business Impact Analysis',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'bcm_required' => true,
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'ISO22301-8.2.2',
                'title' => 'Activities and Resources',
                'description' => 'The BIA process shall identify activities that support products and services and assess impacts over time.',
                'category' => 'Business Impact Analysis',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'bcm_required' => true,
                ],
            ],
            [
                'id' => 'ISO22301-8.2.3',
                'title' => 'Time Frames for Recovery',
                'description' => 'The BIA shall determine RTO, RPO and MTPD for critical activities.',
                'category' => 'Business Impact Analysis',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'bcm_required' => true,
                ],
            ],

            // Clause 8.3: Business Continuity Strategy
            [
                'id' => 'ISO22301-8.3.1',
                'title' => 'General (Strategy)',
                'description' => 'The organization shall establish, implement and maintain a process to determine business continuity strategies.',
                'category' => 'BC Strategy',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'bcm_required' => true,
                ],
            ],
            [
                'id' => 'ISO22301-8.3.2',
                'title' => 'Selection of Strategy',
                'description' => 'The organization shall select appropriate strategies based on BIA results and risk assessment.',
                'category' => 'BC Strategy',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'bcm_required' => true,
                ],
            ],

            // Clause 8.4: Business Continuity Plans and Procedures
            [
                'id' => 'ISO22301-8.4.1',
                'title' => 'General (Plans)',
                'description' => 'The organization shall establish, implement and maintain business continuity plans and procedures.',
                'category' => 'BC Plans',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'bcm_required' => true,
                    'iso_27001_controls' => ['5.30'],
                ],
            ],
            [
                'id' => 'ISO22301-8.4.2',
                'title' => 'Incident Response Structure',
                'description' => 'The organization shall establish and implement an incident response structure.',
                'category' => 'BC Plans',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'bcm_required' => true,
                    'iso_27001_controls' => ['5.24', '5.25'],
                    'incident_management' => true,
                ],
            ],
            [
                'id' => 'ISO22301-8.4.3',
                'title' => 'Warning and Communication',
                'description' => 'The organization shall establish, implement and maintain warning and communication arrangements.',
                'category' => 'BC Plans',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'bcm_required' => true,
                    'iso_27001_controls' => ['5.26'],
                ],
            ],
            [
                'id' => 'ISO22301-8.4.4',
                'title' => 'Business Continuity Procedures',
                'description' => 'The organization shall document procedures to continue and recover prioritized activities.',
                'category' => 'BC Plans',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'bcm_required' => true,
                ],
            ],

            // Clause 8.5: Exercising and Testing
            [
                'id' => 'ISO22301-8.5',
                'title' => 'Exercising and Testing',
                'description' => 'The organization shall exercise and test its business continuity procedures at planned intervals.',
                'category' => 'Testing',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'bcm_required' => true,
                    'audit_evidence' => true,
                ],
            ],

            // Clause 9: Performance Evaluation
            [
                'id' => 'ISO22301-9.1',
                'title' => 'Monitoring, Measurement, Analysis and Evaluation',
                'description' => 'The organization shall evaluate BCMS performance and effectiveness.',
                'category' => 'Performance Evaluation',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_27001_controls' => ['9.1'],
                ],
            ],
            [
                'id' => 'ISO22301-9.2',
                'title' => 'Internal Audit',
                'description' => 'The organization shall conduct internal audits at planned intervals.',
                'category' => 'Performance Evaluation',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_27001_controls' => ['9.2'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'ISO22301-9.3',
                'title' => 'Management Review',
                'description' => 'Top management shall review the BCMS at planned intervals.',
                'category' => 'Performance Evaluation',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_27001_controls' => ['9.3'],
                    'audit_evidence' => true,
                ],
            ],

            // Clause 10: Improvement
            [
                'id' => 'ISO22301-10.1',
                'title' => 'Nonconformity and Corrective Action',
                'description' => 'When nonconformity occurs, the organization shall react, evaluate and take corrective action.',
                'category' => 'Improvement',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_27001_controls' => ['10.1'],
                ],
            ],
            [
                'id' => 'ISO22301-10.2',
                'title' => 'Continual Improvement',
                'description' => 'The organization shall continually improve the suitability, adequacy and effectiveness of the BCMS.',
                'category' => 'Improvement',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_27001_controls' => ['10.2'],
                ],
            ],
        ];
    }
}
