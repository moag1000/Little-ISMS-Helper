<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\Option;
use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:load-eu-ai-act-requirements',
    description: 'Load EU AI Act (Regulation EU 2024/1689) compliance requirements with ISO 27001 control mappings'
)]
class LoadEuAiActRequirementsCommand
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function __invoke(
        #[Option(name: 'update', shortcut: 'u', description: 'Update existing requirements instead of skipping them')]
        bool $update = false,
        ?SymfonyStyle $symfonyStyle = null,
    ): int {
        $updateMode = $update;

        $symfonyStyle->title('Loading EU AI Act Requirements');
        $symfonyStyle->text(sprintf('Mode: %s', $updateMode ? 'UPDATE existing' : 'CREATE new (skip existing)'));

        // Create or get EU AI Act framework
        $framework = $this->entityManager->getRepository(ComplianceFramework::class)
            ->findOneBy(['code' => 'EU-AI-ACT']);

        if (!$framework instanceof ComplianceFramework) {
            $framework = new ComplianceFramework();
            $framework->setCode('EU-AI-ACT')
                ->setName('EU AI Act (Regulation (EU) 2024/1689)')
                ->setDescription('Regulation (EU) 2024/1689 of the European Parliament and Council laying down harmonised rules on artificial intelligence. Published 13 June 2024 in the Official Journal; entered into force 1 August 2024. Phased application: prohibited practices from 2 February 2025; GPAI obligations from 2 August 2025; high-risk AI system obligations from 2 August 2026; full applicability 2 August 2027.')
                ->setVersion('(EU) 2024/1689')
                ->setApplicableIndustry('all')
                ->setRegulatoryBody('European Union (Council and Parliament)')
                ->setMandatory(true)
                ->setScopeDescription('Mandatory for AI providers, deployers, importers, distributors, product manufacturers and authorised representatives operating in the EU. Extraterritorial scope where AI output is used in the EU.')
                ->setActive(true);

            $this->entityManager->persist($framework);
            $symfonyStyle->text('Created framework');
        } else {
            $symfonyStyle->text('Framework exists');
        }

        $requirements = $this->getEuAiActRequirements();
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        foreach ($requirements as $reqData) {
            $existing = $this->entityManager->getRepository(ComplianceRequirement::class)
                ->findOneBy([
                    'complianceFramework' => $framework,
                    'requirementId' => $reqData['id'],
                ]);

            if ($existing instanceof ComplianceRequirement) {
                if ($updateMode) {
                    $existing->setTitle($reqData['title'])
                        ->setDescription($reqData['description'])
                        ->setCategory($reqData['category'])
                        ->setPriority($reqData['priority'])
                        ->setDataSourceMapping($reqData['data_source_mapping']);
                    $stats['updated']++;
                } else {
                    $stats['skipped']++;
                }
            } else {
                $requirement = new ComplianceRequirement();
                $requirement->setFramework($framework)
                    ->setRequirementId($reqData['id'])
                    ->setTitle($reqData['title'])
                    ->setDescription($reqData['description'])
                    ->setCategory($reqData['category'])
                    ->setPriority($reqData['priority'])
                    ->setDataSourceMapping($reqData['data_source_mapping']);

                $this->entityManager->persist($requirement);
                $stats['created']++;
            }
        }

        $this->entityManager->flush();

        $symfonyStyle->success('EU AI Act requirements loaded!');
        $symfonyStyle->table(
            ['Action', 'Count'],
            [
                ['Created', $stats['created']],
                ['Updated', $stats['updated']],
                ['Skipped', $stats['skipped']],
                ['Total', count($requirements)],
            ]
        );

        return Command::SUCCESS;
    }

    private function getEuAiActRequirements(): array
    {
        return [
            [
                'id' => 'AIACT-1',
                'title' => 'AI Risk Classification',
                'description' => 'Classify AI systems by risk level (unacceptable, high, limited, minimal) per Art. 6. Organizations must assess and document the risk category of each AI system before deployment.',
                'category' => 'Risk Management',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['5.8']],
            ],
            [
                'id' => 'AIACT-2',
                'title' => 'High-Risk AI Requirements',
                'description' => 'Establish and maintain a risk management system for high-risk AI systems per Art. 9. Must include identification, analysis, estimation, and evaluation of risks throughout the AI system lifecycle.',
                'category' => 'Risk Management',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['5.8', '8.25']],
            ],
            [
                'id' => 'AIACT-3',
                'title' => 'Data Governance',
                'description' => 'Ensure training, validation, and testing data quality requirements per Art. 10. Data sets must be relevant, sufficiently representative, free of errors, and complete for the intended purpose.',
                'category' => 'Data Quality',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['5.9', '5.12', '8.10']],
            ],
            [
                'id' => 'AIACT-4',
                'title' => 'Technical Documentation',
                'description' => 'Document AI system design, development, and capabilities per Art. 11. Documentation must demonstrate compliance with AI Act requirements and enable conformity assessment.',
                'category' => 'Documentation',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['5.37', '7.5']],
            ],
            [
                'id' => 'AIACT-5',
                'title' => 'Transparency Obligations',
                'description' => 'Ensure users are informed when interacting with AI systems per Art. 13/52. High-risk AI systems must provide sufficient transparency for users to interpret output and use it appropriately.',
                'category' => 'Transparency',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['5.10', '5.34']],
            ],
            [
                'id' => 'AIACT-6',
                'title' => 'Human Oversight',
                'description' => 'High-risk AI systems must be designed to allow effective human oversight per Art. 14. Include appropriate human-machine interface tools enabling natural persons to oversee the AI system.',
                'category' => 'Governance',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['5.3']],
            ],
            [
                'id' => 'AIACT-7',
                'title' => 'Accuracy, Robustness, and Cybersecurity',
                'description' => 'Achieve appropriate levels of accuracy, robustness, and cybersecurity per Art. 15. High-risk AI systems must be resilient against errors, faults, inconsistencies, and attempts at manipulation.',
                'category' => 'Technical Requirements',
                'priority' => 'critical',
                'data_source_mapping' => ['iso_controls' => ['8.25', '8.29', '8.8']],
            ],
            [
                'id' => 'AIACT-8',
                'title' => 'Conformity Assessment',
                'description' => 'Complete conformity assessment before placing high-risk AI on market per Art. 43. Must verify compliance with all applicable requirements and maintain assessment documentation.',
                'category' => 'Compliance',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['5.35', '5.36']],
            ],
            [
                'id' => 'AIACT-9',
                'title' => 'Post-Market Monitoring',
                'description' => 'Establish and document a post-market monitoring system for high-risk AI per Art. 72. Actively and systematically collect, document, and analyze relevant data throughout the AI system lifetime.',
                'category' => 'Monitoring',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['8.16', '5.24']],
            ],
            [
                'id' => 'AIACT-10',
                'title' => 'GPAI Model Obligations',
                'description' => 'General-purpose AI model providers must ensure transparency per Art. 53. Maintain technical documentation, provide information to downstream providers, and comply with copyright law.',
                'category' => 'Transparency',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['5.37']],
            ],
        ];
    }
}
