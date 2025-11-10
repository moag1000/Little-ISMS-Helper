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
    name: 'app:load-gdpr-requirements',
    description: 'Load GDPR (General Data Protection Regulation) requirements with ISMS data mappings'
)]
class LoadGdprRequirementsCommand extends Command
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Create or get GDPR framework
        $framework = $this->entityManager->getRepository(ComplianceFramework::class)
            ->findOneBy(['code' => 'GDPR']);

        if (!$framework) {
            $framework = new ComplianceFramework();
            $framework->setCode('GDPR')
                ->setName('GDPR (General Data Protection Regulation)')
                ->setDescription('EU regulation on data protection and privacy')
                ->setVersion('2016/679')
                ->setApplicableIndustry('all_sectors')
                ->setRegulatoryBody('European Union')
                ->setMandatory(true)
                ->setScopeDescription('Applies to all organizations processing personal data of EU residents')
                ->setActive(true);

            $this->entityManager->persist($framework);
        } else {
            // Framework exists - check if requirements are already loaded
            $existingRequirements = $this->entityManager
                ->getRepository(ComplianceRequirement::class)
                ->findBy(['framework' => $framework]);

            if (!empty($existingRequirements)) {
                $io->warning(sprintf(
                    'Framework GDPR already has %d requirements loaded. Skipping to avoid duplicates.',
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

            $requirements = $this->getGdprRequirements();

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

            $io->success(sprintf('Successfully loaded %d GDPR requirements', count($requirements)));
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            $io->error('Failed to load GDPR requirements: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function getGdprRequirements(): array
    {
        return [
            // Chapter 2: Principles
            [
                'id' => 'GDPR-5.1.a',
                'title' => 'Lawfulness, Fairness and Transparency',
                'description' => 'Personal data shall be processed lawfully, fairly and in a transparent manner.',
                'category' => 'Principles',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'GDPR-5.1.b',
                'title' => 'Purpose Limitation',
                'description' => 'Personal data shall be collected for specified, explicit and legitimate purposes.',
                'category' => 'Principles',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'GDPR-5.1.c',
                'title' => 'Data Minimisation',
                'description' => 'Personal data shall be adequate, relevant and limited to what is necessary.',
                'category' => 'Principles',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                    'asset_types' => ['data'],
                ],
            ],
            [
                'id' => 'GDPR-5.1.d',
                'title' => 'Accuracy',
                'description' => 'Personal data shall be accurate and kept up to date.',
                'category' => 'Principles',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.3'],
                    'asset_types' => ['data'],
                ],
            ],
            [
                'id' => 'GDPR-5.1.e',
                'title' => 'Storage Limitation',
                'description' => 'Personal data shall be kept in a form which permits identification for no longer than necessary.',
                'category' => 'Principles',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.10'],
                ],
            ],
            [
                'id' => 'GDPR-5.1.f',
                'title' => 'Integrity and Confidentiality',
                'description' => 'Personal data shall be processed in a manner that ensures appropriate security.',
                'category' => 'Principles',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.11', '8.24'],
                ],
            ],

            // Chapter 3: Rights of the Data Subject
            [
                'id' => 'GDPR-12',
                'title' => 'Transparent Information and Communication',
                'description' => 'The controller shall take appropriate measures to provide information to data subjects in a concise, transparent, intelligible and easily accessible form.',
                'category' => 'Data Subject Rights',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'GDPR-15',
                'title' => 'Right of Access',
                'description' => 'The data subject shall have the right to obtain confirmation as to whether personal data concerning them is being processed.',
                'category' => 'Data Subject Rights',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                ],
            ],
            [
                'id' => 'GDPR-16',
                'title' => 'Right to Rectification',
                'description' => 'The data subject shall have the right to obtain rectification of inaccurate personal data.',
                'category' => 'Data Subject Rights',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                ],
            ],
            [
                'id' => 'GDPR-17',
                'title' => 'Right to Erasure (Right to be Forgotten)',
                'description' => 'The data subject shall have the right to obtain the erasure of personal data under certain circumstances.',
                'category' => 'Data Subject Rights',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34', '8.10'],
                ],
            ],
            [
                'id' => 'GDPR-18',
                'title' => 'Right to Restriction of Processing',
                'description' => 'The data subject shall have the right to obtain restriction of processing under certain circumstances.',
                'category' => 'Data Subject Rights',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                ],
            ],
            [
                'id' => 'GDPR-20',
                'title' => 'Right to Data Portability',
                'description' => 'The data subject shall have the right to receive their personal data in a structured, commonly used and machine-readable format.',
                'category' => 'Data Subject Rights',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                ],
            ],

            // Chapter 4: Controller and Processor
            [
                'id' => 'GDPR-24',
                'title' => 'Responsibility of the Controller',
                'description' => 'The controller shall implement appropriate technical and organisational measures to ensure compliance.',
                'category' => 'Controller Obligations',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.1', '5.2'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'GDPR-25',
                'title' => 'Data Protection by Design and by Default',
                'description' => 'The controller shall implement appropriate technical and organisational measures to ensure data protection principles.',
                'category' => 'Controller Obligations',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.25', '8.26'],
                ],
            ],
            [
                'id' => 'GDPR-28',
                'title' => 'Processor Requirements',
                'description' => 'Processing by a processor shall be governed by a contract or other legal act.',
                'category' => 'Processor Obligations',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.19', '5.20'],
                ],
            ],
            [
                'id' => 'GDPR-30',
                'title' => 'Records of Processing Activities',
                'description' => 'Each controller shall maintain a record of processing activities under its responsibility.',
                'category' => 'Documentation',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'GDPR-32',
                'title' => 'Security of Processing',
                'description' => 'The controller and processor shall implement appropriate technical and organisational measures to ensure a level of security appropriate to the risk.',
                'category' => 'Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.1', '8.11', '8.24'],
                ],
            ],
            [
                'id' => 'GDPR-32.1.a',
                'title' => 'Pseudonymisation and Encryption',
                'description' => 'Controllers shall implement pseudonymisation and encryption of personal data where appropriate.',
                'category' => 'Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.11', '8.24'],
                ],
            ],
            [
                'id' => 'GDPR-32.1.b',
                'title' => 'Ongoing Confidentiality, Integrity, Availability',
                'description' => 'Controllers shall ensure ongoing confidentiality, integrity, availability and resilience of processing systems.',
                'category' => 'Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.1', '8.13', '5.30'],
                    'bcm_required' => true,
                ],
            ],
            [
                'id' => 'GDPR-32.1.c',
                'title' => 'Ability to Restore Availability',
                'description' => 'Controllers shall have the ability to restore availability and access to personal data in a timely manner.',
                'category' => 'Security',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.13', '5.30'],
                    'bcm_required' => true,
                ],
            ],
            [
                'id' => 'GDPR-32.1.d',
                'title' => 'Regular Testing and Evaluation',
                'description' => 'Controllers shall regularly test, assess and evaluate the effectiveness of technical and organisational measures.',
                'category' => 'Security',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['8.8'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'GDPR-33',
                'title' => 'Notification of Personal Data Breach to Supervisory Authority',
                'description' => 'In case of a personal data breach, the controller shall notify the supervisory authority within 72 hours.',
                'category' => 'Breach Notification',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.26'],
                    'incident_management' => true,
                ],
            ],
            [
                'id' => 'GDPR-34',
                'title' => 'Communication of Personal Data Breach to Data Subject',
                'description' => 'When a breach is likely to result in a high risk to individuals, the controller shall communicate the breach to affected data subjects.',
                'category' => 'Breach Notification',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.26'],
                    'incident_management' => true,
                ],
            ],
            [
                'id' => 'GDPR-35',
                'title' => 'Data Protection Impact Assessment',
                'description' => 'Where processing is likely to result in high risk, the controller shall carry out a data protection impact assessment.',
                'category' => 'Risk Assessment',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.7'],
                    'audit_evidence' => true,
                ],
            ],
            [
                'id' => 'GDPR-37',
                'title' => 'Designation of Data Protection Officer',
                'description' => 'The controller and processor shall designate a data protection officer in certain circumstances.',
                'category' => 'DPO',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.3'],
                ],
            ],
            [
                'id' => 'GDPR-44',
                'title' => 'General Principle for International Transfers',
                'description' => 'Any transfer of personal data to a third country shall only take place if certain conditions are met.',
                'category' => 'International Transfers',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.14'],
                ],
            ],
            [
                'id' => 'GDPR-46',
                'title' => 'Transfers Subject to Appropriate Safeguards',
                'description' => 'International transfers may be made with appropriate safeguards such as standard contractual clauses.',
                'category' => 'International Transfers',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.14'],
                ],
            ],
        ];
    }
}
