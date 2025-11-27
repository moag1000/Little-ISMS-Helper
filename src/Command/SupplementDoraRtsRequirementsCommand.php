<?php

namespace App\Command;

use DateTimeImmutable;
use Exception;
use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:supplement-dora-rts-requirements',
    description: 'Supplement DORA with additional RTS (Regulatory Technical Standards) requirements'
)]
class SupplementDoraRtsRequirementsCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $symfonyStyle = new SymfonyStyle($input, $output);

        // Get existing DORA framework
        $framework = $this->entityManager->getRepository(ComplianceFramework::class)
            ->findOneBy(['code' => 'DORA']);

        if (!$framework instanceof ComplianceFramework) {
            $symfonyStyle->error('DORA framework not found. Please run app:load-dora-requirements first.');
            return Command::FAILURE;
        }

        $symfonyStyle->info('Adding RTS (Regulatory Technical Standards) requirements to DORA framework...');

        try {
            $this->entityManager->beginTransaction();

            $rtsRequirements = $this->getDoraRtsRequirements();
            $addedCount = 0;

            foreach ($rtsRequirements as $rtRequirement) {
                // Check if requirement already exists
                $existing = $this->entityManager
                    ->getRepository(ComplianceRequirement::class)
                    ->findOneBy([
                        'framework' => $framework,
                        'requirementId' => $rtRequirement['id']
                    ]);

                if ($existing instanceof ComplianceRequirement) {
                    $symfonyStyle->note(sprintf('Requirement %s already exists, skipping...', $rtRequirement['id']));
                    continue;
                }

                $requirement = new ComplianceRequirement();
                $requirement->setFramework($framework)
                    ->setRequirementId($rtRequirement['id'])
                    ->setTitle($rtRequirement['title'])
                    ->setDescription($rtRequirement['description'])
                    ->setCategory($rtRequirement['category'])
                    ->setPriority($rtRequirement['priority'])
                    ->setDataSourceMapping($rtRequirement['data_source_mapping']);

                $this->entityManager->persist($requirement);
                $addedCount++;
            }

            $framework->setUpdatedAt(new DateTimeImmutable());
            $this->entityManager->persist($framework);

            $this->entityManager->flush();
            $this->entityManager->commit();

            $symfonyStyle->success(sprintf('Successfully added %d RTS requirements to DORA framework', $addedCount));
        } catch (Exception $e) {
            $this->entityManager->rollback();
            $symfonyStyle->error('Failed to supplement DORA RTS requirements: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function getDoraRtsRequirements(): array
    {
        return [
            // RTS on ICT Risk Management Framework (Article 6-16)
            [
                'id' => 'DORA-RTS-1.1',
                'title' => 'ICT Risk Management Functions and Responsibilities',
                'description' => 'Financial entities shall clearly define and document roles and responsibilities for ICT risk management at all relevant organizational levels.',
                'category' => 'ICT Risk Management RTS',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.3'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'DORA-RTS-1.2',
                'title' => 'ICT Risk Management Policies',
                'description' => 'Financial entities shall establish comprehensive ICT risk management policies covering identification, protection, detection, response and recovery.',
                'category' => 'ICT Risk Management RTS',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1', '8.1'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'DORA-RTS-1.3',
                'title' => 'ICT Risk Identification Procedures',
                'description' => 'Financial entities shall implement procedures for the timely identification and classification of ICT risks.',
                'category' => 'ICT Risk Management RTS',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.7', '8.8'],
                ],
            ],
            [
                'id' => 'DORA-RTS-1.4',
                'title' => 'ICT Protection Measures',
                'description' => 'Financial entities shall implement appropriate protection measures including access controls, encryption, and network security.',
                'category' => 'ICT Risk Management RTS',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.15', '8.20', '8.24'],
                ],
            ],
            [
                'id' => 'DORA-RTS-1.5',
                'title' => 'ICT Detection Mechanisms',
                'description' => 'Financial entities shall implement detection mechanisms to identify anomalies and cyber threats in real-time.',
                'category' => 'ICT Risk Management RTS',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.15', '8.16'],
                ],
            ],
            [
                'id' => 'DORA-RTS-1.6',
                'title' => 'ICT Response Procedures',
                'description' => 'Financial entities shall establish detailed ICT incident response procedures with clear escalation paths.',
                'category' => 'ICT Risk Management RTS',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.24', '5.25'],
                    'incident_management' => true,
                ],
            ],

            // RTS on Harmonization of Conditions for Incident Reporting
            [
                'id' => 'DORA-RTS-2.1',
                'title' => 'Incident Classification Criteria',
                'description' => 'Financial entities shall classify incidents based on criticality levels using harmonized criteria (critical, major, significant).',
                'category' => 'Incident Reporting RTS',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.24', '5.25'],
                    'incident_management' => true,
                ],
            ],
            [
                'id' => 'DORA-RTS-2.2',
                'title' => 'Significant Cyber Threat Reporting',
                'description' => 'Financial entities shall report significant cyber threats to competent authorities using standardized templates.',
                'category' => 'Incident Reporting RTS',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.26'],
                    'incident_management' => true,
                ],
            ],
            [
                'id' => 'DORA-RTS-2.3',
                'title' => 'Incident Reporting Timeline Compliance',
                'description' => 'Financial entities shall adhere to strict timelines: initial notification (4h for critical), intermediate report (72h), final report (1 month).',
                'category' => 'Incident Reporting RTS',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.26'],
                    'incident_management' => true,
                ],
            ],
            [
                'id' => 'DORA-RTS-2.4',
                'title' => 'Root Cause Analysis Requirements',
                'description' => 'Financial entities shall conduct thorough root cause analysis for all major incidents and document findings.',
                'category' => 'Incident Reporting RTS',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.28'],
                    'incident_management' => true,
                ],
            ],

            // RTS on Digital Operational Resilience Testing
            [
                'id' => 'DORA-RTS-3.1',
                'title' => 'Testing Program Components',
                'description' => 'Financial entities shall implement a comprehensive testing program including vulnerability assessments, security scans, and penetration testing.',
                'category' => 'Testing RTS',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.8', '8.29'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'DORA-RTS-3.2',
                'title' => 'TLPT Framework Implementation',
                'description' => 'Significant financial entities shall implement Threat-Led Penetration Testing (TLPT) framework based on TIBER-EU or equivalent.',
                'category' => 'Testing RTS',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.8'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'DORA-RTS-3.3',
                'title' => 'Testing Frequency Requirements',
                'description' => 'Financial entities shall conduct testing at appropriate frequencies based on risk assessment (minimum annually for critical systems).',
                'category' => 'Testing RTS',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.8'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'DORA-RTS-3.4',
                'title' => 'Test Result Documentation',
                'description' => 'Financial entities shall document all testing activities, findings, and remediation actions.',
                'category' => 'Testing RTS',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.8'],
                    'audit_evidence' => true,
                ],
            ],

            // RTS on ICT Third-Party Risk Management
            [
                'id' => 'DORA-RTS-4.1',
                'title' => 'ICT Third-Party Due Diligence',
                'description' => 'Financial entities shall conduct comprehensive due diligence before engaging ICT third-party service providers.',
                'category' => 'Third-Party Risk RTS',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19', '5.20'],
                ],
            ],
            [
                'id' => 'DORA-RTS-4.2',
                'title' => 'Continuous Monitoring of ICT Third Parties',
                'description' => 'Financial entities shall implement continuous monitoring of ICT third-party service providers\' performance and risk posture.',
                'category' => 'Third-Party Risk RTS',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19', '5.21'],
                ],
            ],
            [
                'id' => 'DORA-RTS-4.3',
                'title' => 'ICT Third-Party Exit Strategies',
                'description' => 'Financial entities shall define and maintain exit strategies for all ICT third-party arrangements.',
                'category' => 'Third-Party Risk RTS',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19', '5.23'],
                ],
            ],
            [
                'id' => 'DORA-RTS-4.4',
                'title' => 'Sub-contracting Arrangements',
                'description' => 'Financial entities shall ensure ICT third-party service providers notify and obtain approval for material sub-contracting.',
                'category' => 'Third-Party Risk RTS',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19', '5.22'],
                ],
            ],
            [
                'id' => 'DORA-RTS-4.5',
                'title' => 'Register of ICT Third-Party Information',
                'description' => 'Financial entities shall maintain an up-to-date register of all ICT third-party arrangements with detailed information.',
                'category' => 'Third-Party Risk RTS',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19'],
                    'audit_evidence' => true,
                ],
            ],

            // RTS on Criteria for Critical ICT Third-Party Service Providers
            [
                'id' => 'DORA-RTS-5.1',
                'title' => 'Criticality Assessment of ICT Third Parties',
                'description' => 'Financial entities shall assess and document the criticality of ICT third-party service providers based on systemically important functions.',
                'category' => 'Critical Third Parties RTS',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19'],
                ],
            ],
            [
                'id' => 'DORA-RTS-5.2',
                'title' => 'Enhanced Oversight for Critical Providers',
                'description' => 'Financial entities shall apply enhanced oversight measures for critical ICT third-party service providers.',
                'category' => 'Critical Third Parties RTS',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19', '5.21'],
                ],
            ],

            // Additional Specific Technical Requirements
            [
                'id' => 'DORA-RTS-6.1',
                'title' => 'Backup Strategy Requirements',
                'description' => 'Financial entities shall implement 3-2-1 backup strategy: 3 copies, 2 different media, 1 offsite, with immutable backups for critical data.',
                'category' => 'Technical Requirements RTS',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.13'],
                    'bcm_required' => true,
                ],
            ],
            [
                'id' => 'DORA-RTS-6.2',
                'title' => 'Zero Trust Architecture Implementation',
                'description' => 'Financial entities shall implement zero trust security principles including least privilege access and continuous verification.',
                'category' => 'Technical Requirements RTS',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.15', '5.18', '8.2'],
                ],
            ],
            [
                'id' => 'DORA-RTS-6.3',
                'title' => 'Security Information and Event Management (SIEM)',
                'description' => 'Financial entities shall implement SIEM systems with correlation capabilities and automated alerting.',
                'category' => 'Technical Requirements RTS',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.15', '8.16'],
                ],
            ],
            [
                'id' => 'DORA-RTS-6.4',
                'title' => 'Endpoint Detection and Response (EDR)',
                'description' => 'Financial entities shall deploy EDR solutions on all endpoints with centralized monitoring and automated response capabilities.',
                'category' => 'Technical Requirements RTS',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.7', '8.8'],
                ],
            ],
            [
                'id' => 'DORA-RTS-6.5',
                'title' => 'Network Segmentation Requirements',
                'description' => 'Financial entities shall implement network segmentation with DMZ, internal zones, and critical asset isolation.',
                'category' => 'Technical Requirements RTS',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.20', '8.21'],
                ],
            ],
            [
                'id' => 'DORA-RTS-6.6',
                'title' => 'Privileged Access Management (PAM)',
                'description' => 'Financial entities shall implement PAM solutions with session recording, just-in-time access, and credential vaulting.',
                'category' => 'Technical Requirements RTS',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.2', '8.3'],
                ],
            ],
            [
                'id' => 'DORA-RTS-6.7',
                'title' => 'Vulnerability Management Program',
                'description' => 'Financial entities shall maintain a vulnerability management program with asset inventory, scanning, prioritization, and remediation tracking.',
                'category' => 'Technical Requirements RTS',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.8'],
                ],
            ],
            [
                'id' => 'DORA-RTS-6.8',
                'title' => 'Patch Management Timeline',
                'description' => 'Financial entities shall apply critical patches within 14 days and high-priority patches within 30 days of availability.',
                'category' => 'Technical Requirements RTS',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.19'],
                ],
            ],

            // Information Sharing Requirements
            [
                'id' => 'DORA-RTS-7.1',
                'title' => 'Cyber Threat Information Sharing',
                'description' => 'Financial entities shall participate in cyber threat information sharing arrangements with relevant authorities and peer institutions.',
                'category' => 'Information Sharing RTS',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.7'],
                ],
            ],
            [
                'id' => 'DORA-RTS-7.2',
                'title' => 'Threat Intelligence Integration',
                'description' => 'Financial entities shall integrate external threat intelligence feeds into their security monitoring and detection systems.',
                'category' => 'Information Sharing RTS',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.16'],
                ],
            ],
        ];
    }
}
