<?php

namespace App\Command;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:load-bdsg-requirements',
    description: 'Load BDSG (Bundesdatenschutzgesetz) requirements for German-specific data protection with ISO 27001 control mappings'
)]
class LoadBdsgRequirementsCommand
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function __invoke(
        #[Option(name: 'update', shortcut: 'u', description: 'Update existing requirements instead of skipping them')]
        bool $update = false,
        ?SymfonyStyle $symfonyStyle = null
    ): int {
        $updateMode = $update;

        $symfonyStyle->title('Loading BDSG Requirements');
        $symfonyStyle->text(sprintf('Mode: %s', $updateMode ? 'UPDATE existing' : 'CREATE new (skip existing)'));

        // Create or get BDSG framework
        $framework = $this->entityManager->getRepository(ComplianceFramework::class)
            ->findOneBy(['code' => 'BDSG']);
        $isNew = !$framework instanceof ComplianceFramework;
        if ($isNew) {
            $framework = new ComplianceFramework();
        }
        $framework->setCode('BDSG')
            ->setName('Bundesdatenschutzgesetz (BDSG)')
            ->setDescription('German Federal Data Protection Act - German-specific data protection requirements beyond GDPR')
            ->setVersion('2018/2024')
            ->setApplicableIndustry('all')
            ->setRegulatoryBody('BfDI (Bundesbeauftragter für den Datenschutz und die Informationsfreiheit)')
            ->setMandatory(true)
            ->setScopeDescription('Mandatory for all organizations processing personal data in Germany, complementing EU GDPR with German-specific provisions')
            ->setActive(true);

        if ($isNew) {
            $this->entityManager->persist($framework);
            $symfonyStyle->text('✓ Created framework');
        } else {
            $symfonyStyle->text('✓ Framework exists');
        }

        $requirements = $this->getBdsgRequirements();
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        foreach ($requirements as $reqData) {
            $existing = $this->entityManager->getRepository(ComplianceRequirement::class)
                ->findOneBy([
                    'complianceFramework' => $framework,
                    'requirementId' => $reqData['id']
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

            // Batch flush
            if (($stats['created'] + $stats['updated']) % 50 === 0) {
                $this->entityManager->flush();
            }
        }

        $this->entityManager->flush();

        $symfonyStyle->success('BDSG requirements loaded!');
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

    private function getBdsgRequirements(): array
    {
        return [
            [
                'id' => 'BDSG-1',
                'title' => 'Scope of application (§1)',
                'description' => 'Scope and applicability of the BDSG, including non-automated processing not covered by GDPR scope.',
                'category' => 'Scope',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['4.3'], 'legal_requirement' => 'BDSG §1', 'gdpr_relevant' => true],
            ],
            [
                'id' => 'BDSG-31',
                'title' => 'Scoring and credit information (§31)',
                'description' => 'German concretisation of GDPR Art. 22 on automated decision-making, specifically for scoring and credit reporting.',
                'category' => 'Automated Decision Making',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['8.2'], 'legal_requirement' => 'BDSG §31', 'gdpr_relevant' => true],
            ],
            [
                'id' => 'BDSG-34',
                'title' => 'Right of access restrictions (§34)',
                'description' => 'Defines national restrictions to the GDPR Art. 15 right of access, e.g. for journalism and archival purposes.',
                'category' => 'Data Subject Rights',
                'priority' => 'high',
                'data_source_mapping' => ['iso_controls' => ['5.34'], 'legal_requirement' => 'BDSG §34', 'gdpr_relevant' => true],
            ],
            [
                'id' => 'BDSG-45',
                'title' => 'Special provisions for criminal prosecution (§45)',
                'description' => 'Transposes GDPR provisions to processing for criminal-prosecution purposes under national law.',
                'category' => 'Criminal Matters',
                'priority' => 'medium',
                'data_source_mapping' => ['iso_controls' => ['5.31'], 'legal_requirement' => 'BDSG §45'],
            ],
            [
                'id' => 'BDSG-48',
                'title' => 'Processing for criminal-prosecution purposes (§48)',
                'description' => 'Specific lawful-basis rules for public criminal-prosecution processing.',
                'category' => 'Criminal Matters',
                'priority' => 'medium',
                'data_source_mapping' => ['iso_controls' => ['5.31'], 'legal_requirement' => 'BDSG §48'],
            ],
            [
                'id' => 'BDSG-22',
                'title' => 'Processing of special categories (§22)',
                'description' => 'Additional conditions for processing Art. 9 data under German law.',
                'category' => 'Special Categories',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34', '5.12'],
                    'legal_requirement' => 'BDSG §22',
                    'gdpr_relevant' => true,
                ],
            ],
            [
                'id' => 'BDSG-26',
                'title' => 'Employee data processing (§26)',
                'description' => 'Processing of employee personal data for employment purposes.',
                'category' => 'Employment Data',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34', '6.1', '6.2'],
                    'legal_requirement' => 'BDSG §26',
                    'gdpr_relevant' => true,
                ],
            ],
            [
                'id' => 'BDSG-35',
                'title' => 'Right to erasure restrictions (§35)',
                'description' => 'German-specific restrictions on erasure obligations.',
                'category' => 'Data Subject Rights',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.34', '8.10'],
                    'legal_requirement' => 'BDSG §35',
                    'gdpr_relevant' => true,
                ],
            ],
            [
                'id' => 'BDSG-38',
                'title' => 'Data Protection Officer appointment (§38)',
                'description' => 'DPO required when 20 or more persons regularly process personal data.',
                'category' => 'Organization',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['5.2', '5.4'],
                    'legal_requirement' => 'BDSG §38',
                    'gdpr_relevant' => true,
                ],
            ],
            [
                'id' => 'BDSG-42',
                'title' => 'Criminal liability (§42)',
                'description' => 'Penalties for unauthorized commercial data processing.',
                'category' => 'Enforcement',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.31', '5.34'],
                    'legal_requirement' => 'BDSG §42',
                ],
            ],
            [
                'id' => 'BDSG-43',
                'title' => 'Administrative fines (§43)',
                'description' => 'German-specific fine regulations complementing GDPR Art. 83.',
                'category' => 'Enforcement',
                'priority' => 'high',
                'data_source_mapping' => [
                    'iso_controls' => ['5.31'],
                    'legal_requirement' => 'BDSG §43',
                    'gdpr_relevant' => true,
                ],
            ],
            [
                'id' => 'BDSG-64',
                'title' => 'Technical and organizational measures (§64)',
                'description' => 'Requirements for automated processing by public bodies.',
                'category' => 'Technical Measures',
                'priority' => 'critical',
                'data_source_mapping' => [
                    'iso_controls' => ['8.1', '8.5', '8.15'],
                    'legal_requirement' => 'BDSG §64',
                ],
            ],
        ];
    }
}
