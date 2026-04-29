<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Load ISO/IEC 27005:2022 (Information security risk management) core clauses.
 * Used as the risk-management hub for DORA Art. 5-9, NIS2 Art. 21 risk provisions,
 * and TISAX risk-related controls.
 */
#[AsCommand(
    name: 'app:load-iso27005-requirements',
    description: 'Load ISO/IEC 27005:2022 Information Security Risk Management clauses'
)]
final class LoadIso27005RequirementsCommand
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $framework = $this->entityManager->getRepository(ComplianceFramework::class)
            ->findOneBy(['code' => 'ISO27005']);
        $isNew = !$framework instanceof ComplianceFramework;
        if ($isNew) {
            $framework = new ComplianceFramework();
        }
        $framework->setCode('ISO27005')
            ->setName('ISO/IEC 27005:2022 - Information Security Risk Management')
            ->setDescription('Guidance for information security risk management, aligned with ISO 27001:2022. Primary risk-management reference for ISMS, NIS2, DORA, TISAX.')
            ->setVersion('2022')
            ->setApplicableIndustry('all_sectors')
            ->setRegulatoryBody('ISO/IEC')
            ->setMandatory(false)
            ->setScopeDescription('Provides normative risk-management process applicable to any ISMS.')
            ->setActive(true);

        if ($isNew) {
            $this->entityManager->persist($framework);
            $io->text('Created ISO 27005 framework');
        }

        foreach ($this->requirements() as $data) {
            $existing = $this->entityManager->getRepository(ComplianceRequirement::class)
                ->findOneBy(['framework' => $framework, 'requirementId' => $data['id']]);
            if ($existing instanceof ComplianceRequirement) {
                continue;
            }
            $requirement = (new ComplianceRequirement())
                ->setFramework($framework)
                ->setRequirementId($data['id'])
                ->setTitle($data['title'])
                ->setDescription($data['description'])
                ->setCategory($data['category'])
                ->setPriority($data['priority'])
                ->setDataSourceMapping(['iso_controls' => $data['iso_controls']]);
            $this->entityManager->persist($requirement);
        }
        $this->entityManager->flush();

        $io->success('ISO 27005:2022 core clauses loaded.');
        return Command::SUCCESS;
    }

    private function requirements(): array
    {
        return [
            ['id' => '5', 'title' => 'Information security risk management process', 'description' => 'Systematic application of management policies, procedures and practices to the activities of communicating, consulting, establishing the context, identifying, analysing, evaluating, treating, monitoring and reviewing risk.', 'category' => 'Risk Management Process', 'priority' => 'critical', 'iso_controls' => ['6.1.2', '6.1.3']],
            ['id' => '6', 'title' => 'Context establishment', 'description' => 'Define scope, boundaries, risk criteria (likelihood, impact, risk-acceptance), stakeholders, and internal/external issues for the risk management process.', 'category' => 'Context Establishment', 'priority' => 'critical', 'iso_controls' => ['4.1', '4.2', '4.3']],
            ['id' => '7', 'title' => 'Information security risk assessment', 'description' => 'Identification, analysis and evaluation of information security risks; uses an event-based or asset-based approach.', 'category' => 'Risk Assessment', 'priority' => 'critical', 'iso_controls' => ['6.1.2']],
            ['id' => '7.2', 'title' => 'Risk identification', 'description' => 'Identify threats, vulnerabilities, existing controls, consequences and assets in scope.', 'category' => 'Risk Assessment', 'priority' => 'critical', 'iso_controls' => ['6.1.2', 'A.5.7', 'A.5.9']],
            ['id' => '7.3', 'title' => 'Risk analysis', 'description' => 'Determine likelihood and impact of identified risks using defined criteria.', 'category' => 'Risk Assessment', 'priority' => 'critical', 'iso_controls' => ['6.1.2']],
            ['id' => '7.4', 'title' => 'Risk evaluation', 'description' => 'Compare risk-analysis results with the risk-acceptance criteria; prioritise risks for treatment.', 'category' => 'Risk Assessment', 'priority' => 'critical', 'iso_controls' => ['6.1.2']],
            ['id' => '8', 'title' => 'Information security risk treatment', 'description' => 'Selection of treatment options (modification, retention, avoidance, sharing), determination of controls, statement of applicability and risk-treatment plan.', 'category' => 'Risk Treatment', 'priority' => 'critical', 'iso_controls' => ['6.1.3']],
            ['id' => '8.2', 'title' => 'Risk treatment options', 'description' => 'Select one or more options: modify the risk, retain the risk, avoid the risk, share the risk with external parties.', 'category' => 'Risk Treatment', 'priority' => 'high', 'iso_controls' => ['6.1.3']],
            ['id' => '8.3', 'title' => 'Residual risk acceptance', 'description' => 'Formal acceptance of residual risks by risk owner including justification for any exceedance of criteria.', 'category' => 'Risk Treatment', 'priority' => 'high', 'iso_controls' => ['6.1.3']],
            ['id' => '9', 'title' => 'Risk communication and consultation', 'description' => 'Continuous exchange of information about risk and its management with internal and external stakeholders.', 'category' => 'Communication', 'priority' => 'high', 'iso_controls' => ['7.4']],
            ['id' => '10', 'title' => 'Risk monitoring and review', 'description' => 'Monitor risks, controls, residual risks and the effectiveness of the risk-management process; review on a defined cadence.', 'category' => 'Monitoring', 'priority' => 'high', 'iso_controls' => ['9.1', '9.3']],
        ];
    }
}
