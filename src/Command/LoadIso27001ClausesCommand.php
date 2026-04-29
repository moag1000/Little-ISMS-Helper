<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * H-04: Seeds ISO 27001:2022 Clauses 4-10 (ISMS core clauses, not Annex A).
 * Required for ISO 27001 certification readiness (Context, Leadership, Planning,
 * Support, Operation, Performance Evaluation, Improvement).
 */
#[AsCommand(
    name: 'app:load-iso27001-clauses',
    description: 'Load ISO 27001:2022 Clauses 4-10 (ISMS core clauses) as ComplianceRequirements'
)]
class LoadIso27001ClausesCommand
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function __invoke(#[Option(name: 'update', shortcut: 'u', description: 'Update existing requirements instead of skipping them')]
    bool $update = false, ?SymfonyStyle $symfonyStyle = null): int
    {
        $symfonyStyle?->title('Loading ISO 27001:2022 Clauses 4-10');
        $symfonyStyle?->text(sprintf('Mode: %s', $update ? 'UPDATE existing' : 'CREATE new (skip existing)'));

        $framework = $this->entityManager->getRepository(ComplianceFramework::class)
            ->findOneBy(['code' => 'ISO-27001']);
        if (!$framework instanceof ComplianceFramework) {
            $symfonyStyle?->error('ISO-27001 framework not found. Run app:load-iso27001-requirements first.');
            return Command::FAILURE;
        }

        $requirements = $this->getClauses();
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        foreach ($requirements as $reqData) {
            $existing = $this->entityManager->getRepository(ComplianceRequirement::class)
                ->findOneBy([
                    'framework' => $framework,
                    'requirementId' => $reqData['id'],
                ]);

            if ($existing instanceof ComplianceRequirement) {
                if ($update) {
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
        $symfonyStyle?->success(sprintf(
            'ISO 27001 Clauses 4-10: %d created, %d updated, %d skipped',
            $stats['created'], $stats['updated'], $stats['skipped']
        ));
        return Command::SUCCESS;
    }

    /** @return list<array<string,mixed>> */
    private function getClauses(): array
    {
        return [
            // Clause 4 — Context of the organization
            ['id' => 'ISO27001-4.1', 'title' => 'Understanding the organization and its context', 'description' => 'The organization shall determine external and internal issues relevant to its purpose that affect its ability to achieve the intended outcome(s) of its ISMS.', 'category' => 'Context', 'priority' => 'critical', 'data_source_mapping' => ['entity' => 'ISMSContext']],
            ['id' => 'ISO27001-4.2', 'title' => 'Understanding the needs and expectations of interested parties', 'description' => 'The organization shall determine interested parties relevant to the ISMS and their requirements (including legal, regulatory, contractual).', 'category' => 'Context', 'priority' => 'critical', 'data_source_mapping' => ['entity' => 'InterestedParty']],
            ['id' => 'ISO27001-4.3', 'title' => 'Determining the scope of the information security management system', 'description' => 'The organization shall determine the boundaries and applicability of the ISMS to establish its scope.', 'category' => 'Context', 'priority' => 'critical', 'data_source_mapping' => ['entity' => 'ISMSContext']],
            ['id' => 'ISO27001-4.4', 'title' => 'Information security management system', 'description' => 'The organization shall establish, implement, maintain and continually improve an ISMS, including the processes needed and their interactions.', 'category' => 'Context', 'priority' => 'critical', 'data_source_mapping' => []],

            // Clause 5 — Leadership
            ['id' => 'ISO27001-5.1', 'title' => 'Leadership and commitment', 'description' => 'Top management shall demonstrate leadership and commitment with respect to the ISMS.', 'category' => 'Leadership', 'priority' => 'critical', 'data_source_mapping' => []],
            ['id' => 'ISO27001-5.2', 'title' => 'Information security policy', 'description' => 'Top management shall establish an information security policy that is appropriate to the purpose of the organization.', 'category' => 'Leadership', 'priority' => 'critical', 'data_source_mapping' => ['entity' => 'Document', 'document_type' => 'policy']],
            ['id' => 'ISO27001-5.3', 'title' => 'Organizational roles, responsibilities and authorities', 'description' => 'Top management shall ensure that responsibilities and authorities for roles relevant to information security are assigned and communicated.', 'category' => 'Leadership', 'priority' => 'critical', 'data_source_mapping' => ['entity' => 'Role']],

            // Clause 6 — Planning
            ['id' => 'ISO27001-6.1.1', 'title' => 'General (planning)', 'description' => 'When planning for the ISMS, the organization shall consider issues referred to in 4.1 and requirements referred to in 4.2, and determine risks and opportunities.', 'category' => 'Planning', 'priority' => 'critical', 'data_source_mapping' => []],
            ['id' => 'ISO27001-6.1.2', 'title' => 'Information security risk assessment', 'description' => 'The organization shall define and apply an information security risk assessment process.', 'category' => 'Planning', 'priority' => 'critical', 'data_source_mapping' => ['entity' => 'Risk']],
            ['id' => 'ISO27001-6.1.3', 'title' => 'Information security risk treatment', 'description' => 'The organization shall define and apply an information security risk treatment process and produce a Statement of Applicability (SoA).', 'category' => 'Planning', 'priority' => 'critical', 'data_source_mapping' => ['entity' => 'RiskTreatmentPlan']],
            ['id' => 'ISO27001-6.2', 'title' => 'Information security objectives and planning to achieve them', 'description' => 'The organization shall establish information security objectives at relevant functions and levels.', 'category' => 'Planning', 'priority' => 'critical', 'data_source_mapping' => ['entity' => 'ISMSObjective']],
            ['id' => 'ISO27001-6.3', 'title' => 'Planning of changes', 'description' => 'When the organization determines the need for changes to the ISMS, the changes shall be carried out in a planned manner.', 'category' => 'Planning', 'priority' => 'high', 'data_source_mapping' => ['entity' => 'ChangeRequest']],

            // Clause 7 — Support
            ['id' => 'ISO27001-7.1', 'title' => 'Resources', 'description' => 'The organization shall determine and provide the resources needed for the establishment, implementation, maintenance and continual improvement of the ISMS.', 'category' => 'Support', 'priority' => 'high', 'data_source_mapping' => []],
            ['id' => 'ISO27001-7.2', 'title' => 'Competence', 'description' => 'The organization shall determine the necessary competence of person(s) doing work that affects the ISMS performance, and ensure they are competent.', 'category' => 'Support', 'priority' => 'high', 'data_source_mapping' => ['entity' => 'Training']],
            ['id' => 'ISO27001-7.3', 'title' => 'Awareness', 'description' => 'Persons doing work under the organization\'s control shall be aware of the information security policy, their contribution, and the implications of not conforming.', 'category' => 'Support', 'priority' => 'high', 'data_source_mapping' => ['entity' => 'Training']],
            ['id' => 'ISO27001-7.4', 'title' => 'Communication', 'description' => 'The organization shall determine the need for internal and external communications relevant to the ISMS.', 'category' => 'Support', 'priority' => 'medium', 'data_source_mapping' => []],
            ['id' => 'ISO27001-7.5', 'title' => 'Documented information', 'description' => 'The ISMS shall include documented information required by this document and determined by the organization.', 'category' => 'Support', 'priority' => 'critical', 'data_source_mapping' => ['entity' => 'Document']],

            // Clause 8 — Operation
            ['id' => 'ISO27001-8.1', 'title' => 'Operational planning and control', 'description' => 'The organization shall plan, implement and control the processes needed to meet requirements and implement actions determined in Clause 6.', 'category' => 'Operation', 'priority' => 'critical', 'data_source_mapping' => []],
            ['id' => 'ISO27001-8.2', 'title' => 'Information security risk assessment (operation)', 'description' => 'The organization shall perform information security risk assessments at planned intervals or when significant changes occur.', 'category' => 'Operation', 'priority' => 'critical', 'data_source_mapping' => ['entity' => 'Risk']],
            ['id' => 'ISO27001-8.3', 'title' => 'Information security risk treatment (operation)', 'description' => 'The organization shall implement the information security risk treatment plan.', 'category' => 'Operation', 'priority' => 'critical', 'data_source_mapping' => ['entity' => 'RiskTreatmentPlan']],

            // Clause 9 — Performance evaluation
            ['id' => 'ISO27001-9.1', 'title' => 'Monitoring, measurement, analysis and evaluation', 'description' => 'The organization shall evaluate the information security performance and effectiveness of the ISMS.', 'category' => 'Performance Evaluation', 'priority' => 'critical', 'data_source_mapping' => ['entity' => 'Control', 'context' => 'effectiveness']],
            ['id' => 'ISO27001-9.2.1', 'title' => 'Internal audit — general', 'description' => 'The organization shall conduct internal audits at planned intervals to provide information on whether the ISMS conforms and is effectively implemented.', 'category' => 'Performance Evaluation', 'priority' => 'critical', 'data_source_mapping' => ['entity' => 'InternalAudit']],
            ['id' => 'ISO27001-9.2.2', 'title' => 'Internal audit programme', 'description' => 'The organization shall plan, establish, implement and maintain an audit programme(s).', 'category' => 'Performance Evaluation', 'priority' => 'high', 'data_source_mapping' => ['entity' => 'InternalAudit']],
            ['id' => 'ISO27001-9.3.1', 'title' => 'Management review — general', 'description' => 'Top management shall review the organization\'s ISMS at planned intervals to ensure its continuing suitability, adequacy, and effectiveness.', 'category' => 'Performance Evaluation', 'priority' => 'critical', 'data_source_mapping' => ['entity' => 'ManagementReview']],
            ['id' => 'ISO27001-9.3.2', 'title' => 'Management review inputs', 'description' => 'The management review shall include consideration of status of actions, changes, feedback, audit results, risks and opportunities.', 'category' => 'Performance Evaluation', 'priority' => 'critical', 'data_source_mapping' => ['entity' => 'ManagementReview']],
            ['id' => 'ISO27001-9.3.3', 'title' => 'Management review results', 'description' => 'The results of the management review shall include decisions related to continual improvement opportunities and any need for changes to the ISMS.', 'category' => 'Performance Evaluation', 'priority' => 'critical', 'data_source_mapping' => ['entity' => 'ManagementReview']],

            // Clause 10 — Improvement
            ['id' => 'ISO27001-10.1', 'title' => 'Continual improvement', 'description' => 'The organization shall continually improve the suitability, adequacy and effectiveness of the ISMS.', 'category' => 'Improvement', 'priority' => 'critical', 'data_source_mapping' => []],
            ['id' => 'ISO27001-10.2', 'title' => 'Nonconformity and corrective action', 'description' => 'When a nonconformity occurs, the organization shall react, evaluate the need for action, implement action, review effectiveness, and retain documented information.', 'category' => 'Improvement', 'priority' => 'critical', 'data_source_mapping' => ['entity' => 'AuditFinding', 'related' => 'CorrectiveAction']],
        ];
    }
}
