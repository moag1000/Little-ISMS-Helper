<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Service\Compliance\FrameworkLoaderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
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
final class LoadIso27005RequirementsCommand extends Command implements FrameworkLoaderInterface
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    public function getFrameworkCode(): string
    {
        return 'ISO27005';
    }

    public function loadRequirements(bool $update = false, ?SymfonyStyle $io = null): int
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
            $io?->text('Created ISO 27005 framework');
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

        $io?->success('ISO 27005:2022 core clauses loaded.');
        return Command::SUCCESS;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->loadRequirements(false, new SymfonyStyle($input, $output));
    }

    private function requirements(): array
    {
        return [
            // Clause 5 — Process overview
            ['id' => '5', 'title' => 'Information security risk management process', 'description' => 'Systematic application of management policies, procedures and practices to the activities of communicating, consulting, establishing the context, identifying, analysing, evaluating, treating, monitoring and reviewing risk.', 'category' => 'Risk Management Process', 'priority' => 'critical', 'iso_controls' => ['6.1.2', '6.1.3']],

            // Clause 6 — Context establishment
            ['id' => '6', 'title' => 'Context establishment', 'description' => 'Define scope, boundaries, risk criteria (likelihood, impact, risk-acceptance), stakeholders, and internal/external issues for the risk management process.', 'category' => 'Context Establishment', 'priority' => 'critical', 'iso_controls' => ['4.1', '4.2', '4.3']],
            ['id' => '6.1', 'title' => 'General considerations for context establishment', 'description' => 'Determine the internal and external context relevant to the organisation, and identify how the ISMS scope and risk process boundaries interact with strategic and operational objectives.', 'category' => 'Context Establishment', 'priority' => 'critical', 'iso_controls' => ['4.1', '4.3', '6.1.1']],
            ['id' => '6.2', 'title' => 'Risk criteria', 'description' => 'Establish and maintain the criteria used to evaluate the significance of information security risks, covering both risk-acceptance thresholds and consequence/likelihood scales.', 'category' => 'Context Establishment', 'priority' => 'critical', 'iso_controls' => ['6.1.2']],
            ['id' => '6.2.1', 'title' => 'Risk criteria — general', 'description' => 'Define the basis for risk criteria, ensuring alignment with organisational objectives, legal/regulatory obligations and stakeholder expectations; document criteria so assessments are repeatable.', 'category' => 'Context Establishment', 'priority' => 'critical', 'iso_controls' => ['6.1.2']],
            ['id' => '6.2.2', 'title' => 'Selecting the risk assessment approach', 'description' => 'Choose and document an asset-based approach (threats/vulnerabilities to assets) or an event-based approach (scenarios/consequences) — or a combination — and ensure the chosen method is consistently applied.', 'category' => 'Context Establishment', 'priority' => 'high', 'iso_controls' => ['6.1.2']],

            // Clause 7 — Risk assessment
            ['id' => '7', 'title' => 'Information security risk assessment', 'description' => 'Identification, analysis and evaluation of information security risks; uses an event-based or asset-based approach.', 'category' => 'Risk Assessment', 'priority' => 'critical', 'iso_controls' => ['6.1.2']],
            ['id' => '7.1', 'title' => 'Risk assessment — general', 'description' => 'Perform risk assessments at planned intervals and whenever significant changes occur; ensure the process produces consistent, valid and comparable results.', 'category' => 'Risk Assessment', 'priority' => 'critical', 'iso_controls' => ['6.1.2', '8.2']],
            ['id' => '7.2', 'title' => 'Risk identification', 'description' => 'Identify threats, vulnerabilities, existing controls, consequences and assets in scope.', 'category' => 'Risk Assessment', 'priority' => 'critical', 'iso_controls' => ['6.1.2', 'A.5.7', 'A.5.9']],
            ['id' => '7.3', 'title' => 'Risk analysis', 'description' => 'Determine likelihood and impact of identified risks using defined criteria.', 'category' => 'Risk Assessment', 'priority' => 'critical', 'iso_controls' => ['6.1.2']],
            ['id' => '7.4', 'title' => 'Risk evaluation', 'description' => 'Compare risk-analysis results with the risk-acceptance criteria; prioritise risks for treatment.', 'category' => 'Risk Assessment', 'priority' => 'critical', 'iso_controls' => ['6.1.2']],

            // Clause 8 — Risk treatment
            ['id' => '8', 'title' => 'Information security risk treatment', 'description' => 'Selection of treatment options (modification, retention, avoidance, sharing), determination of controls, statement of applicability and risk-treatment plan.', 'category' => 'Risk Treatment', 'priority' => 'critical', 'iso_controls' => ['6.1.3']],
            ['id' => '8.1', 'title' => 'Risk treatment — general', 'description' => 'Determine and apply an appropriate information security risk treatment process; ensure decisions on treatment options are based on the outputs of the risk evaluation step.', 'category' => 'Risk Treatment', 'priority' => 'critical', 'iso_controls' => ['6.1.3', '8.3']],
            ['id' => '8.2', 'title' => 'Risk treatment options', 'description' => 'Select one or more options: modify the risk, retain the risk, avoid the risk, share the risk with external parties.', 'category' => 'Risk Treatment', 'priority' => 'high', 'iso_controls' => ['6.1.3']],
            ['id' => '8.3', 'title' => 'Residual risk acceptance', 'description' => 'Formal acceptance of residual risks by risk owner including justification for any exceedance of criteria.', 'category' => 'Risk Treatment', 'priority' => 'high', 'iso_controls' => ['6.1.3']],
            ['id' => '8.4', 'title' => 'Formulating the information security risk treatment plan', 'description' => 'Document a risk treatment plan that records the selected treatment options, required controls, responsibilities, resource requirements, timescales and how residual risk will be measured; obtain risk-owner approval.', 'category' => 'Risk Treatment', 'priority' => 'high', 'iso_controls' => ['6.1.3', '8.3']],
            ['id' => '8.5', 'title' => 'Acceptance of residual information security risk', 'description' => 'Formally record risk-owner acceptance of the residual risks that remain after applying the planned controls; acceptance must be explicit and traceable when residual risk exceeds the defined risk-acceptance criteria.', 'category' => 'Risk Treatment', 'priority' => 'high', 'iso_controls' => ['6.1.3']],

            // Clause 9 — Communication and consultation
            ['id' => '9', 'title' => 'Risk communication and consultation', 'description' => 'Continuous exchange of information about risk and its management with internal and external stakeholders.', 'category' => 'Communication', 'priority' => 'high', 'iso_controls' => ['7.4']],
            ['id' => '9.1', 'title' => 'Risk communication — general', 'description' => 'Plan and carry out ongoing communication of risk information to relevant stakeholders to ensure that responsibilities are understood and that decisions are based on shared situational awareness.', 'category' => 'Communication', 'priority' => 'high', 'iso_controls' => ['7.4']],
            ['id' => '9.2', 'title' => 'Consultation', 'description' => 'Engage stakeholders (including risk owners, process owners and subject-matter experts) in the risk assessment and treatment steps to improve the quality of decisions and increase commitment to the risk management process.', 'category' => 'Communication', 'priority' => 'medium', 'iso_controls' => ['7.4', '5.4']],

            // Clause 10 — Monitoring and review
            ['id' => '10', 'title' => 'Risk monitoring and review', 'description' => 'Monitor risks, controls, residual risks and the effectiveness of the risk-management process; review on a defined cadence.', 'category' => 'Monitoring', 'priority' => 'high', 'iso_controls' => ['9.1', '9.3']],
            ['id' => '10.1', 'title' => 'Monitoring and review — general', 'description' => 'Establish and maintain a monitoring and review process that ensures the risk management activities remain effective and aligned with the changing business environment, threat landscape and legal obligations.', 'category' => 'Monitoring', 'priority' => 'high', 'iso_controls' => ['9.1', '9.3']],
            ['id' => '10.2', 'title' => 'Monitoring and review of risk factors', 'description' => 'Continuously track changes to assets, threats, vulnerabilities, likelihoods and impacts that could affect the current risk profile; update the risk register when significant changes are detected.', 'category' => 'Monitoring', 'priority' => 'high', 'iso_controls' => ['8.2', '9.1', 'A.5.7']],
            ['id' => '10.3', 'title' => 'Monitoring, review and improvement of the risk management process', 'description' => 'Periodically audit the risk management process itself — including criteria adequacy, methodology consistency and documentation completeness — and implement improvements to increase its maturity and effectiveness.', 'category' => 'Monitoring', 'priority' => 'high', 'iso_controls' => ['9.3', '10.2']],
            ['id' => '10.4', 'title' => 'Review of the information security risk management process', 'description' => 'Conduct scheduled management reviews of the overall ISRM process to verify outputs are fed into management-review inputs, performance is measured against objectives, and lessons learned are incorporated.', 'category' => 'Monitoring', 'priority' => 'medium', 'iso_controls' => ['9.3', '10.1', '10.2']],
        ];
    }
}
